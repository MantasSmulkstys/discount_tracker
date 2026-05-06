<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

function seedBaseSnapshot(string $storeName, string $productName, float $price): array
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

    DB::table('offer_snapshots')->insert([
        'product_variant_id' => $variantId,
        'store_id' => $storeId,
        'week_starting' => now()->startOfWeek()->toDateString(),
        'price' => $price,
        'scraped_at' => now(),
        'created_at' => now(),
    ]);

    return ['variantId' => $variantId, 'storeId' => $storeId];
}

it('inserts a follow-up snapshot and price_change row for each demo product', function (): void {
    seedBaseSnapshot('aibe', 'Pienas', 1.50);
    seedBaseSnapshot('rimi', 'Duona', 2.00);
    seedBaseSnapshot('iki', 'Sviestas', 3.20);

    $this->artisan('kainu:seed-demo-price-changes', ['--count' => 3])
        ->assertSuccessful();

    expect(DB::table('price_changes')->count())->toBe(3)
        ->and(DB::table('offer_snapshots')->count())->toBe(6);

    $batchId = DB::table('scrape_batches')->where('source_name', 'demo:price-changes')->value('id');
    expect($batchId)->not->toBeNull();

    $demoSnapshots = DB::table('offer_snapshots')
        ->where('scrape_batch_id', $batchId)
        ->get();

    expect($demoSnapshots)->toHaveCount(3);

    foreach ($demoSnapshots as $snapshot) {
        expect((float) $snapshot->price)->toBeGreaterThan(0);
    }
});

it('is idempotent: re-running without --fresh does not duplicate', function (): void {
    seedBaseSnapshot('aibe', 'Pienas', 1.50);

    $this->artisan('kainu:seed-demo-price-changes', ['--count' => 1])->assertSuccessful();
    $this->artisan('kainu:seed-demo-price-changes', ['--count' => 1])->assertSuccessful();

    expect(DB::table('price_changes')->count())->toBe(1);
});

it('--fresh removes prior demo data and re-seeds', function (): void {
    seedBaseSnapshot('aibe', 'Pienas', 1.50);

    $this->artisan('kainu:seed-demo-price-changes', ['--count' => 1])->assertSuccessful();
    expect(DB::table('price_changes')->count())->toBe(1);

    $this->artisan('kainu:seed-demo-price-changes', ['--count' => 1, '--fresh' => true])
        ->assertSuccessful();

    expect(DB::table('price_changes')->count())->toBe(1)
        ->and(DB::table('scrape_batches')->where('source_name', 'demo:price-changes')->count())->toBe(1);
});

it('exits with failure when there are no base snapshots', function (): void {
    $this->artisan('kainu:seed-demo-price-changes')->assertFailed();
});
