<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function seedStoreVariantSnapshot(string $storeName, string $productName, float $price, ?float $changePct = null, ?string $rawName = null, bool $withBaseline = true): array
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
        'scraped_at' => now(),
        'created_at' => now(),
    ]);

    if ($changePct !== null) {
        $previousPrice = round($price / (1 + $changePct / 100), 2);

        // The prior-week snapshot the finding was compared against. A real
        // price_change always has one; omit it (withBaseline: false) to model
        // an orphaned finding whose history was deleted.
        if ($withBaseline) {
            DB::table('offer_snapshots')->insert([
                'product_variant_id' => $variantId,
                'store_id' => $storeId,
                'week_starting' => now()->subWeek()->startOfWeek()->toDateString(),
                'price' => $previousPrice,
                'scraped_at' => now()->subWeek(),
                'created_at' => now(),
            ]);
        }

        DB::table('price_changes')->insert([
            'product_variant_id' => $variantId,
            'store_id' => $storeId,
            'previous_price' => $previousPrice,
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
    $response = $this->actingAs(User::factory()->create())->get(route('stats.index'));

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

    $response = $this->actingAs(User::factory()->create())->get(route('stats.index'));

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

it('exposes the full raw scraped name, falling back to the canonical name', function (): void {
    seedStoreVariantSnapshot('rimi', 'Pienas 2,5% Rokiškio', 1.20, -25.0, rawName: 'ROKIŠKIO pienas 2,5% riebumo 1 L');
    seedStoreVariantSnapshot('iki', 'Duona', 1.00, -10.0);

    $response = $this->actingAs(User::factory()->create())->get(route('stats.index'));

    $response->assertOk()->assertInertia(
        fn (Assert $page) => $page
            ->where('biggestChanges.0.rawName', 'ROKIŠKIO pienas 2,5% riebumo 1 L')
            ->where('biggestChanges.0.productName', 'Pienas 2,5% Rokiškio')
            ->where('biggestChanges.1.rawName', 'Duona')
    );
});

it('hides extreme outliers (promo swings) beyond +-100% from the biggest changes', function (): void {
    seedStoreVariantSnapshot('aibe', 'Normal change', 1.20, -25.0);
    seedStoreVariantSnapshot('rimi', 'Promo ending', 4.49, 358.0);

    $response = $this->actingAs(User::factory()->create())->get(route('stats.index'));

    $response->assertOk()->assertInertia(
        fn (Assert $page) => $page
            ->has('biggestChanges', 1)
            ->where('biggestChanges.0.productName', 'Normal change')
    );
});

it('hides a finding whose baseline snapshot was deleted (orphaned price history)', function (): void {
    // Supported: still has its prior-week snapshot.
    seedStoreVariantSnapshot('aibe', 'Supported', 1.20, -25.0);
    // Orphaned: its earlier snapshot was removed, so the change references a
    // price that no longer has any history behind it.
    seedStoreVariantSnapshot('rimi', 'Orphaned', 0.35, -87.0, withBaseline: false);

    $response = $this->actingAs(User::factory()->create())->get(route('stats.index'));

    $response->assertOk()->assertInertia(
        fn (Assert $page) => $page
            ->has('biggestChanges', 1)
            ->where('biggestChanges.0.productName', 'Supported')
            // Summary counts must also exclude the orphaned finding.
            ->where('summary.totalChanges', 1)
            ->where('summary.totalDrops', 1)
    );
});

it('lists every price drop behind the Atpigimai count', function (): void {
    seedStoreVariantSnapshot('aibe', 'Drop A', 1.00, -15.0);
    seedStoreVariantSnapshot('rimi', 'Drop B', 2.00, -40.0);
    seedStoreVariantSnapshot('iki', 'Rise', 3.00, 20.0);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('stats.changes', ['direction' => 'drops']));

    $response->assertOk()
        ->assertJsonPath('direction', 'drops')
        ->assertJsonCount(2, 'changes')
        // Sorted by absolute change desc.
        ->assertJsonPath('changes.0.productName', 'Drop B')
        ->assertJsonPath('changes.1.productName', 'Drop A');

    expect(collect($response->json('changes'))->every(fn ($c) => $c['changePct'] < 0))->toBeTrue();
});

it('lists every price raise behind the Pabrangimai count', function (): void {
    seedStoreVariantSnapshot('aibe', 'Drop', 1.00, -15.0);
    seedStoreVariantSnapshot('rimi', 'Rise', 2.00, 30.0);

    $this->actingAs(User::factory()->create())
        ->getJson(route('stats.changes', ['direction' => 'raises']))
        ->assertOk()
        ->assertJsonCount(1, 'changes')
        ->assertJsonPath('changes.0.productName', 'Rise');
});

it('omits orphaned findings from the changes list, matching the summary count', function (): void {
    seedStoreVariantSnapshot('aibe', 'Supported', 1.00, -20.0);
    seedStoreVariantSnapshot('rimi', 'Orphaned', 0.35, -80.0, withBaseline: false);

    $this->actingAs(User::factory()->create())
        ->getJson(route('stats.changes', ['direction' => 'drops']))
        ->assertOk()
        ->assertJsonCount(1, 'changes')
        ->assertJsonPath('changes.0.productName', 'Supported');
});

it('rejects an unknown changes direction', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson(route('stats.changes', ['direction' => 'bogus']))
        ->assertNotFound();
});

it('requires authentication to load the changes list', function (): void {
    $this->getJson(route('stats.changes', ['direction' => 'drops']))
        ->assertUnauthorized();
});

it('includes price raises alongside drops, ranked by absolute change', function (): void {
    seedStoreVariantSnapshot('aibe', 'Drop', 1.00, -15.0);
    seedStoreVariantSnapshot('rimi', 'Rise', 2.00, 30.0);
    seedStoreVariantSnapshot('iki', 'SmallRise', 1.50, 5.0);

    $response = $this->actingAs(User::factory()->create())->get(route('stats.index'));

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
