<?php

declare(strict_types=1);

namespace App\Console\Commands\Kainu;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Signature('kainu:seed-demo-price-changes {--count=10 : How many products to seed} {--fresh : Remove previously seeded demo data and re-seed}')]
#[Description('Insert fake follow-up offer_snapshots + price_changes so the UI demonstrates week-over-week price changes.')]
class SeedDemoPriceChanges extends Command
{
    private const SOURCE_NAME = 'demo:price-changes';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->cleanup();
        }

        $count = max(1, (int) $this->option('count'));

        $hasAnyRealSnapshots = DB::table('offer_snapshots')
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->exists();

        if (! $hasAnyRealSnapshots) {
            $this->warn('No offer_snapshots found to base demo data on. Run a real scrape first.');

            return self::FAILURE;
        }

        $batchId = $this->ensureBatch();

        $candidates = DB::table('offer_snapshots')
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->where(function ($q) use ($batchId): void {
                $q->whereNull('scrape_batch_id')
                    ->orWhere('scrape_batch_id', '!=', $batchId);
            })
            ->whereNotExists(function ($q) use ($batchId): void {
                $q->select(DB::raw(1))
                    ->from('offer_snapshots as ds')
                    ->whereColumn('ds.product_variant_id', 'offer_snapshots.product_variant_id')
                    ->whereColumn('ds.store_id', 'offer_snapshots.store_id')
                    ->where('ds.scrape_batch_id', $batchId);
            })
            ->groupBy('product_variant_id', 'store_id')
            ->selectRaw('product_variant_id, store_id, MAX(id) as latest_id')
            ->inRandomOrder()
            ->limit($count * 3)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('All eligible products already have demo price changes. Run with --fresh to reset.');

            return self::SUCCESS;
        }

        $deltas = [-0.30, -0.20, -0.15, -0.10, 0.10, 0.15, 0.20, 0.30];
        $inserted = 0;

        foreach ($candidates as $candidate) {
            if ($inserted >= $count) {
                break;
            }

            $base = DB::table('offer_snapshots')->where('id', $candidate->latest_id)->first();
            if ($base === null || $base->price === null) {
                continue;
            }

            $newWeek = Carbon::parse($base->week_starting)->addWeek()->toDateString();

            $alreadyExists = DB::table('offer_snapshots')
                ->where('product_variant_id', $base->product_variant_id)
                ->where('store_id', $base->store_id)
                ->where('week_starting', $newWeek)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            $delta = $deltas[array_rand($deltas)];
            $oldPrice = (float) $base->price;
            $newPrice = max(0.05, round($oldPrice + $delta, 2));

            if (abs($newPrice - $oldPrice) < 0.01) {
                continue;
            }

            $changePct = round((($newPrice - $oldPrice) / $oldPrice) * 100, 2);
            $now = now();

            $snapshotId = DB::table('offer_snapshots')->insertGetId([
                'product_variant_id' => $base->product_variant_id,
                'store_id' => $base->store_id,
                'scrape_batch_id' => $batchId,
                'week_starting' => $newWeek,
                'price' => $newPrice,
                'old_price' => $oldPrice,
                'discount_percent' => null,
                'condition' => $base->condition,
                'loyalty_card' => $base->loyalty_card,
                'unit_price_value' => $base->unit_price_value,
                'unit_price_unit' => $base->unit_price_unit,
                'valid_from' => $newWeek,
                'valid_to' => null,
                'scraped_at' => $now,
                'is_promotion' => false,
                'created_at' => $now,
            ]);

            DB::table('price_changes')->insert([
                'product_variant_id' => $base->product_variant_id,
                'store_id' => $base->store_id,
                'previous_price' => $oldPrice,
                'new_price' => $newPrice,
                'change_pct' => $changePct,
                'week_starting' => $newWeek,
                'changed_at' => $now,
                'snapshot_id' => $snapshotId,
            ]);

            $inserted++;
        }

        $this->info("Inserted {$inserted} demo offer_snapshots + price_changes.");
        $this->line('Run with --fresh to wipe and re-seed.');

        return self::SUCCESS;
    }

    private function ensureBatch(): int
    {
        $existing = DB::table('scrape_batches')
            ->where('source_name', self::SOURCE_NAME)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('scrape_batches')->insertGetId([
            'store_id' => null,
            'source_name' => self::SOURCE_NAME,
            'notes' => 'Synthetic batch used by kainu:seed-demo-price-changes.',
            'week_starting' => null,
            'started_at' => now(),
            'finished_at' => now(),
            'status' => 'completed',
            'offers_found' => 0,
            'offers_inserted' => 0,
            'offers_updated' => 0,
            'offers_failed' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function cleanup(): void
    {
        $batchId = DB::table('scrape_batches')
            ->where('source_name', self::SOURCE_NAME)
            ->value('id');

        if ($batchId === null) {
            $this->line('No previous demo batch to remove.');

            return;
        }

        $snapshotIds = DB::table('offer_snapshots')
            ->where('scrape_batch_id', $batchId)
            ->pluck('id')
            ->all();

        if ($snapshotIds !== []) {
            DB::table('price_changes')->whereIn('snapshot_id', $snapshotIds)->delete();
            DB::table('offer_snapshots')->whereIn('id', $snapshotIds)->delete();
        }

        DB::table('scrape_batches')->where('id', $batchId)->delete();

        $count = count($snapshotIds);
        $this->info("Removed {$count} previous demo snapshot(s).");
    }
}
