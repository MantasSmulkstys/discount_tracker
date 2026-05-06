<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function relaxIdentityKey(): void
{
    DB::statement('DROP INDEX IF EXISTS normalized_products_identity_key_unique');
}

function seedDedupeNp(string $name, ?string $brand = null): int
{
    return DB::table('normalized_products')->insertGetId([
        'canonical_name' => $name,
        'normalized_name' => mb_strtolower($name),
        'brand' => $brand,
        'needs_review' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function seedDedupeVariant(int $npId, ?float $size = null, ?string $unit = null): int
{
    return DB::table('product_variants')->insertGetId([
        'normalized_product_id' => $npId,
        'variant_name' => 'default',
        'size_value' => $size,
        'size_unit' => $unit,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function seedDedupeSnapshot(int $variantId, int $storeId, string $week, float $price = 1.99, ?int $rawOfferId = null): int
{
    return DB::table('offer_snapshots')->insertGetId([
        'product_variant_id' => $variantId,
        'store_id' => $storeId,
        'raw_offer_id' => $rawOfferId,
        'week_starting' => $week,
        'price' => $price,
        'is_promotion' => false,
        'loyalty_card' => false,
        'scraped_at' => now(),
        'created_at' => now(),
    ]);
}

function seedDedupeRawOffer(int $storeId, string $contentHash, string $name = 'raw'): int
{
    return DB::table('raw_offers')->insertGetId([
        'store_id' => $storeId,
        'content_hash' => $contentHash,
        'name' => $name,
        'normalization_status' => 'done',
        'normalized_at' => now(),
        'scraped_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function seedDedupeStore(string $slug): int
{
    return DB::table('stores')->insertGetId([
        'name' => $slug,
        'slug' => $slug,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('does nothing when there are no duplicates', function () {
    $np = seedDedupeNp('Pienas', 'Rokiškio');
    seedDedupeVariant($np, 1.0, 'l');

    expect(DB::table('normalized_products')->count())->toBe(1);

    Artisan::call('products:dedupe');

    expect(DB::table('normalized_products')->count())->toBe(1);
});

it('merges siblings differing only in brand casing', function () {
    relaxIdentityKey();
    $storeId = seedDedupeStore('iki');

    $keeper = seedDedupeNp('Sūris', 'Mlekovita');
    $loser = seedDedupeNp('Sūris', 'MLEKOVITA');

    $vKeeper = seedDedupeVariant($keeper, 200.0, 'g');
    $vLoser = seedDedupeVariant($loser, 200.0, 'g');

    seedDedupeSnapshot($vKeeper, $storeId, '2026-04-27', 1.99);
    seedDedupeSnapshot($vLoser, $storeId, '2026-05-04', 1.79);

    Artisan::call('products:dedupe');

    expect(DB::table('normalized_products')->count())->toBe(1);
    expect(DB::table('normalized_products')->first()->id)->toBe($keeper);
    expect(DB::table('product_variants')->count())->toBe(1);
    expect(DB::table('offer_snapshots')->where('product_variant_id', $vKeeper)->count())->toBe(2);
});

it('treats NULL brand as a duplicate of empty-brand rows', function () {
    relaxIdentityKey();
    $keeper = seedDedupeNp('Avokadas', null);
    $loser = seedDedupeNp('Avokadas', '');

    seedDedupeVariant($keeper);
    seedDedupeVariant($loser);

    Artisan::call('products:dedupe');

    expect(DB::table('normalized_products')->count())->toBe(1);
    expect(DB::table('normalized_products')->first()->id)->toBe($keeper);
});

it('moves variants whose shape does not collide with the keeper', function () {
    relaxIdentityKey();
    $keeper = seedDedupeNp('Sūris', 'Mlekovita');
    $loser = seedDedupeNp('Sūris', 'MLEKOVITA');

    seedDedupeVariant($keeper, 200.0, 'g');
    $vLoser = seedDedupeVariant($loser, 500.0, 'g');

    Artisan::call('products:dedupe');

    expect(DB::table('product_variants')->count())->toBe(2);
    expect(DB::table('product_variants')->where('id', $vLoser)->value('normalized_product_id'))
        ->toBe($keeper);
});

it('drops loser snapshots that clash with keeper on (variant, store, week)', function () {
    relaxIdentityKey();
    $store = seedDedupeStore('iki');

    $keeper = seedDedupeNp('Sūris', 'Mlekovita');
    $loser = seedDedupeNp('Sūris', 'MLEKOVITA');

    $vKeeper = seedDedupeVariant($keeper, 200.0, 'g');
    $vLoser = seedDedupeVariant($loser, 200.0, 'g');

    seedDedupeSnapshot($vKeeper, $store, '2026-04-27', 1.99);
    seedDedupeSnapshot($vLoser, $store, '2026-04-27', 1.49);

    Artisan::call('products:dedupe');

    expect(DB::table('offer_snapshots')->count())->toBe(1);
    expect(DB::table('offer_snapshots')->first()->price)->toEqual('1.99');
});

it('--by=content-hash merges nps reached by raws sharing (store, content_hash)', function () {
    relaxIdentityKey();
    $store = seedDedupeStore('aibe');

    $keeper = seedDedupeNp('Kiaulienos sprandinės šašlykas su žolelėmis BIOVELA KAUKAZO', 'BIOVELA KAUKAZO');
    $loser = seedDedupeNp('Šaltai marinuotas kiaulienos sprandinės šašlykas su žolelėmis BIOVELA KAUKAZO', 'BIOVELA KAUKAZO');

    $vKeeper = seedDedupeVariant($keeper, 700.0, 'g');
    $vLoser = seedDedupeVariant($loser, 700.0, 'g');

    $rawA = seedDedupeRawOffer($store, '64f6481a3cb8');
    $rawB = seedDedupeRawOffer($store, '64f6481a3cb8');

    seedDedupeSnapshot($vKeeper, $store, '2026-04-27', 9.99, $rawA);
    seedDedupeSnapshot($vLoser, $store, '2026-05-04', 8.49, $rawB);

    Artisan::call('products:dedupe', ['--by' => 'content-hash']);

    expect(DB::table('normalized_products')->count())->toBe(1);
    expect(DB::table('normalized_products')->first()->id)->toBe($keeper);
    expect(DB::table('product_variants')->count())->toBe(1);
    expect(DB::table('offer_snapshots')->where('product_variant_id', $vKeeper)->count())->toBe(2);
});

it('--by=content-hash leaves nps reached only by distinct (store, hash) keys alone', function () {
    relaxIdentityKey();
    $store = seedDedupeStore('aibe');

    $a = seedDedupeNp('Avokadas', 'Foo');
    $b = seedDedupeNp('Bananas', 'Bar');

    $vA = seedDedupeVariant($a);
    $vB = seedDedupeVariant($b);

    $rawA = seedDedupeRawOffer($store, 'aaaaaaaaaaaa');
    $rawB = seedDedupeRawOffer($store, 'bbbbbbbbbbbb');

    seedDedupeSnapshot($vA, $store, '2026-04-27', 1.0, $rawA);
    seedDedupeSnapshot($vB, $store, '2026-04-27', 2.0, $rawB);

    Artisan::call('products:dedupe', ['--by' => 'content-hash']);

    expect(DB::table('normalized_products')->count())->toBe(2);
});

it('rejects an unknown --by strategy', function () {
    $exit = Artisan::call('products:dedupe', ['--by' => 'nonsense']);

    expect($exit)->not->toBe(0);
});

it('--dry-run reports clusters but persists nothing', function () {
    relaxIdentityKey();
    $keeper = seedDedupeNp('Sūris', 'Mlekovita');
    $loser = seedDedupeNp('Sūris', 'MLEKOVITA');
    seedDedupeVariant($keeper);
    seedDedupeVariant($loser);

    Artisan::call('products:dedupe', ['--dry-run' => true]);

    expect(DB::table('normalized_products')->count())->toBe(2);
});
