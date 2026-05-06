<?php

use App\Enums\UserRole;
use App\Models\NormalizedProduct;
use App\Models\ProductReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function makeAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin]);
}

/**
 * @return array{snapshotId: int, storeId: int, variantId: int}
 */
function seedOfferFor(NormalizedProduct $product, float $price = 1.99, ?float $oldPrice = 2.99, string $storeName = 'testmart', ?int $variantId = null): array
{
    $storeId = DB::table('stores')->insertGetId([
        'name' => $storeName,
        'slug' => $storeName,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $variantId ??= DB::table('product_variants')->insertGetId([
        'normalized_product_id' => $product->id,
        'variant_name' => 'default',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $snapshotId = DB::table('offer_snapshots')->insertGetId([
        'product_variant_id' => $variantId,
        'store_id' => $storeId,
        'week_starting' => now()->startOfWeek()->toDateString(),
        'price' => $price,
        'old_price' => $oldPrice,
        'is_promotion' => $oldPrice !== null,
        'loyalty_card' => false,
        'scraped_at' => now(),
        'created_at' => now(),
    ]);

    DB::table('current_offers')->insert([
        'store_id' => $storeId,
        'product_variant_id' => $variantId,
        'price' => $price,
        'old_price' => $oldPrice,
        'is_promotion' => $oldPrice !== null,
        'loyalty_card' => false,
        'last_seen_at' => now(),
        'week_starting' => now()->startOfWeek()->toDateString(),
        'last_snapshot_id' => $snapshotId,
        'updated_at' => now(),
    ]);

    return [
        'snapshotId' => $snapshotId,
        'storeId' => $storeId,
        'variantId' => $variantId,
    ];
}

test('non-admin cannot access reports page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/reports')
        ->assertForbidden();
});

test('guest cannot access reports page', function () {
    $this->get('/admin/reports')->assertRedirect('/login');
});

test('admin can view reports page', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Reports')
            ->has('userReported')
            ->has('aiFlagged')
            ->has('categories')
        );
});

test('products with 5 or more reports appear in user-reported list', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();

    User::factory()->count(5)->create()->each(function (User $user) use ($product) {
        ProductReport::create([
            'user_id' => $user->id,
            'normalized_product_id' => $product->id,
        ]);
    });

    $this->actingAs($admin)
        ->get('/admin/reports')
        ->assertInertia(fn ($page) => $page
            ->where('userReported.0.id', $product->id)
            ->where('userReported.0.reportsCount', 5)
        );
});

test('products with fewer than 5 reports do not appear in user-reported list', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();

    User::factory()->count(4)->create()->each(function (User $user) use ($product) {
        ProductReport::create([
            'user_id' => $user->id,
            'normalized_product_id' => $product->id,
        ]);
    });

    $this->actingAs($admin)
        ->get('/admin/reports')
        ->assertInertia(fn ($page) => $page
            ->where('userReported', [])
        );
});

test('low-confidence products appear in ai-flagged list', function () {
    $admin = makeAdmin();
    $lowConf = NormalizedProduct::factory()->lowConfidence()->create();
    NormalizedProduct::factory()->create(['ai_confidence' => 0.95]);

    $this->actingAs($admin)
        ->get('/admin/reports')
        ->assertInertia(fn ($page) => $page
            ->has('aiFlagged', 1)
            ->where('aiFlagged.0.id', $lowConf->id)
        );
});

test('high-confidence products with needs_review do not appear in ai-flagged list', function () {
    $admin = makeAdmin();
    NormalizedProduct::factory()->needsReview()->create(['ai_confidence' => 0.9]);

    $this->actingAs($admin)
        ->get('/admin/reports')
        ->assertInertia(fn ($page) => $page
            ->where('aiFlagged', [])
        );
});

test('admin update bumps ai_confidence so the product leaves the ai-flagged list', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->lowConfidence()->create();

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}", [
            'canonical_name' => 'Verified Name',
            'brand' => 'Brand',
            'category_id' => null,
            'subcategory_id' => null,
        ])
        ->assertRedirect();

    $product->refresh();
    expect((float) $product->ai_confidence)->toBe(1.0);

    $this->actingAs($admin)
        ->get('/admin/reports')
        ->assertInertia(fn ($page) => $page->where('aiFlagged', []));
});

test('admin can update a product and reports are cleared', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();

    User::factory()->count(5)->create()->each(function (User $user) use ($product) {
        ProductReport::create([
            'user_id' => $user->id,
            'normalized_product_id' => $product->id,
        ]);
    });

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}", [
            'canonical_name' => 'Updated Name',
            'brand' => 'New Brand',
            'category_id' => null,
            'subcategory_id' => null,
            'needs_review' => false,
        ])
        ->assertRedirect();

    $product->refresh();
    expect($product->canonical_name)->toBe('Updated Name');
    expect($product->brand)->toBe('New Brand');
    expect($product->needs_review)->toBeFalse();
    expect(ProductReport::where('normalized_product_id', $product->id)->count())->toBe(0);
});

test('admin update validates required fields', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}", [
            'canonical_name' => '',
        ])
        ->assertSessionHasErrors('canonical_name');
});

test('non-admin cannot update a product', function () {
    $user = User::factory()->create();
    $product = NormalizedProduct::factory()->create();

    $this->actingAs($user)
        ->patch("/admin/products/{$product->id}", [
            'canonical_name' => 'Hack',
            'needs_review' => false,
        ])
        ->assertForbidden();
});

test('reports payload exposes offers, best price and store count', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->lowConfidence()->create();
    $first = seedOfferFor($product, price: 1.50, oldPrice: 2.00, storeName: 'maxima');
    seedOfferFor($product, price: 1.20, oldPrice: 1.80, storeName: 'rimi', variantId: $first['variantId']);

    $this->actingAs($admin)
        ->get('/admin/reports')
        ->assertInertia(fn ($page) => $page
            ->where('aiFlagged.0.storeCount', 2)
            ->where('aiFlagged.0.bestPrice', 1.2)
            ->has('aiFlagged.0.offers', 2)
            ->where('aiFlagged.0.offers.0.storeName', 'Maxima')
            ->where('aiFlagged.0.offers.0.price', 1.5)
        );
});

test('admin can update offer prices and current_offers reflect the change', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();
    $offer = seedOfferFor($product, price: 1.99, oldPrice: 2.99);

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}", [
            'canonical_name' => $product->canonical_name,
            'brand' => $product->brand,
            'category_id' => null,
            'subcategory_id' => null,
            'offers' => [[
                'snapshotId' => $offer['snapshotId'],
                'storeId' => $offer['storeId'],
                'variantId' => $offer['variantId'],
                'price' => 1.49,
                'oldPrice' => 2.49,
                'condition' => '1+1',
            ]],
        ])
        ->assertRedirect();

    $snapshot = DB::table('offer_snapshots')->where('id', $offer['snapshotId'])->first();
    expect((float) $snapshot->price)->toBe(1.49);
    expect((float) $snapshot->old_price)->toBe(2.49);
    expect($snapshot->condition)->toBe('1+1');
    expect((float) $snapshot->discount_percent)->toBe(40.16);

    $current = DB::table('current_offers')
        ->where('store_id', $offer['storeId'])
        ->where('product_variant_id', $offer['variantId'])
        ->first();
    expect((float) $current->price)->toBe(1.49);
    expect((float) $current->old_price)->toBe(2.49);
    expect($current->condition)->toBe('1+1');
});

test('admin offer update is rejected when snapshot belongs to another product', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();
    $other = NormalizedProduct::factory()->create();
    $foreign = seedOfferFor($other, storeName: 'lidl');

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}", [
            'canonical_name' => $product->canonical_name,
            'offers' => [[
                'snapshotId' => $foreign['snapshotId'],
                'storeId' => $foreign['storeId'],
                'variantId' => $foreign['variantId'],
                'price' => 0.01,
                'oldPrice' => null,
                'condition' => null,
            ]],
        ])
        ->assertRedirect();

    $snapshot = DB::table('offer_snapshots')->where('id', $foreign['snapshotId'])->first();
    expect((float) $snapshot->price)->not->toBe(0.01);
});

test('admin can override discount independently of price calculation', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();
    $offer = seedOfferFor($product, price: 1.99, oldPrice: 2.99);

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}", [
            'canonical_name' => $product->canonical_name,
            'offers' => [[
                'snapshotId' => $offer['snapshotId'],
                'storeId' => $offer['storeId'],
                'variantId' => $offer['variantId'],
                'price' => 1.99,
                'oldPrice' => 2.99,
                'discount' => 50,
                'condition' => null,
            ]],
        ])
        ->assertRedirect();

    $snapshot = DB::table('offer_snapshots')->where('id', $offer['snapshotId'])->first();
    expect((float) $snapshot->discount_percent)->toBe(50.0);
});

test('reports payload includes storeSlug and current discount', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->lowConfidence()->create();
    seedOfferFor($product, price: 1.50, oldPrice: 2.00, storeName: 'maxima');

    $this->actingAs($admin)
        ->get('/admin/reports')
        ->assertInertia(fn ($page) => $page
            ->where('aiFlagged.0.offers.0.storeSlug', 'maxima')
            ->has('aiFlagged.0.offers.0.discount')
        );
});

test('admin offer update validates discount range', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();
    $offer = seedOfferFor($product);

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}", [
            'canonical_name' => $product->canonical_name,
            'offers' => [[
                'snapshotId' => $offer['snapshotId'],
                'storeId' => $offer['storeId'],
                'variantId' => $offer['variantId'],
                'price' => 1.0,
                'oldPrice' => 2.0,
                'discount' => 150,
                'condition' => null,
            ]],
        ])
        ->assertSessionHasErrors('offers.0.discount');
});

test('admin offer update validates negative prices', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();
    $offer = seedOfferFor($product);

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}", [
            'canonical_name' => $product->canonical_name,
            'offers' => [[
                'snapshotId' => $offer['snapshotId'],
                'storeId' => $offer['storeId'],
                'variantId' => $offer['variantId'],
                'price' => -5,
                'oldPrice' => null,
                'condition' => null,
            ]],
        ])
        ->assertSessionHasErrors('offers.0.price');
});
