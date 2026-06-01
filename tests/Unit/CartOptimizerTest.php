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

function seedFamilyOfferIn(ProductFamily $family, Store $store, float $price, string $name = 'Pienas'): int
{
    $product = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
        'canonical_name' => $name.' '.$store->slug,
        'normalized_name' => mb_strtolower($name.' '.$store->slug),
    ]);

    $variantId = DB::table('product_variants')->insertGetId([
        'normalized_product_id' => $product->id,
        'variant_name' => 'default',
        'size_unit' => 'l',
        'size_value' => 1.0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('offer_snapshots')->insertGetId([
        'product_variant_id' => $variantId,
        'store_id' => $store->id,
        'week_starting' => now()->startOfWeek()->toDateString(),
        'price' => $price,
        'is_promotion' => false,
        'loyalty_card' => false,
        'scraped_at' => now(),
        'created_at' => now(),
    ]);

    return $variantId;
}

test('cheapest alternative picks the cheapest offer per item across stores', function () {
    Cache::flush();

    $maxima = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);
    $iki = Store::factory()->create(['name' => 'iki', 'slug' => 'iki']);

    $familyMilk = ProductFamily::create([
        'family_name' => 'Pienas',
        'normalized_family_name' => 'pienas-'.uniqid(),
    ]);
    seedFamilyOfferIn($familyMilk, $maxima, 1.50, 'Pienas');
    seedFamilyOfferIn($familyMilk, $iki, 1.20, 'Pienas');

    $familyBread = ProductFamily::create([
        'family_name' => 'Duona',
        'normalized_family_name' => 'duona-'.uniqid(),
    ]);
    seedFamilyOfferIn($familyBread, $maxima, 1.10, 'Duona');
    seedFamilyOfferIn($familyBread, $iki, 1.80, 'Duona');

    $optimizer = new CartOptimizer(
        app(KainuCatalog::class),
        fakeRoutes(),
        fakePlaces(),
    );

    $result = $optimizer->optimize(
        items: [
            [
                'familyId' => $familyMilk->id,
                'normalizedProductId' => null,
                'variantId' => null,
                'quantity' => 1,
                'storeSlug' => 'maxima',
                'priceCents' => 150,
            ],
            [
                'familyId' => $familyBread->id,
                'normalizedProductId' => null,
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

    $optimizer = new CartOptimizer(
        app(KainuCatalog::class),
        fakeRoutes(),
        fakePlaces(),
    );

    $result = $optimizer->optimize(
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

    $family = ProductFamily::create([
        'family_name' => 'Pienas',
        'normalized_family_name' => 'pienas-'.uniqid(),
    ]);

    seedFamilyOfferIn($family, $maxima, 1.00, 'Pienas');
    seedFamilyOfferIn($family, $iki, 5.00, 'Pienas');

    $optimizer = new CartOptimizer(
        app(KainuCatalog::class),
        fakeRoutes(600),
        fakePlaces(),
    );

    $result = $optimizer->optimize(
        items: [
            [
                'familyId' => $family->id,
                'normalizedProductId' => null,
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
