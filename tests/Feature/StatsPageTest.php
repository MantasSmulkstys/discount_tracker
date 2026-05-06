<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function seedStoreVariantSnapshot(string $storeName, string $productName, float $price, ?float $changePct = null): array
{
    $storeId = DB::table('stores')->insertGetId([
        'name' => $storeName,
        'slug' => $storeName,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = DB::table('normalized_products')->insertGetId([
        'canonical_name' => $productName,
        'normalized_name' => mb_strtolower($productName),
        'brand' => null,
        'needs_review' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $variantId = DB::table('product_variants')->insertGetId([
        'normalized_product_id' => $productId,
        'variant_name' => '1 vnt',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $weekStarting = now()->startOfWeek()->toDateString();

    $snapshotId = DB::table('offer_snapshots')->insertGetId([
        'product_variant_id' => $variantId,
        'store_id' => $storeId,
        'week_starting' => $weekStarting,
        'price' => $price,
        'scraped_at' => now(),
        'created_at' => now(),
    ]);

    if ($changePct !== null) {
        DB::table('price_changes')->insert([
            'product_variant_id' => $variantId,
            'store_id' => $storeId,
            'previous_price' => round($price / (1 + $changePct / 100), 2),
            'new_price' => $price,
            'change_pct' => $changePct,
            'week_starting' => $weekStarting,
            'changed_at' => now(),
            'snapshot_id' => $snapshotId,
        ]);
    }

    return ['variantId' => $variantId, 'storeId' => $storeId, 'snapshotId' => $snapshotId];
}

it('renders empty-state copy when price_changes is empty', function (): void {
    $response = $this->get(route('stats.index'));

    $response->assertOk()->assertInertia(
        fn (Assert $page) => $page
            ->component('kainu/Statistics')
            ->has('biggestChanges', 0)
            ->where('summary.totalChanges', 0)
            ->where('summary.totalDrops', 0)
            ->where('summary.totalRaises', 0)
            ->where('summary.totalStores', 0)
    );
});

it('returns biggest changes sorted by absolute change_pct desc', function (): void {
    seedStoreVariantSnapshot('aibe', 'Pienas 1l', 1.20, -25.0);
    seedStoreVariantSnapshot('rimi', 'Sviestas 200g', 2.50, -40.0);
    seedStoreVariantSnapshot('iki', 'Duona', 1.00, -10.0);

    $response = $this->get(route('stats.index'));

    $response->assertOk()->assertInertia(
        fn (Assert $page) => $page
            ->component('kainu/Statistics')
            ->has('biggestChanges', 3)
            ->where('biggestChanges.0.changePct', fn ($v) => (float) $v === -40.0)
            ->where('biggestChanges.0.productName', 'Sviestas 200g')
            ->where('biggestChanges.2.changePct', fn ($v) => (float) $v === -10.0)
            ->where('summary.totalChanges', 3)
            ->where('summary.totalDrops', 3)
            ->where('summary.totalRaises', 0)
    );
});

it('includes price raises alongside drops, ranked by absolute change', function (): void {
    seedStoreVariantSnapshot('aibe', 'Drop', 1.00, -15.0);
    seedStoreVariantSnapshot('rimi', 'Rise', 2.00, 30.0);
    seedStoreVariantSnapshot('iki', 'SmallRise', 1.50, 5.0);

    $response = $this->get(route('stats.index'));

    $response->assertOk()->assertInertia(
        fn (Assert $page) => $page
            ->where('summary.totalChanges', 3)
            ->where('summary.totalDrops', 1)
            ->where('summary.totalRaises', 2)
            ->has('biggestChanges', 3)
            ->where('biggestChanges.0.changePct', fn ($v) => (float) $v === 30.0)
            ->where('biggestChanges.0.productName', 'Rise')
            ->where('biggestChanges.1.changePct', fn ($v) => (float) $v === -15.0)
            ->where('biggestChanges.2.changePct', fn ($v) => (float) $v === 5.0)
    );
});
