<?php

declare(strict_types=1);

use App\Models\NormalizedProduct;
use App\Models\ProductFamily;
use App\Models\Store;
use App\Services\CartOptimizer;
use App\Services\KainuCatalog;
use App\Services\Maps\PlacesApiClient;
use App\Services\Maps\RoutesApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function fakePlaces(): PlacesApiClient
{
    return new class extends PlacesApiClient
    {
        public function __construct()
        {
            parent::__construct('test-key');
        }

        public function findNearestStore(string $chainQuery, array $origin, int $radiusMeters = 15000): ?array
        {
            return [
                'name' => $chainQuery,
                'address' => 'A',
                'city' => 'Vilnius',
                'lat' => 54.69,
                'lng' => 25.28,
            ];
        }
    };
}

function fakeRoutes(int $duration = 600): RoutesApiClient
{
    return new class($duration) extends RoutesApiClient
    {
        public function __construct(private int $dur)
        {
            parent::__construct('test-key');
        }

        public function computeRoute(array $origin, array $destination, array $intermediates, string $mode): array
        {
            $stops = count($intermediates);

            return [
                'order' => array_keys($intermediates),
                'polyline' => 'p',
                'totalDurationSec' => $this->dur * $stops,
                'totalDistanceMeters' => 1000 * $stops,
                'legs' => array_fill(0, $stops + 1, ['durationSec' => $this->dur, 'distanceMeters' => 500]),
            ];
        }
    };
}

/**
 * Seed a fresh offer for the *same* product at a given store. Alternatives may
 * only re-price the exact product across the stores that actually sell it, so
 * cross-store substitution is modelled by one product with offers in several
 * stores — never by sibling products sharing a family.
 */
function seedProductOfferIn(NormalizedProduct $product, Store $store, float $price, float $sizeValue = 1.0): int
{
    // The same product+size sold at several stores shares ONE variant; the
    // stores differ only by their offer_snapshots. Reuse the variant so we
    // don't violate product_variants.identity_key.
    $variantId = DB::table('product_variants')
        ->where('normalized_product_id', $product->id)
        ->value('id');

    if ($variantId === null) {
        $variantId = DB::table('product_variants')->insertGetId([
            'normalized_product_id' => $product->id,
            'variant_name' => 'default',
            'size_unit' => 'l',
            'size_value' => $sizeValue,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('offer_snapshots')->insert([
        'product_variant_id' => $variantId,
        'store_id' => $store->id,
        'week_starting' => now()->startOfWeek()->toDateString(),
        'price' => $price,
        'is_promotion' => false,
        'loyalty_card' => false,
        'scraped_at' => now(),
        'created_at' => now(),
    ]);

    return (int) $variantId;
}

function makeOptimizer(int $routeDuration = 600): CartOptimizer
{
    return new CartOptimizer(
        app(KainuCatalog::class),
        fakeRoutes($routeDuration),
        fakePlaces(),
    );
}

test('cheapest alternative picks the cheapest offer per item across stores', function () {
    Cache::flush();

    $maxima = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);
    $iki = Store::factory()->create(['name' => 'iki', 'slug' => 'iki']);

    $milk = NormalizedProduct::factory()->create(['canonical_name' => 'Pienas 2.5%']);
    seedProductOfferIn($milk, $maxima, 1.50);
    seedProductOfferIn($milk, $iki, 1.20);

    $bread = NormalizedProduct::factory()->create(['canonical_name' => 'Duona']);
    seedProductOfferIn($bread, $maxima, 1.10);
    seedProductOfferIn($bread, $iki, 1.80);

    $result = makeOptimizer()->optimize(
        items: [
            [
                'familyId' => null,
                'normalizedProductId' => $milk->id,
                'variantId' => null,
                'quantity' => 1,
                'storeSlug' => 'maxima',
                'priceCents' => 150,
            ],
            [
                'familyId' => null,
                'normalizedProductId' => $bread->id,
                'variantId' => null,
                'quantity' => 1,
                'storeSlug' => 'maxima',
                'priceCents' => 110,
            ],
        ],
        origin: ['lat' => 54.69, 'lng' => 25.28],
        mode: 'DRIVE',
    );

    $cheapest = collect($result['alternatives'])->firstWhere('label', 'Pigesnis');

    expect($cheapest)->not->toBeNull()
        ->and($cheapest['totalPriceCents'])->toBe(120 + 110);
});

test('cart with no substitutes returns empty alternatives', function () {
    Cache::flush();

    $result = makeOptimizer()->optimize(
        items: [
            [
                'familyId' => null,
                'normalizedProductId' => null,
                'variantId' => null,
                'quantity' => 1,
                'storeSlug' => 'maxima',
                'priceCents' => 100,
            ],
        ],
        origin: ['lat' => 54.69, 'lng' => 25.28],
        mode: 'DRIVE',
    );

    expect($result['alternatives'])->toBe([]);
});

test('pareto filter drops dominated alternatives', function () {
    Cache::flush();

    $maxima = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);
    $iki = Store::factory()->create(['name' => 'iki', 'slug' => 'iki']);

    $milk = NormalizedProduct::factory()->create(['canonical_name' => 'Pienas']);
    seedProductOfferIn($milk, $maxima, 1.00);
    seedProductOfferIn($milk, $iki, 5.00);

    $result = makeOptimizer()->optimize(
        items: [
            [
                'familyId' => null,
                'normalizedProductId' => $milk->id,
                'variantId' => null,
                'quantity' => 1,
                'storeSlug' => 'iki',
                'priceCents' => 500,
            ],
        ],
        origin: ['lat' => 54.69, 'lng' => 25.28],
        mode: 'DRIVE',
    );

    $stores = collect($result['alternatives'])->pluck('stores')->all();
    expect($stores)->not->toBeEmpty();
    foreach ($stores as $set) {
        expect($set)->not->toBe(['iki']);
    }
});

test('suggests a same-size family sibling from another store', function () {
    Cache::flush();

    $norfa = Store::factory()->create(['name' => 'norfa', 'slug' => 'norfa']);
    $rimi = Store::factory()->create(['name' => 'rimi', 'slug' => 'rimi']);

    $family = ProductFamily::create([
        'family_name' => 'Akvilė',
        'normalized_family_name' => 'akvile-'.uniqid(),
    ]);

    // Same product family, same 1,5 l size, but split into two normalized
    // products under slightly different scraped names (the real-world case
    // where the normalizer didn't merge them across stores).
    $mine = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
        'canonical_name' => 'Akvilė negaz. mineralinis vanduo 1,5 l',
    ]);
    $sibling = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
        'canonical_name' => 'Akvilė gaz. mineralinis vanduo 1,5 l',
    ]);

    seedProductOfferIn($mine, $norfa, 0.51, 1.5);    // only at norfa
    seedProductOfferIn($sibling, $rimi, 0.49, 1.5);  // same size, cheaper, elsewhere

    $result = makeOptimizer()->optimize(
        items: [
            [
                'familyId' => $family->id,
                'normalizedProductId' => $mine->id,
                'variantId' => null,
                'quantity' => 1,
                'storeSlug' => 'norfa',
                'priceCents' => 51,
            ],
        ],
        origin: ['lat' => 54.69, 'lng' => 25.28],
        mode: 'DRIVE',
    );

    $cheapest = collect($result['alternatives'])->firstWhere('label', 'Pigesnis');

    expect($cheapest)->not->toBeNull()
        ->and($cheapest['items'])->toHaveCount(1)
        ->and($cheapest['items'][0]['storeSlug'])->toBe('rimi')
        ->and($cheapest['items'][0]['priceCents'])->toBe(49)
        // The substituted item reports the *actual* sibling product, not ours.
        ->and($cheapest['items'][0]['normalizedProductId'])->toBe($sibling->id)
        // ...and is flagged as a real change (store + product both differ).
        ->and($cheapest['items'][0]['changed'])->toBeTrue();
});

test('does not substitute a family sibling of a different size', function () {
    Cache::flush();

    $norfa = Store::factory()->create(['name' => 'norfa', 'slug' => 'norfa']);
    $rimi = Store::factory()->create(['name' => 'rimi', 'slug' => 'rimi']);

    $family = ProductFamily::create([
        'family_name' => 'Akvilė',
        'normalized_family_name' => 'akvile-'.uniqid(),
    ]);

    $mine = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
        'canonical_name' => 'Akvilė 1,5 l',
    ]);
    $bigSibling = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
        'canonical_name' => 'Akvilė 2 l',
    ]);

    seedProductOfferIn($mine, $norfa, 0.51, 1.5);      // only at norfa
    seedProductOfferIn($bigSibling, $rimi, 0.49, 2.0); // different size, cheaper

    $result = makeOptimizer()->optimize(
        items: [
            [
                'familyId' => $family->id,
                'normalizedProductId' => $mine->id,
                'variantId' => null,
                'quantity' => 1,
                'storeSlug' => 'norfa',
                'priceCents' => 51,
            ],
        ],
        origin: ['lat' => 54.69, 'lng' => 25.28],
        mode: 'DRIVE',
    );

    // A 2 l bottle is not a substitute for a 1,5 l one, and the 1,5 l product
    // is sold only at norfa — so there is nothing to suggest.
    expect($result['alternatives'])->toBe([]);
});

test('suggests the same product at a cheaper store without changing the product', function () {
    Cache::flush();

    $maxima = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);
    $iki = Store::factory()->create(['name' => 'iki', 'slug' => 'iki']);

    $milk = NormalizedProduct::factory()->create(['canonical_name' => 'Pienas 2.5%']);
    seedProductOfferIn($milk, $maxima, 2.00);
    seedProductOfferIn($milk, $iki, 1.20);

    $result = makeOptimizer()->optimize(
        items: [
            [
                'familyId' => null,
                'normalizedProductId' => $milk->id,
                'variantId' => null,
                'quantity' => 1,
                'storeSlug' => 'maxima',
                'priceCents' => 200,
            ],
        ],
        origin: ['lat' => 54.69, 'lng' => 25.28],
        mode: 'DRIVE',
    );

    $cheapest = collect($result['alternatives'])->firstWhere('label', 'Pigesnis');

    expect($cheapest)->not->toBeNull()
        ->and($cheapest['items'])->toHaveCount(1)
        ->and($cheapest['items'][0]['normalizedProductId'])->toBe($milk->id)
        ->and($cheapest['items'][0]['storeSlug'])->toBe('iki')
        ->and($cheapest['items'][0]['priceCents'])->toBe(120);
});
