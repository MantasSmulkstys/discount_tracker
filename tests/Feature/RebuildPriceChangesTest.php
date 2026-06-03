<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

function rebuildSeedStore(string $slug = 'maxima'): int
{
    return DB::table('stores')->insertGetId([
        'name' => $slug,
        'slug' => $slug,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function rebuildSeedVariant(string $name = '1 l'): int
{
    $suffix = uniqid();

    $productId = DB::table('normalized_products')->insertGetId([
        'canonical_name' => "Pienas 2,5% {$suffix}",
        'normalized_name' => "pienas 2,5% {$suffix}",
        'needs_review' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('product_variants')->insertGetId([
        'normalized_product_id' => $productId,
        'variant_name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function rebuildSeedSnapshot(int $storeId, int $variantId, string $week, float $price, string $rawName): int
{
    $rawOfferId = DB::table('raw_offers')->insertGetId([
        'store_id' => $storeId,
        'name' => $rawName,
        'loyalty_card' => false,
        'scraped_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('offer_snapshots')->insertGetId([
        'product_variant_id' => $variantId,
        'store_id' => $storeId,
        'raw_offer_id' => $rawOfferId,
        'week_starting' => $week,
        'price' => $price,
        'scraped_at' => now(),
        'created_at' => now(),
    ]);
}

test('rebuild matches by raw name and records the actual price change', function () {
    $storeId = rebuildSeedStore();
    $variantId = rebuildSeedVariant();

    rebuildSeedSnapshot($storeId, $variantId, '2026-05-04', 2.00, 'Pienas Rokiškio 2,5% 1 L');
    $week2 = rebuildSeedSnapshot($storeId, $variantId, '2026-05-11', 2.50, 'Pienas Rokiškio 2,5% 1 L');

    // A stale row that must be wiped by the rebuild.
    DB::table('price_changes')->insert([
        'product_variant_id' => $variantId,
        'store_id' => $storeId,
        'previous_price' => 9.99,
        'new_price' => 1.11,
        'change_pct' => -88.89,
        'week_starting' => '2026-05-11',
        'changed_at' => now(),
        'snapshot_id' => null,
    ]);

    $this->artisan('kainu:rebuild-price-changes')->assertSuccessful();

    $changes = DB::table('price_changes')->get();
    expect($changes)->toHaveCount(1);

    $change = $changes->first();
    expect((int) $change->snapshot_id)->toBe($week2);
    expect((float) $change->previous_price)->toBe(2.00);
    expect((float) $change->new_price)->toBe(2.50);
    expect((float) $change->change_pct)->toBe(25.0);
});

test('rebuild still matches when the variant drifts but the raw name is identical', function () {
    $storeId = rebuildSeedStore();
    $variantA = rebuildSeedVariant('1 l');
    $variantB = rebuildSeedVariant('1 l'); // AI re-grouped the same card under a new variant

    rebuildSeedSnapshot($storeId, $variantA, '2026-05-04', 2.00, 'Sviestas 82% 200 g');
    $week2 = rebuildSeedSnapshot($storeId, $variantB, '2026-05-11', 1.50, 'Sviestas 82% 200 g');

    $this->artisan('kainu:rebuild-price-changes')->assertSuccessful();

    $change = DB::table('price_changes')->where('snapshot_id', $week2)->first();
    expect($change)->not->toBeNull();
    expect((float) $change->change_pct)->toBe(-25.0);
});

test('rebuild does not compare across different raw names', function () {
    $storeId = rebuildSeedStore();
    $variantId = rebuildSeedVariant();

    rebuildSeedSnapshot($storeId, $variantId, '2026-05-04', 2.00, 'Pienas A');
    rebuildSeedSnapshot($storeId, $variantId, '2026-05-11', 5.00, 'Pienas B');

    $this->artisan('kainu:rebuild-price-changes')->assertSuccessful();

    expect(DB::table('price_changes')->count())->toBe(0);
});

test('rebuild ignores a series with no price movement', function () {
    $storeId = rebuildSeedStore();
    $variantId = rebuildSeedVariant();

    rebuildSeedSnapshot($storeId, $variantId, '2026-05-04', 3.00, 'Duona');
    rebuildSeedSnapshot($storeId, $variantId, '2026-05-11', 3.00, 'Duona');

    $this->artisan('kainu:rebuild-price-changes')->assertSuccessful();

    expect(DB::table('price_changes')->count())->toBe(0);
});
