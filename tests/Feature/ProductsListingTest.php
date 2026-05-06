<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array{store_name?: string, raw_external_id?: string, product_name?: string, normalized_name?: string, brand?: string|null, price?: float|null, old_price?: float|null, discount_percent?: float|null, scraped_at?: Carbon, image_url?: string|null, raw_url?: string|null, valid_from?: string|null, valid_to?: string|null, unit_value?: float|null, unit_unit?: string|null, loyalty?: bool}  $attributes
 */
function seedSnapshot(array $attributes = []): int
{
    $storeName = $attributes['store_name'] ?? 'testmart';
    $rawExternalId = $attributes['raw_external_id'] ?? 'raw-'.uniqid();
    $productName = $attributes['product_name'] ?? 'Šviežias pienas 1 L';
    $normalizedName = $attributes['normalized_name'] ?? 'šviežias pienas 1l';
    $brand = $attributes['brand'] ?? 'Rokiškio';
    $price = $attributes['price'] ?? 1.49;
    $oldPrice = $attributes['old_price'] ?? 2.09;
    $discountPercent = $attributes['discount_percent'] ?? 29;
    $scrapedAt = $attributes['scraped_at'] ?? Carbon::now();
    $imageUrl = $attributes['image_url'] ?? 'https://example.com/pienas.png';
    $rawUrl = $attributes['raw_url'] ?? 'https://example.com/offer';
    $validFrom = $attributes['valid_from'] ?? null;
    $validTo = $attributes['valid_to'] ?? null;
    $unitValue = $attributes['unit_value'] ?? 1.49;
    $unitUnit = $attributes['unit_unit'] ?? 'l';
    $loyalty = $attributes['loyalty'] ?? false;

    $storeId = DB::table('stores')->where('name', $storeName)->value('id');
    if ($storeId === null) {
        $storeId = DB::table('stores')->insertGetId([
            'name' => $storeName,
            'slug' => Str::slug($storeName),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $rawOfferId = DB::table('raw_offers')->insertGetId([
        'store_id' => $storeId,
        'content_hash' => $rawExternalId,
        'name' => $productName,
        'price' => $price,
        'old_price' => $oldPrice,
        'discount' => $discountPercent,
        'unit_price' => $unitValue !== null ? sprintf('%.2f €/%s', $unitValue, $unitUnit) : null,
        'image' => $imageUrl,
        'url' => $rawUrl,
        'valid_dates' => null,
        'loyalty_card' => $loyalty,
        'scraped_at' => $scrapedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $normalizedProductId = DB::table('normalized_products')
        ->where('normalized_name', $normalizedName)
        ->where('brand', $brand)
        ->value('id');

    if ($normalizedProductId === null) {
        $normalizedProductId = DB::table('normalized_products')->insertGetId([
            'canonical_name' => $productName,
            'normalized_name' => $normalizedName,
            'brand' => $brand,
            'generic_name' => null,
            'category_id' => null,
            'subcategory_id' => null,
            'family_id' => null,
            'ai_confidence' => null,
            'needs_review' => false,
            'normalization_version' => 'test',
            'source_name' => $productName,
            'image_url' => $imageUrl,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $productVariantId = DB::table('product_variants')
        ->where('normalized_product_id', $normalizedProductId)
        ->value('id');

    if ($productVariantId === null) {
        $productVariantId = DB::table('product_variants')->insertGetId([
            'normalized_product_id' => $normalizedProductId,
            'variant_name' => '1 l',
            'size_value' => 1,
            'size_unit' => 'l',
            'package_count' => null,
            'package_unit' => null,
            'net_quantity_value' => 1,
            'net_quantity_unit' => 'l',
            'attributes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $weekStarting = Carbon::parse($scrapedAt)->startOfWeek()->toDateString();

    return (int) DB::table('offer_snapshots')->insertGetId([
        'raw_offer_id' => $rawOfferId,
        'product_variant_id' => $productVariantId,
        'store_id' => $storeId,
        'week_starting' => $weekStarting,
        'price' => $price,
        'old_price' => $oldPrice,
        'discount_percent' => $discountPercent,
        'loyalty_card' => $loyalty,
        'unit_price_value' => $unitValue,
        'unit_price_unit' => $unitUnit,
        'valid_from' => $validFrom,
        'valid_to' => $validTo,
        'scraped_at' => $scrapedAt,
        'is_promotion' => $oldPrice !== null,
        'created_at' => now(),
    ]);
}

test('public product listing reads from the normalized snapshot schema', function () {
    $snapshotId = seedSnapshot([
        'store_name' => 'testmart',
        'product_name' => 'Šviežias pienas 1 L',
        'normalized_name' => 'šviežias pienas 1l',
        'brand' => 'Rokiškio',
        'price' => 1.49,
        'old_price' => 2.09,
        'discount_percent' => 29,
    ]);

    $response = $this->get(route('products.index'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('kainu/Products/Index')
            ->has('products.data', 1)
            ->where('products.data.0.id', $snapshotId)
            ->where('products.data.0.name', 'Šviežias pienas 1 L')
            ->where('products.data.0.brand', 'Rokiškio')
            ->where('products.data.0.bestPrice', 1.49)
            ->where('products.data.0.oldPrice', 2.09)
            ->where('products.data.0.bestStoreName', 'Testmart')
        );
});

test('listing deduplicates older snapshots for the same product + store pair', function () {
    seedSnapshot([
        'store_name' => 'testmart',
        'raw_external_id' => 'older',
        'product_name' => 'Šviežias pienas 1 L',
        'normalized_name' => 'šviežias pienas 1l',
        'price' => 2.00,
        'scraped_at' => Carbon::now()->subWeek(),
    ]);

    $latestId = seedSnapshot([
        'store_name' => 'testmart',
        'raw_external_id' => 'latest',
        'product_name' => 'Šviežias pienas 1 L',
        'normalized_name' => 'šviežias pienas 1l',
        'price' => 1.49,
        'scraped_at' => Carbon::now(),
    ]);

    $response = $this->get(route('products.index'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('kainu/Products/Index')
            ->has('products.data', 1)
            ->where('products.data.0.id', $latestId)
            ->where('products.data.0.bestPrice', 1.49)
            ->where('products.meta.total', 1)
        );
});

test('listing keeps one row per store when the same product is sold by multiple stores', function () {
    seedSnapshot([
        'store_name' => 'maxima',
        'raw_external_id' => 'maxima-offer',
        'product_name' => 'Šviežias pienas 1 L',
        'normalized_name' => 'šviežias pienas 1l',
        'price' => 1.59,
    ]);

    seedSnapshot([
        'store_name' => 'rimi',
        'raw_external_id' => 'rimi-offer',
        'product_name' => 'Šviežias pienas 1 L',
        'normalized_name' => 'šviežias pienas 1l',
        'price' => 1.49,
    ]);

    $response = $this->get(route('products.index'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('kainu/Products/Index')
            ->has('products.data', 2)
            ->where('products.meta.total', 2)
        );
});

test('search filter matches the normalized product name', function () {
    seedSnapshot([
        'product_name' => 'Rokiškio pienas 1 L',
        'normalized_name' => 'rokiškio pienas 1l',
        'brand' => 'Rokiškio',
    ]);

    seedSnapshot([
        'raw_external_id' => 'other',
        'product_name' => 'Švelnios vaflės',
        'normalized_name' => 'švelnios vaflės',
        'brand' => null,
    ]);

    $response = $this->get(route('products.index', ['q' => 'pienas']));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('kainu/Products/Index')
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Rokiškio pienas 1 L')
            ->where('filters.q', 'pienas')
        );
});

test('store filter scopes the listing to the selected chains', function () {
    seedSnapshot([
        'store_name' => 'maxima',
        'raw_external_id' => 'maxima-1',
        'product_name' => 'Pienas 1',
        'normalized_name' => 'pienas 1',
    ]);

    seedSnapshot([
        'store_name' => 'rimi',
        'raw_external_id' => 'rimi-1',
        'product_name' => 'Pienas 2',
        'normalized_name' => 'pienas 2',
    ]);

    $response = $this->get(route('products.index', ['stores' => 'rimi']));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('kainu/Products/Index')
            ->has('products.data', 1)
            ->where('products.data.0.bestStoreName', 'Rimi')
        );
});

test('product detail page shows the latest offer for the normalized product across stores', function () {
    $maximaId = seedSnapshot([
        'store_name' => 'maxima',
        'raw_external_id' => 'maxima-1',
        'product_name' => 'Šviežias pienas 1 L',
        'normalized_name' => 'šviežias pienas 1l',
        'price' => 1.79,
    ]);

    seedSnapshot([
        'store_name' => 'rimi',
        'raw_external_id' => 'rimi-1',
        'product_name' => 'Šviežias pienas 1 L',
        'normalized_name' => 'šviežias pienas 1l',
        'price' => 1.49,
    ]);

    $response = $this->get(route('products.show', ['id' => $maximaId]));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('kainu/Products/Show')
            ->has('offers', 2)
            ->where('product.name', 'Šviežias pienas 1 L')
            ->where('offers.0.price', 1.49)
            ->where('offers.0.storeName', 'Rimi')
        );
});

test('product detail page 404s for an unknown snapshot', function () {
    $this->get(route('products.show', ['id' => 99999]))->assertNotFound();
});
