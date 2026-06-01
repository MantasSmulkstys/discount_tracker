<?php

declare(strict_types=1);

use App\Models\NormalizedProduct;
use App\Models\ProductFamily;
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

function seedOffer(ProductFamily $family, Store $store, float $price): array
{
    $product = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
        'canonical_name' => 'Pienas 2.5%',
    ]);

    $variantId = DB::table('product_variants')->insertGetId([
        'normalized_product_id' => $product->id,
        'variant_name' => 'default',
        'size_unit' => 'l',
        'size_value' => 1.0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

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

    return ['product' => $product, 'variantId' => $variantId];
}

test('route plan returns alternatives when items are provided', function () {
    fakeMaps();

    $maxima = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);
    $iki = Store::factory()->create(['name' => 'iki', 'slug' => 'iki']);

    $family = ProductFamily::create([
        'family_name' => 'Pienas',
        'normalized_family_name' => 'pienas-'.uniqid(),
    ]);
    seedOffer($family, $maxima, 1.50);
    seedOffer($family, $iki, 1.20);

    $response = $this->postJson('/cart/route-plan', [
        'origin' => ['lat' => 54.69, 'lng' => 25.28],
        'chains' => ['maxima'],
        'mode' => 'driving',
        'items' => [[
            'familyId' => $family->id,
            'normalizedProductId' => null,
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
                '*' => ['label', 'stores', 'totalPriceCents', 'items'],
            ],
        ])
        ->assertJsonPath('alternatives.0.items.0.priceCents', 120);
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
