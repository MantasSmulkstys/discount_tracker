<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function vsStore(string $slug): int
{
    return DB::table('stores')->insertGetId([
        'name' => $slug,
        'slug' => $slug,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function vsVariant(int $npId, ?string $code): int
{
    return DB::table('product_variants')->insertGetId([
        'normalized_product_id' => $npId,
        'variant_name' => '1 vnt.',
        'package_count' => 1,
        'package_unit' => 'vnt',
        'variant_code' => $code,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function vsRawOffer(int $storeId, string $name): int
{
    return DB::table('raw_offers')->insertGetId([
        'store_id' => $storeId,
        'name' => $name,
        'normalization_status' => 'done',
        'normalized_at' => now(),
        'scraped_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function vsSnapshot(int $variantId, int $storeId, int $rawOfferId, string $week, float $price): int
{
    return DB::table('offer_snapshots')->insertGetId([
        'product_variant_id' => $variantId,
        'store_id' => $storeId,
        'raw_offer_id' => $rawOfferId,
        'week_starting' => $week,
        'price' => $price,
        'is_promotion' => false,
        'loyalty_card' => false,
        'scraped_at' => now(),
        'created_at' => now(),
    ]);
}

function vsCurrentOffer(int $storeId, int $variantId, int $snapshotId, float $price): void
{
    DB::table('current_offers')->insert([
        'store_id' => $storeId,
        'product_variant_id' => $variantId,
        'price' => $price,
        'is_promotion' => false,
        'loyalty_card' => false,
        'last_seen_at' => now(),
        'week_starting' => '2026-06-01',
        'last_snapshot_id' => $snapshotId,
        'updated_at' => now(),
    ]);
}

function vsNp(string $name): int
{
    return DB::table('normalized_products')->insertGetId([
        'canonical_name' => $name,
        'normalized_name' => mb_strtolower($name),
        'needs_review' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('merges the stale NULL-code variant into its coded twin', function () {
    $store = vsStore('norfa');
    $np = vsNp('Sulankstomas karutis');

    $oldVariant = vsVariant($np, null);          // pre-fix, NULL code
    $newVariant = vsVariant($np, 'art:HKW-003'); // post-backfill, coded

    // One raw_offer that ended up on BOTH variants (same store + week).
    $raw = vsRawOffer($store, 'Sulankstomas karutis, art. HKW-003, 1 vnt.');
    $oldSnap = vsSnapshot($oldVariant, $store, $raw, '2026-06-01', 19.99);
    $newSnap = vsSnapshot($newVariant, $store, $raw, '2026-06-01', 19.99);
    vsCurrentOffer($store, $oldVariant, $oldSnap, 19.99);
    vsCurrentOffer($store, $newVariant, $newSnap, 19.99);

    Artisan::call('products:dedupe-variants');

    // The stale variant and its duplicate listing are gone; the coded one stays.
    expect(DB::table('product_variants')->where('id', $oldVariant)->exists())->toBeFalse();
    expect(DB::table('product_variants')->where('id', $newVariant)->exists())->toBeTrue();
    expect(DB::table('current_offers')->where('product_variant_id', $oldVariant)->count())->toBe(0);
    expect(DB::table('current_offers')->where('product_variant_id', $newVariant)->count())->toBe(1);
    // The same-week clash means the old snapshot is dropped, leaving one.
    expect(DB::table('offer_snapshots')->where('raw_offer_id', $raw)->count())->toBe(1);
});

test('leaves genuinely distinct variants (different raw offers) untouched', function () {
    $store = vsStore('norfa');
    $np = vsNp('Elektrinis virdulys ONEX');

    $a = vsVariant($np, 'art:KT-S08');
    $b = vsVariant($np, 'art:PK-3058B');

    // Two different physical listings — each with its OWN single snapshot.
    $rawA = vsRawOffer($store, 'Elektrinis virdulys ONEX, art. KT-S08, 1 vnt.');
    $rawB = vsRawOffer($store, 'Elektrinis virdulys ONEX, art. PK-3058B, 1 vnt.');
    vsSnapshot($a, $store, $rawA, '2026-06-01', 14.99);
    vsSnapshot($b, $store, $rawB, '2026-06-01', 10.99);

    Artisan::call('products:dedupe-variants');

    expect(DB::table('product_variants')->where('id', $a)->exists())->toBeTrue();
    expect(DB::table('product_variants')->where('id', $b)->exists())->toBeTrue();
});
