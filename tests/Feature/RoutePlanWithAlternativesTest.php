<?php

declare(strict_types=1);

use App\Models\NormalizedProduct;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.google_maps.key', 'test-key');
    config()->set('services.google_maps.server_key', null);

    Cache::flush();
    $this->actingAs(User::factory()->create());
});

function fakeMaps(): void
{
    Http::fake([
        'places.googleapis.com/*' => Http::response([
            'places' => [[
                'displayName' => ['text' => 'Maxima A'],
                'shortFormattedAddress' => 'A 1',
                'formattedAddress' => 'A 1',
                'addressComponents' => [
                    ['types' => ['locality'], 'shortText' => 'Vilnius', 'longText' => 'Vilnius'],
                ],
                'location' => ['latitude' => 54.69, 'longitude' => 25.28],
            ]],
        ]),
        'routes.googleapis.com/*' => Http::response([
            'routes' => [[
                'optimizedIntermediateWaypointIndex' => [0],
                'duration' => '600s',
                'distanceMeters' => 1000,
                'polyline' => ['encodedPolyline' => 'p'],
                'legs' => [
                    ['duration' => '300s', 'distanceMeters' => 500],
                    ['duration' => '300s', 'distanceMeters' => 500],
                ],
            ]],
        ]),
    ]);
}

function seedOfferForProduct(NormalizedProduct $product, Store $store, float $price): int
{
    // One shared variant per product; stores differ only by offer_snapshots.
    $variantId = DB::table('product_variants')
        ->where('normalized_product_id', $product->id)
        ->value('id');

    if ($variantId === null) {
        $variantId = DB::table('product_variants')->insertGetId([
            'normalized_product_id' => $product->id,
            'variant_name' => 'default',
            'size_unit' => 'l',
            'size_value' => 1.0,
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

test('route plan returns alternatives when items are provided', function () {
    fakeMaps();

    $maxima = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);
    $iki = Store::factory()->create(['name' => 'iki', 'slug' => 'iki']);

    $milk = NormalizedProduct::factory()->create(['canonical_name' => 'Pienas 2.5%']);
    seedOfferForProduct($milk, $maxima, 1.50);
    $ikiVariantId = seedOfferForProduct($milk, $iki, 1.20);

    // The snapshot id the cheaper iki offer must report — it drives the
    // /products/{id} detail link, which resolves by offer_snapshots.id (NOT
    // normalized_product_id), so the wrong id silently links to another product.
    $ikiSnapshotId = (int) DB::table('offer_snapshots')
        ->where('product_variant_id', $ikiVariantId)
        ->where('store_id', $iki->id)
        ->value('id');

    $response = $this->postJson('/cart/route-plan', [
        'origin' => ['lat' => 54.69, 'lng' => 25.28],
        'chains' => ['maxima'],
        'mode' => 'driving',
        'items' => [[
            'familyId' => null,
            'normalizedProductId' => $milk->id,
            'variantId' => null,
            'quantity' => 1,
            'storeSlug' => 'maxima',
            'priceCents' => 150,
        ]],
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'mode',
            'polyline',
            'totalDurationSec',
            'alternatives' => [
                '*' => ['label', 'stores', 'totalPriceCents', 'items' => [
                    '*' => ['normalizedProductId', 'snapshotId', 'storeSlug', 'priceCents', 'name', 'image', 'fixed', 'changed'],
                ]],
            ],
        ])
        ->assertJsonPath('alternatives.0.items.0.priceCents', 120)
        ->assertJsonPath('alternatives.0.items.0.snapshotId', $ikiSnapshotId)
        ->assertJsonPath('alternatives.0.items.0.normalizedProductId', $milk->id)
        // maxima → iki is a real change.
        ->assertJsonPath('alternatives.0.items.0.changed', true);
});

test('route plan returns empty alternatives when no items are provided', function () {
    fakeMaps();

    Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);

    $this->postJson('/cart/route-plan', [
        'origin' => ['lat' => 54.69, 'lng' => 25.28],
        'chains' => ['maxima'],
        'mode' => 'driving',
    ])
        ->assertOk()
        ->assertJsonPath('alternatives', []);
});

test('route plan with items but no substitutes returns empty alternatives', function () {
    fakeMaps();

    Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);

    $this->postJson('/cart/route-plan', [
        'origin' => ['lat' => 54.69, 'lng' => 25.28],
        'chains' => ['maxima'],
        'mode' => 'driving',
        'items' => [[
            'familyId' => null,
            'normalizedProductId' => null,
            'variantId' => null,
            'quantity' => 1,
            'storeSlug' => 'maxima',
            'priceCents' => 200,
        ]],
    ])
        ->assertOk()
        ->assertJsonPath('alternatives', []);
});
