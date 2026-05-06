<?php

declare(strict_types=1);

use App\Models\NormalizedProduct;
use App\Models\ProductFamily;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

function seedOfferForVariant(
    NormalizedProduct $product,
    Store $store,
    float $price,
    ?string $sizeUnit = null,
    ?float $sizeValue = null,
): int {
    $variant = DB::table('product_variants')->insertGetId([
        'normalized_product_id' => $product->id,
        'variant_name' => 'default',
        'size_unit' => $sizeUnit,
        'size_value' => $sizeValue,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $snapshotId = DB::table('offer_snapshots')->insertGetId([
        'product_variant_id' => $variant,
        'store_id' => $store->id,
        'week_starting' => now()->startOfWeek()->toDateString(),
        'price' => $price,
        'is_promotion' => false,
        'loyalty_card' => false,
        'scraped_at' => now(),
        'created_at' => now(),
    ]);

    DB::table('current_offers')->insert([
        'store_id' => $store->id,
        'product_variant_id' => $variant,
        'price' => $price,
        'is_promotion' => false,
        'loyalty_card' => false,
        'last_seen_at' => now(),
        'week_starting' => now()->startOfWeek()->toDateString(),
        'last_snapshot_id' => $snapshotId,
        'updated_at' => now(),
    ]);

    return $snapshotId;
}

test('product detail surfaces sibling offers from other stores in the same family', function () {
    $family = ProductFamily::create([
        'family_name' => 'Saulegrazu aliejus Natura',
        'normalized_family_name' => 'saulegrazu aliejus natura',
    ]);
    $rimiSku = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
        'canonical_name' => 'Saulėgrąžų aliejus Natura',
    ]);
    $maximaSku = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
        'canonical_name' => 'Saulėgrąžų aliejus Natura',
    ]);
    $rimi = Store::factory()->create(['name' => 'rimi', 'slug' => 'rimi']);
    $maxima = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);

    $rimiSnapshot = seedOfferForVariant($rimiSku, $rimi, 2.49, 'l', 1.0);
    seedOfferForVariant($maximaSku, $maxima, 5.99, 'l', 3.0);

    $this->get("/products/$rimiSnapshot")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('kainu/Products/Show')
            ->has('offers', 2)
            ->where('offers.0.sizeLabel', '1 l')
            ->where('offers.0.isExactMatch', true)
            ->where('offers.0.unitPriceLabel', '2,49 €/l')
            ->has('offers.0.productName')
            ->where('offers.1.sizeLabel', '3 l')
            ->where('offers.1.isExactMatch', false)
            ->where('offers.1.unitPriceLabel', '2,00 €/l')
        );
});

test('price history is scoped to the store the user is viewing', function () {
    $product = NormalizedProduct::factory()->create(['family_id' => null]);
    $rimi = Store::factory()->create(['name' => 'rimi', 'slug' => 'rimi']);
    $maxima = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);

    $variant = DB::table('product_variants')->insertGetId([
        'normalized_product_id' => $product->id,
        'variant_name' => 'default',
        'size_unit' => 'l',
        'size_value' => 1.0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('offer_snapshots')->insert([
        'product_variant_id' => $variant,
        'store_id' => $rimi->id,
        'week_starting' => now()->subDays(7)->startOfWeek()->toDateString(),
        'price' => 2.49,
        'is_promotion' => false,
        'loyalty_card' => false,
        'scraped_at' => now()->subDays(7),
        'created_at' => now()->subDays(7),
    ]);
    $rimiSnap2 = DB::table('offer_snapshots')->insertGetId([
        'product_variant_id' => $variant,
        'store_id' => $rimi->id,
        'week_starting' => now()->startOfWeek()->toDateString(),
        'price' => 2.69,
        'is_promotion' => false,
        'loyalty_card' => false,
        'scraped_at' => now(),
        'created_at' => now(),
    ]);
    DB::table('current_offers')->insert([
        'store_id' => $rimi->id,
        'product_variant_id' => $variant,
        'price' => 2.69,
        'is_promotion' => false,
        'loyalty_card' => false,
        'last_seen_at' => now(),
        'week_starting' => now()->startOfWeek()->toDateString(),
        'last_snapshot_id' => $rimiSnap2,
        'updated_at' => now(),
    ]);

    DB::table('offer_snapshots')->insert([
        'product_variant_id' => $variant,
        'store_id' => $maxima->id,
        'week_starting' => now()->startOfWeek()->toDateString(),
        'price' => 7.99,
        'is_promotion' => false,
        'loyalty_card' => false,
        'scraped_at' => now(),
        'created_at' => now(),
    ]);

    $this->get("/products/$rimiSnap2")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('history', [2.49, 2.69])
        );
});

test('product detail without family_id only shows its own store offers', function () {
    $product = NormalizedProduct::factory()->create(['family_id' => null]);
    $other = NormalizedProduct::factory()->create(['family_id' => null]);
    $rimi = Store::factory()->create(['name' => 'rimi', 'slug' => 'rimi']);
    $maxima = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);

    $snapshot = seedOfferForVariant($product, $rimi, 2.49, 'l', 1.0);
    seedOfferForVariant($other, $maxima, 5.99, 'l', 3.0);

    $this->get("/products/$snapshot")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('offers', 1));
});

test('siblings with different size_unit are excluded from cross-store comparison', function () {
    $family = ProductFamily::create([
        'family_name' => 'Aliejus',
        'normalized_family_name' => 'aliejus',
    ]);
    $litreSku = NormalizedProduct::factory()->create(['family_id' => $family->id]);
    $kgSku = NormalizedProduct::factory()->create(['family_id' => $family->id]);
    $rimi = Store::factory()->create(['name' => 'rimi', 'slug' => 'rimi']);
    $maxima = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);

    $snapshot = seedOfferForVariant($litreSku, $rimi, 2.49, 'l', 1.0);
    seedOfferForVariant($kgSku, $maxima, 4.99, 'kg', 1.0);

    $this->get("/products/$snapshot")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('offers', 1));
});
