<?php

use App\Jobs\ExpiryGuardJob;
use App\Jobs\NormalizePendingJob;
use App\Jobs\ScrapeStoreJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

function seedJobsStore(string $slug, bool $active = true): int
{
    return DB::table('stores')->insertGetId([
        'name' => $slug,
        'slug' => $slug,
        'is_active' => $active,
        'scrape_cron' => '0 6 * * 1',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('ScrapeStoreJob runs the python subprocess with the store slug', function () {
    Process::fake();

    $storeId = seedJobsStore('iki');

    (new ScrapeStoreJob($storeId))->handle();

    Process::assertRan(function ($process) {
        return is_array($process->command)
            && in_array('--store', $process->command, true)
            && in_array('iki', $process->command, true);
    });
});

test('ScrapeStoreJob is a no-op when the store has been deleted', function () {
    Process::fake();

    (new ScrapeStoreJob(99999))->handle();

    Process::assertNothingRan();
});

test('ScrapeStoreJob fails when the python subprocess exits non-zero', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
    ]);

    $storeId = seedJobsStore('iki');

    expect(fn () => (new ScrapeStoreJob($storeId))->handle())
        ->toThrow(RuntimeException::class);
});

test('NormalizePendingJob invokes the normalization script', function () {
    Process::fake();

    (new NormalizePendingJob)->handle();

    Process::assertRan(function ($process) {
        return is_array($process->command)
            && in_array('scripts.process_raw_offers_normalized', $process->command, true);
    });
});

test('ExpiryGuardJob queues a refresh chain for stores whose snapshots are expiring', function () {
    Bus::fake();

    $expiringId = seedJobsStore('iki');
    $freshId = seedJobsStore('maxima');

    DB::table('scrape_batches')->insert([
        'id' => 1,
        'store_id' => $expiringId,
        'source_name' => 'iki',
        'status' => 'completed',
        'started_at' => now()->subDay(),
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);
    DB::table('scrape_batches')->insert([
        'id' => 2,
        'store_id' => $freshId,
        'source_name' => 'maxima',
        'status' => 'completed',
        'started_at' => now()->subDay(),
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    snapshotFor($expiringId, validTo: now()->subDay()->toDateString(), batchId: 1);
    snapshotFor($freshId, validTo: now()->addDays(10)->toDateString(), batchId: 2);

    (new ExpiryGuardJob)->handle();

    Bus::assertChained([
        ScrapeStoreJob::class,
        NormalizePendingJob::class,
    ]);
});

test('ExpiryGuardJob does nothing when every store is fresh', function () {
    Bus::fake();

    $storeId = seedJobsStore('iki');

    DB::table('scrape_batches')->insert([
        'id' => 1,
        'store_id' => $storeId,
        'source_name' => 'iki',
        'status' => 'completed',
        'started_at' => now()->subHours(1),
        'created_at' => now()->subHours(1),
        'updated_at' => now()->subHours(1),
    ]);

    snapshotFor($storeId, validTo: now()->addDays(5)->toDateString(), batchId: 1);

    (new ExpiryGuardJob)->handle();

    Bus::assertNothingDispatched();
});

function snapshotFor(int $storeId, string $validTo, int $batchId): void
{
    $variantId = DB::table('product_variants')->insertGetId([
        'normalized_product_id' => DB::table('normalized_products')->insertGetId([
            'canonical_name' => 'Test product '.$storeId,
            'normalized_name' => 'test_'.$storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        'variant_name' => 'default',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('offer_snapshots')->insert([
        'product_variant_id' => $variantId,
        'store_id' => $storeId,
        'scrape_batch_id' => $batchId,
        'price' => 1.99,
        'is_promotion' => false,
        'loyalty_card' => false,
        'valid_to' => $validTo,
        'week_starting' => now()->startOfWeek()->toDateString(),
        'scraped_at' => now()->subHour(),
        'created_at' => now()->subHour(),
    ]);
}
