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
    array $variantOverrides = [],
): int {
    $variant = DB::table('product_variants')->insertGetId([
        'normalized_product_id' => $product->id,
        'variant_name' => 'default',
        'size_unit' => $sizeUnit,
        'size_value' => $sizeValue,
        'created_at' => now(),
        'updated_at' => now(),
        ...$variantOverrides,
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

function seedSubcategory(string $slug): int
{
    $categoryId = DB::table('categories')->insertGetId([
        'name_lt' => 'Kategorija '.$slug,
        'slug' => 'kategorija-'.$slug,
    ]);

    return (int) DB::table('subcategories')->insertGetId([
        'category_id' => $categoryId,
        'name_lt' => 'Pakategorė '.$slug,
        'slug' => $slug,
    ]);
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

test('the Kiekis label prefers net quantity, then piece count, then size — never the unit price', function (
    array $variantOverrides,
    ?float $sizeValue,
    ?string $sizeUnit,
    ?string $expectedSizeLabel,
) {
    $product = NormalizedProduct::factory()->create(['family_id' => null]);
    $store = Store::factory()->create(['name' => 'aibe', 'slug' => 'aibe']);

    $snapshot = seedOfferForVariant($product, $store, 1.79, $sizeUnit, $sizeValue, $variantOverrides);

    $this->get("/products/$snapshot")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('kainu/Products/Show')
            ->where('offers.0.sizeLabel', $expectedSizeLabel)
        );
})->with([
    // Pieces-only pack (toilet paper, sponges): show the count, not "0,3 €/vnt".
    'piece count when there is no size' => [
        ['package_count' => 8, 'package_unit' => 'vnt'],
        null, null, '8 vnt',
    ],
    // Multipack with a net total (4×100 g): show the summed weight, not "100 g".
    'net quantity wins over a sub-unit size' => [
        ['package_count' => 4, 'package_unit' => 'vnt', 'net_quantity_value' => 400, 'net_quantity_unit' => 'g'],
        100.0, 'g', '400 g',
    ],
    // Bin bags "60 l, 20 vnt." with no net total: the count is the quantity.
    'piece count wins over a per-item volume' => [
        ['package_count' => 20, 'package_unit' => 'vnt'],
        60.0, 'l', '20 vnt',
    ],
    // A plain single-size product is unchanged.
    'plain size when there is no pack' => [
        [],
        1.0, 'l', '1 l',
    ],
]);

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
            // Each chart point carries the listing behind that week's price.
            ->where('historyPoints.0.price', 2.49)
            ->where('historyPoints.0.store', 'rimi')
            ->where('historyPoints.1.price', 2.69)
            ->where('historyPoints.1.storeName', 'Rimi')
            ->where('historyPoints.1.sizeLabel', '1 l')
            ->count('historyPoints', 2)
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

test('siblings in a different measurement dimension are excluded from cross-store comparison', function () {
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

test('a family member with no parsed size is still shown in the comparison', function () {
    $family = ProductFamily::create([
        'family_name' => 'sviestas',
        'normalized_family_name' => 'sviestas',
    ]);
    $sized = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
        'generic_name' => 'sviestas',
        'canonical_name' => 'Sviestas 82%',
    ]);
    $sizeless = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
        'generic_name' => 'sviestas',
        'canonical_name' => 'Fermentinis sviestas',
    ]);
    $rimi = Store::factory()->create(['name' => 'rimi', 'slug' => 'rimi']);
    $maxima = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);

    $snapshot = seedOfferForVariant($sized, $rimi, 2.49, 'g', 200.0);
    seedOfferForVariant($sizeless, $maxima, 2.79, null, null);

    $this->get("/products/$snapshot")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('offers', 2));
});

test('litres and millilitres in the same family are compared together', function () {
    $family = ProductFamily::create([
        'family_name' => 'Vanduo',
        'normalized_family_name' => 'vanduo-litres-ml',
    ]);
    $litre = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
        'canonical_name' => 'Vanduo 1 l',
    ]);
    $millilitre = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
        'canonical_name' => 'Vanduo 500 ml',
    ]);
    $rimi = Store::factory()->create(['name' => 'rimi', 'slug' => 'rimi']);
    $maxima = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);

    $snapshot = seedOfferForVariant($litre, $rimi, 0.99, 'l', 1.0);
    seedOfferForVariant($millilitre, $maxima, 0.59, 'ml', 500.0);

    $this->get("/products/$snapshot")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('offers', 2));
});

test('different water families are linked by their shared core noun', function () {
    $subcategoryId = seedSubcategory('vanduo');

    $gazuotas = ProductFamily::create([
        'family_name' => 'gazuotas vanduo',
        'normalized_family_name' => 'gazuotas vanduo',
    ]);
    $mineralinis = ProductFamily::create([
        'family_name' => 'mineralinis vanduo',
        'normalized_family_name' => 'mineralinis vanduo',
    ]);

    $gProduct = NormalizedProduct::factory()->create([
        'family_id' => $gazuotas->id,
        'subcategory_id' => $subcategoryId,
        'canonical_name' => 'Gazuotas vanduo',
    ]);
    $mProduct = NormalizedProduct::factory()->create([
        'family_id' => $mineralinis->id,
        'subcategory_id' => $subcategoryId,
        'canonical_name' => 'Mineralinis vanduo',
    ]);
    $rimi = Store::factory()->create(['name' => 'rimi', 'slug' => 'rimi']);
    $maxima = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);

    $snapshot = seedOfferForVariant($gProduct, $rimi, 0.99, 'l', 1.0);
    seedOfferForVariant($mProduct, $maxima, 1.29, 'l', 1.0);

    $this->get("/products/$snapshot")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('offers', 2));
});

test('products are linked by generic_name even when the family name ends in a qualifier', function () {
    $subcategoryId = seedSubcategory('sviestas');

    $withSalt = ProductFamily::create([
        'family_name' => 'sviestas su druska',
        'normalized_family_name' => 'sviestas su druska',
    ]);
    $fermented = ProductFamily::create([
        'family_name' => 'fermentinis sviestas',
        'normalized_family_name' => 'fermentinis sviestas',
    ]);

    $opened = NormalizedProduct::factory()->create([
        'family_id' => $withSalt->id,
        'subcategory_id' => $subcategoryId,
        'generic_name' => 'sviestas',
        'canonical_name' => 'Sviestas su druska 82%',
    ]);
    $sibling = NormalizedProduct::factory()->create([
        'family_id' => $fermented->id,
        'subcategory_id' => $subcategoryId,
        'generic_name' => 'sviestas',
        'canonical_name' => 'Fermentinis sviestas',
    ]);
    $rimi = Store::factory()->create(['name' => 'rimi', 'slug' => 'rimi']);
    $maxima = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);

    $snapshot = seedOfferForVariant($opened, $rimi, 1.99, 'g', 200.0);
    seedOfferForVariant($sibling, $maxima, 2.49, 'g', 200.0);

    $this->get("/products/$snapshot")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('offers', 2));
});

test('same subcategory products with a different core noun stay out of the comparison', function () {
    $subcategoryId = seedSubcategory('gerimai');

    $vanduoFamily = ProductFamily::create([
        'family_name' => 'gazuotas vanduo',
        'normalized_family_name' => 'gazuotas vanduo b',
    ]);
    $sultysFamily = ProductFamily::create([
        'family_name' => 'obuoliu sultys',
        'normalized_family_name' => 'obuoliu sultys',
    ]);

    $water = NormalizedProduct::factory()->create([
        'family_id' => $vanduoFamily->id,
        'subcategory_id' => $subcategoryId,
        'canonical_name' => 'Gazuotas vanduo',
    ]);
    $juice = NormalizedProduct::factory()->create([
        'family_id' => $sultysFamily->id,
        'subcategory_id' => $subcategoryId,
        'canonical_name' => 'Obuoliu sultys',
    ]);
    $rimi = Store::factory()->create(['name' => 'rimi', 'slug' => 'rimi']);
    $maxima = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);

    $snapshot = seedOfferForVariant($water, $rimi, 0.99, 'l', 1.0);
    seedOfferForVariant($juice, $maxima, 1.49, 'l', 1.0);

    $this->get("/products/$snapshot")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('offers', 1));
});
