<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ProductFamily;
use App\Models\SavedCart;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function seedSnapshotWithFamily(array $attributes): int
{
    $storeName = $attributes['store_name'];
    $productName = $attributes['product_name'];
    $scrapedAt = $attributes['scraped_at'];
    $familyId = $attributes['family_id'] ?? null;
    $price = $attributes['price'] ?? 1.49;

    $storeId = DB::table('stores')->where('name', $storeName)->value('id')
        ?? DB::table('stores')->insertGetId([
            'name' => $storeName,
            'slug' => $storeName,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    $rawOfferId = DB::table('raw_offers')->insertGetId([
        'store_id' => $storeId,
        'content_hash' => 'hash-'.uniqid('', true),
        'name' => $productName,
        'price' => $price,
        'scraped_at' => $scrapedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $normalizedProductId = DB::table('normalized_products')->insertGetId([
        'canonical_name' => $productName,
        'normalized_name' => strtolower($productName).'-'.uniqid('', true),
        'brand' => null,
        'family_id' => $familyId,
        'needs_review' => false,
        'normalization_version' => 'test',
        'source_name' => $productName,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $variantId = DB::table('product_variants')->insertGetId([
        'normalized_product_id' => $normalizedProductId,
        'variant_name' => '1 l',
        'size_value' => 1,
        'size_unit' => 'l',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return (int) DB::table('offer_snapshots')->insertGetId([
        'raw_offer_id' => $rawOfferId,
        'product_variant_id' => $variantId,
        'store_id' => $storeId,
        'week_starting' => Carbon::parse($scrapedAt)->startOfWeek()->toDateString(),
        'price' => $price,
        'loyalty_card' => false,
        'scraped_at' => $scrapedAt,
        'is_promotion' => false,
        'created_at' => now(),
    ]);
}

test('without a visibility floor both fresh and older snapshots show in listing', function () {
    config(['kainu.visibility_floor' => null]);

    seedSnapshotWithFamily([
        'store_name' => 'maxima',
        'product_name' => 'Pienas A',
        'scraped_at' => Carbon::now()->subDays(2),
    ]);

    seedSnapshotWithFamily([
        'store_name' => 'rimi',
        'product_name' => 'Pienas B',
        'scraped_at' => Carbon::now(),
    ]);

    $this->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 2));
});

test('setting the floor hides snapshots scraped before it from the catalog', function () {
    Carbon::setTestNow('2026-05-14 10:00:00');

    seedSnapshotWithFamily([
        'store_name' => 'maxima',
        'product_name' => 'Pienas A (senas)',
        'scraped_at' => Carbon::parse('2026-05-10 12:00:00'),
    ]);

    seedSnapshotWithFamily([
        'store_name' => 'rimi',
        'product_name' => 'Pienas B (naujas)',
        'scraped_at' => Carbon::parse('2026-05-13 09:00:00'),
    ]);

    config(['kainu.visibility_floor' => '2026-05-12 00:00:00']);

    $this->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Pienas B (naujas)')
        );

    Carbon::setTestNow();
});

test('floor hides pre-floor offers from per-store counts on the listing page', function () {
    Carbon::setTestNow('2026-05-14 10:00:00');

    seedSnapshotWithFamily([
        'store_name' => 'norfa',
        'product_name' => 'Senas A',
        'scraped_at' => Carbon::parse('2026-05-10 12:00:00'),
    ]);
    seedSnapshotWithFamily([
        'store_name' => 'norfa',
        'product_name' => 'Senas B',
        'scraped_at' => Carbon::parse('2026-05-10 13:00:00'),
    ]);
    seedSnapshotWithFamily([
        'store_name' => 'rimi',
        'product_name' => 'Naujas',
        'scraped_at' => Carbon::parse('2026-05-13 09:00:00'),
    ]);

    config(['kainu.visibility_floor' => '2026-05-12 00:00:00']);

    $this->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('allStores', function ($stores): bool {
                $byId = collect($stores)->keyBy('id');

                return ($byId->get('norfa')['count'] ?? null) === 0
                    && ($byId->get('rimi')['count'] ?? null) === 1;
            })
        );

    Carbon::setTestNow();
});

test('floor hides pre-floor entries from the stats page', function () {
    $preFloor = seedSnapshotWithFamily([
        'store_name' => 'maxima',
        'product_name' => 'Senas',
        'scraped_at' => Carbon::parse('2026-05-10 12:00:00'),
    ]);
    $postFloor = seedSnapshotWithFamily([
        'store_name' => 'rimi',
        'product_name' => 'Naujas',
        'scraped_at' => Carbon::parse('2026-05-13 09:00:00'),
    ]);

    foreach ([[$preFloor, '2026-05-10 12:00:00', -40.0], [$postFloor, '2026-05-13 09:00:00', -10.0]] as [$snapshotId, $changedAt, $pct]) {
        $row = DB::table('offer_snapshots')->where('id', $snapshotId)->first();

        // The prior-week snapshot the finding was compared against. Scraped
        // pre-floor so it backs the change without inflating totalSnapshots.
        DB::table('offer_snapshots')->insert([
            'product_variant_id' => $row->product_variant_id,
            'store_id' => $row->store_id,
            'week_starting' => Carbon::parse($changedAt)->subWeek()->startOfWeek()->toDateString(),
            'price' => 2.00,
            'scraped_at' => Carbon::parse($changedAt)->subWeek(),
            'created_at' => now(),
        ]);

        DB::table('price_changes')->insert([
            'product_variant_id' => $row->product_variant_id,
            'store_id' => $row->store_id,
            'previous_price' => 2.00,
            'new_price' => 1.00,
            'change_pct' => $pct,
            'week_starting' => Carbon::parse($changedAt)->startOfWeek()->toDateString(),
            'changed_at' => $changedAt,
            'snapshot_id' => $snapshotId,
        ]);
    }

    config(['kainu.visibility_floor' => '2026-05-12 00:00:00']);

    $this->actingAs(User::factory()->create())
        ->get(route('stats.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('biggestChanges', 1)
            ->where('biggestChanges.0.changePct', fn ($v) => (float) $v === -10.0)
            ->where('summary.totalSnapshots', 1)
            ->where('summary.totalChanges', 1)
            ->where('summary.totalDrops', 1)
        );
});

test('floor hides AI-flagged + user-reported products from admin reports', function () {
    $preFloorId = seedSnapshotWithFamily([
        'store_name' => 'maxima',
        'product_name' => 'Senas (low confidence)',
        'scraped_at' => Carbon::parse('2026-05-10 12:00:00'),
    ]);
    $postFloorId = seedSnapshotWithFamily([
        'store_name' => 'rimi',
        'product_name' => 'Naujas (low confidence)',
        'scraped_at' => Carbon::parse('2026-05-13 09:00:00'),
    ]);

    $preFloorProductId = (int) DB::table('product_variants')
        ->where('id', DB::table('offer_snapshots')->where('id', $preFloorId)->value('product_variant_id'))
        ->value('normalized_product_id');
    $postFloorProductId = (int) DB::table('product_variants')
        ->where('id', DB::table('offer_snapshots')->where('id', $postFloorId)->value('product_variant_id'))
        ->value('normalized_product_id');

    DB::table('normalized_products')->whereIn('id', [$preFloorProductId, $postFloorProductId])
        ->update(['ai_confidence' => 0.5, 'needs_review' => true]);

    config(['kainu.visibility_floor' => '2026-05-12 00:00:00']);

    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)
        ->get(route('admin.reports'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('aiFlagged', 1)
            ->where('aiFlagged.0.id', $postFloorProductId)
        );
});

test('floor does not affect saved-list family resolution', function () {
    Carbon::setTestNow('2026-05-14 10:00:00');

    $user = User::factory()->create();
    $family = ProductFamily::create([
        'family_name' => 'Pienas',
        'normalized_family_name' => 'pienas-'.uniqid(),
    ]);

    $snapshotId = seedSnapshotWithFamily([
        'store_name' => 'maxima',
        'product_name' => 'Pienas pre-floor',
        'scraped_at' => Carbon::parse('2026-05-10 12:00:00'),
        'family_id' => $family->id,
        'price' => 1.29,
    ]);

    $variantId = (int) DB::table('offer_snapshots')->where('id', $snapshotId)->value('product_variant_id');

    $cart = SavedCart::create(['user_id' => $user->id, 'name' => 'Senas sąrašas']);
    $cart->items()->create([
        'product_family_id' => $family->id,
        'product_variant_id' => $variantId,
        'quantity' => 1,
        'snapshot_name' => 'Pienas pre-floor',
        'snapshot_store_slug' => 'maxima',
        'snapshot_price_cents' => 129,
    ]);

    config(['kainu.visibility_floor' => '2026-05-12 00:00:00']);

    $this->actingAs($user)
        ->postJson("/sarasai/{$cart->id}/atkurti")
        ->assertOk()
        ->assertJsonPath('items.0.available', true)
        ->assertJsonPath('items.0.current.price', 1.29);

    Carbon::setTestNow();
});
