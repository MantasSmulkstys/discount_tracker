<?php

use App\Enums\UserRole;
use App\Models\NormalizedProduct;
use App\Models\ProductFamily;
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
function seedOfferFor(NormalizedProduct $product, float $price = 1.99, ?float $oldPrice = 2.99, string $storeName = 'testmart', ?int $variantId = null, ?string $weekStarting = null, ?string $rawName = null): array
{
    $weekStarting ??= now()->startOfWeek()->toDateString();

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

    $rawOfferId = $rawName === null ? null : DB::table('raw_offers')->insertGetId([
        'store_id' => $storeId,
        'name' => $rawName,
        'loyalty_card' => false,
        'scraped_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $snapshotId = DB::table('offer_snapshots')->insertGetId([
        'product_variant_id' => $variantId,
        'store_id' => $storeId,
        'raw_offer_id' => $rawOfferId,
        'week_starting' => $weekStarting,
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
    seedOfferFor($lowConf);
    NormalizedProduct::factory()->create(['ai_confidence' => 0.95]);

    $this->actingAs($admin)
        ->get('/admin/reports')
        ->assertInertia(fn ($page) => $page
            ->has('aiFlagged', 1)
            ->where('aiFlagged.0.id', $lowConf->id)
        );
});

test('low-confidence products whose offers are not in the current batch are excluded', function () {
    $admin = makeAdmin();

    $current = NormalizedProduct::factory()->lowConfidence()->create();
    seedOfferFor($current, storeName: 'maxima');

    $stale = NormalizedProduct::factory()->lowConfidence()->create();
    seedOfferFor($stale, storeName: 'rimi', weekStarting: now()->subWeeks(3)->startOfWeek()->toDateString());

    $this->actingAs($admin)
        ->get('/admin/reports')
        ->assertInertia(fn ($page) => $page
            ->has('aiFlagged', 1)
            ->where('aiFlagged.0.id', $current->id)
        );
});

test('reports payload exposes the full raw scraped name', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->lowConfidence()->create(['canonical_name' => 'Pienas']);
    seedOfferFor($product, storeName: 'maxima', rawName: 'ROKIŠKIO pienas 2,5% riebumo 1 L');

    $this->actingAs($admin)
        ->get('/admin/reports')
        ->assertInertia(fn ($page) => $page
            ->where('aiFlagged.0.rawName', 'ROKIŠKIO pienas 2,5% riebumo 1 L')
            ->where('aiFlagged.0.canonicalName', 'Pienas')
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

test('admin can assign a family to a product', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create(['family_id' => null]);
    $family = ProductFamily::create([
        'family_name' => 'Sūris',
        'normalized_family_name' => 'suris',
        'generic_name' => 'suris',
    ]);

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}", [
            'canonical_name' => $product->canonical_name,
            'family_id' => $family->id,
        ])
        ->assertRedirect();

    $product->refresh();
    expect($product->family_id)->toBe($family->id);
});

test('admin can clear a product family', function () {
    $admin = makeAdmin();
    $family = ProductFamily::create([
        'family_name' => 'Sūris',
        'normalized_family_name' => 'suris',
        'generic_name' => 'suris',
    ]);
    $product = NormalizedProduct::factory()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}", [
            'canonical_name' => $product->canonical_name,
            'family_id' => null,
        ])
        ->assertRedirect();

    $product->refresh();
    expect($product->family_id)->toBeNull();
});

test('admin update rejects a non-existent family', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}", [
            'canonical_name' => $product->canonical_name,
            'family_id' => 999999,
        ])
        ->assertSessionHasErrors('family_id');
});

test('reports payload exposes family id and name', function () {
    $admin = makeAdmin();
    $family = ProductFamily::create([
        'family_name' => 'Sūris',
        'normalized_family_name' => 'suris',
        'generic_name' => 'suris',
    ]);
    $product = NormalizedProduct::factory()->lowConfidence()->create([
        'family_id' => $family->id,
    ]);
    seedOfferFor($product);

    $this->actingAs($admin)
        ->get('/admin/reports')
        ->assertInertia(fn ($page) => $page
            ->where('aiFlagged.0.familyId', $family->id)
            ->where('aiFlagged.0.familyName', 'Sūris')
        );
});

test('reports page exposes the family vocabulary', function () {
    $admin = makeAdmin();
    $family = ProductFamily::create([
        'family_name' => 'Sūris',
        'normalized_family_name' => 'suris',
        'generic_name' => 'suris',
    ]);

    $this->actingAs($admin)
        ->get('/admin/reports')
        ->assertInertia(fn ($page) => $page
            ->has('families')
            ->where('families.0.id', $family->id)
            ->where('families.0.nameLt', 'Sūris')
            ->where('families.0.key', 'suris')
        );
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

test('editing an offer price updates the linked price_changes row for statistics', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();
    $offer = seedOfferFor($product, price: 2.00, oldPrice: null);

    DB::table('price_changes')->insert([
        'product_variant_id' => $offer['variantId'],
        'store_id' => $offer['storeId'],
        'previous_price' => 1.00,
        'new_price' => 2.00,
        'change_pct' => 100.00,
        'week_starting' => now()->startOfWeek()->toDateString(),
        'changed_at' => now(),
        'snapshot_id' => $offer['snapshotId'],
    ]);

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}", [
            'canonical_name' => $product->canonical_name,
            'offers' => [[
                'snapshotId' => $offer['snapshotId'],
                'storeId' => $offer['storeId'],
                'variantId' => $offer['variantId'],
                'price' => 1.20,
                'oldPrice' => null,
                'condition' => null,
            ]],
        ])
        ->assertRedirect();

    $change = DB::table('price_changes')->where('snapshot_id', $offer['snapshotId'])->first();
    expect((float) $change->new_price)->toBe(1.20);
    expect((float) $change->previous_price)->toBe(1.00);
    // (1.20 - 1.00) / 1.00 * 100 = 20.00
    expect((float) $change->change_pct)->toBe(20.00);
});

test('clearing an offer price removes the stale price_changes row', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();
    $offer = seedOfferFor($product, price: 2.00, oldPrice: null);

    DB::table('price_changes')->insert([
        'product_variant_id' => $offer['variantId'],
        'store_id' => $offer['storeId'],
        'previous_price' => 1.00,
        'new_price' => 2.00,
        'change_pct' => 100.00,
        'week_starting' => now()->startOfWeek()->toDateString(),
        'changed_at' => now(),
        'snapshot_id' => $offer['snapshotId'],
    ]);

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}", [
            'canonical_name' => $product->canonical_name,
            'offers' => [[
                'snapshotId' => $offer['snapshotId'],
                'storeId' => $offer['storeId'],
                'variantId' => $offer['variantId'],
                'price' => null,
                'oldPrice' => null,
                'condition' => null,
            ]],
        ])
        ->assertRedirect();

    expect(DB::table('price_changes')->where('snapshot_id', $offer['snapshotId'])->exists())->toBeFalse();
});

function seedPastSnapshot(int $storeId, int $variantId, string $week, float $price, ?float $oldPrice = null): int
{
    return DB::table('offer_snapshots')->insertGetId([
        'product_variant_id' => $variantId,
        'store_id' => $storeId,
        'week_starting' => $week,
        'price' => $price,
        'old_price' => $oldPrice,
        'is_promotion' => $oldPrice !== null,
        'loyalty_card' => false,
        'scraped_at' => now(),
        'created_at' => now(),
    ]);
}

test('admin can load formatted price history with the current snapshot flagged', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();
    $current = seedOfferFor($product, price: 1.50, oldPrice: null, storeName: 'maxima');
    seedPastSnapshot($current['storeId'], $current['variantId'], '2026-05-04', 1.80, 2.00);

    $response = $this->actingAs($admin)->getJson("/admin/products/{$product->id}/history");

    $response->assertOk()->assertJsonStructure([
        'history' => [[
            'snapshotId', 'storeName', 'weekStarting', 'price', 'oldPrice',
            'discount', 'condition', 'isCurrent', 'rawName', 'variantLabel',
            'imageUrl', 'unitPrice', 'unitPriceUnit', 'validFrom', 'validTo',
            'isPromotion', 'loyaltyCard',
        ]],
    ]);

    $history = collect($response->json('history'));
    expect($history)->toHaveCount(2);
    expect($history->where('isCurrent', true))->toHaveCount(1);
    expect($history->where('isCurrent', false))->toHaveCount(1);
});

test('non-admin cannot load price history', function () {
    $user = User::factory()->create();
    $product = NormalizedProduct::factory()->create();

    $this->actingAs($user)
        ->getJson("/admin/products/{$product->id}/history")
        ->assertForbidden();
});

test('admin can edit a historical snapshot and price_changes syncs without touching current_offers', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();
    $current = seedOfferFor($product, price: 1.50, oldPrice: null, storeName: 'maxima');
    $pastId = seedPastSnapshot($current['storeId'], $current['variantId'], '2026-05-04', 1.80);

    DB::table('price_changes')->insert([
        'product_variant_id' => $current['variantId'],
        'store_id' => $current['storeId'],
        'previous_price' => 2.00,
        'new_price' => 1.80,
        'change_pct' => -10.00,
        'week_starting' => '2026-05-04',
        'changed_at' => now(),
        'snapshot_id' => $pastId,
    ]);

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}/history", [
            'history' => [[
                'snapshotId' => $pastId,
                'price' => 1.70,
                'oldPrice' => null,
                'condition' => null,
            ]],
        ])
        ->assertRedirect();

    expect((float) DB::table('offer_snapshots')->where('id', $pastId)->value('price'))->toBe(1.70);
    // (1.70 - 2.00) / 2.00 * 100 = -15.00
    expect((float) DB::table('price_changes')->where('snapshot_id', $pastId)->value('change_pct'))->toBe(-15.00);
    expect((float) DB::table('price_changes')->where('snapshot_id', $pastId)->value('new_price'))->toBe(1.70);
    // current_offers points at the current snapshot, so it must be untouched.
    expect((float) DB::table('current_offers')->where('product_variant_id', $current['variantId'])->value('price'))->toBe(1.50);
});

test('admin can delete a historical snapshot and its linked price_changes', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();
    $current = seedOfferFor($product, price: 1.50, oldPrice: null, storeName: 'maxima');
    $pastId = seedPastSnapshot($current['storeId'], $current['variantId'], '2026-05-04', 1.80);

    DB::table('price_changes')->insert([
        'product_variant_id' => $current['variantId'],
        'store_id' => $current['storeId'],
        'previous_price' => 2.00,
        'new_price' => 1.80,
        'change_pct' => -10.00,
        'week_starting' => '2026-05-04',
        'changed_at' => now(),
        'snapshot_id' => $pastId,
    ]);

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}/history", [
            'deleted_snapshot_ids' => [$pastId],
        ])
        ->assertRedirect();

    expect(DB::table('offer_snapshots')->where('id', $pastId)->exists())->toBeFalse();
    expect(DB::table('price_changes')->where('snapshot_id', $pastId)->exists())->toBeFalse();
});

test('admin cannot delete the snapshot a current offer points at', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();
    $current = seedOfferFor($product, price: 1.50, oldPrice: null, storeName: 'maxima');

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}/history", [
            'deleted_snapshot_ids' => [$current['snapshotId']],
        ])
        ->assertRedirect();

    expect(DB::table('offer_snapshots')->where('id', $current['snapshotId'])->exists())->toBeTrue();
});

test('history edit is ignored for a snapshot belonging to another product', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();
    $other = NormalizedProduct::factory()->create();
    $foreign = seedOfferFor($other, price: 5.00, storeName: 'lidl');
    $foreignPast = seedPastSnapshot($foreign['storeId'], $foreign['variantId'], '2026-05-04', 4.00);

    $this->actingAs($admin)
        ->patch("/admin/products/{$product->id}/history", [
            'history' => [[
                'snapshotId' => $foreignPast,
                'price' => 0.01,
                'oldPrice' => null,
                'condition' => null,
            ]],
        ])
        ->assertRedirect();

    expect((float) DB::table('offer_snapshots')->where('id', $foreignPast)->value('price'))->toBe(4.00);
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

test('hiding a product removes it from the public catalog and lists it in the hidden tab', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create();
    seedOfferFor($product, storeName: 'maxima');

    // Visible in the catalog before hiding.
    $this->get(route('products.index'))
        ->assertInertia(fn ($page) => $page->has('products.data', 1));

    $this->actingAs($admin)
        ->post("/admin/products/{$product->id}/hide")
        ->assertRedirect();

    expect($product->fresh()->is_hidden)->toBeTrue();

    // Gone from the public catalog…
    $this->get(route('products.index'))
        ->assertInertia(fn ($page) => $page->has('products.data', 0));

    // …and surfaced in the admin hidden tab instead.
    $this->actingAs($admin)
        ->get('/admin/reports')
        ->assertInertia(fn ($page) => $page
            ->has('hidden', 1)
            ->where('hidden.0.id', $product->id)
            ->where('hidden.0.isHidden', true)
        );
});

test('unhiding a product returns it to the public catalog', function () {
    $admin = makeAdmin();
    $product = NormalizedProduct::factory()->create(['is_hidden' => true]);
    seedOfferFor($product, storeName: 'maxima');

    $this->actingAs($admin)
        ->delete("/admin/products/{$product->id}/hide")
        ->assertRedirect();

    expect($product->fresh()->is_hidden)->toBeFalse();

    $this->get(route('products.index'))
        ->assertInertia(fn ($page) => $page->has('products.data', 1));
});

test('the hidden tab only lists hidden products still on a current listing', function () {
    $admin = makeAdmin();

    $current = NormalizedProduct::factory()->create(['is_hidden' => true]);
    seedOfferFor($current, storeName: 'maxima');

    // A hidden product whose promotion has ended: its latest offer falls
    // outside the freshness window, so it must drop off the hidden tab.
    $stale = NormalizedProduct::factory()->create(['is_hidden' => true]);
    $staleOffer = seedOfferFor($stale, storeName: 'rimi');
    DB::table('offer_snapshots')
        ->where('id', $staleOffer['snapshotId'])
        ->update(['scraped_at' => now()->subWeeks(4)]);

    $this->actingAs($admin)
        ->get('/admin/reports')
        ->assertInertia(fn ($page) => $page
            ->has('hidden', 1)
            ->where('hidden.0.id', $current->id)
        );
});

test('hidden products are excluded from the user-reported and ai-flagged queues', function () {
    $admin = makeAdmin();

    $flagged = NormalizedProduct::factory()->lowConfidence()->create(['is_hidden' => true]);
    seedOfferFor($flagged, storeName: 'maxima');

    $reported = NormalizedProduct::factory()->create(['is_hidden' => true]);
    seedOfferFor($reported, storeName: 'rimi');
    User::factory()->count(5)->create()->each(fn (User $user) => ProductReport::create([
        'user_id' => $user->id,
        'normalized_product_id' => $reported->id,
    ]));

    $this->actingAs($admin)
        ->get('/admin/reports')
        ->assertInertia(fn ($page) => $page
            ->where('aiFlagged', [])
            ->where('userReported', [])
        );
});

test('non-admin cannot hide a product', function () {
    $user = User::factory()->create();
    $product = NormalizedProduct::factory()->create();

    $this->actingAs($user)
        ->post("/admin/products/{$product->id}/hide")
        ->assertForbidden();

    expect($product->fresh()->is_hidden)->toBeFalse();
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
