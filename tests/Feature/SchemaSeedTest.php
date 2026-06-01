<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Store;
use App\Models\Subcategory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--force' => true]);
});

it('creates every catalog and offer table', function (): void {
    $expected = [
        'stores',
        'categories',
        'subcategories',
        'product_families',
        'normalized_products',
        'product_variants',
        'scrape_batches',
        'raw_offers',
        'offer_snapshots',
        'current_offers',
        'price_changes',
    ];

    foreach ($expected as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
    }
});

it('seeds the five Lithuanian grocery stores', function (): void {
    $slugs = Store::query()->orderBy('slug')->pluck('slug')->all();

    expect($slugs)->toEqual(['iki', 'lidl', 'maxima', 'norfa', 'rimi']);
});

it('seeds the category taxonomy with subcategories', function (): void {
    expect(Category::query()->count())->toBeGreaterThan(0)
        ->and(Subcategory::query()->count())->toBeGreaterThan(Category::query()->count());
});

it('exposes identity_key columns on normalized_products and product_variants', function (): void {
    expect(Schema::hasColumn('normalized_products', 'identity_key'))->toBeTrue()
        ->and(Schema::hasColumn('product_variants', 'identity_key'))->toBeTrue();
});

it('uses composite primary key for current_offers', function (): void {
    expect(Schema::hasColumn('current_offers', 'store_id'))->toBeTrue()
        ->and(Schema::hasColumn('current_offers', 'product_variant_id'))->toBeTrue()
        ->and(Schema::hasColumn('current_offers', 'last_snapshot_id'))->toBeTrue();
});
