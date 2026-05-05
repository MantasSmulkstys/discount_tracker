<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Backdates `valid_to` on stale `offer_snapshots` (and parallel `current_offers`)
 * rows whose `valid_to` is NULL and whose `scraped_at` predates today.
 *
 * Use case: a store config that previously couldn't parse its single-end-date
 * format (e.g. Maxima's "Iki MM.DD") wrote NULL into `valid_to`. Once the
 * parser is fixed, future scrapes get the right date — but rows already in the
 * DB stay NULL until they age out of the 14-day freshness window. This command
 * expires them immediately so the public listing stops showing last week's
 * leaflet.
 *
 * Defaults to a dry run; pass --apply (or answer the prompt) to mutate data.
 *
 *     php artisan offers:expire-stale-null-valid-to                   # dry run
 *     php artisan offers:expire-stale-null-valid-to --store=maxima    # filter
 *     php artisan offers:expire-stale-null-valid-to --apply           # mutate
 */
#[Signature('offers:expire-stale-null-valid-to {--store= : Limit to a single store slug} {--apply : Persist the change (default is dry-run)}')]
#[Description('Backdate valid_to on stale snapshots whose flyer date never got parsed.')]
class ExpireStaleNullValidTo extends Command
{
    public function handle(): int
    {
        $storeSlug = $this->option('store');
        $apply = (bool) $this->option('apply');

        $storeId = null;
        if ($storeSlug !== null) {
            $storeId = DB::table('stores')->where('slug', $storeSlug)->value('id');
            if ($storeId === null) {
                $this->error("No store with slug '{$storeSlug}'.");

                return self::FAILURE;
            }
        }

        $cutoff = now()->startOfDay()->toDateTimeString();
        $newValidTo = now()->subDay()->toDateString();

        $snapshotQuery = fn () => DB::table('offer_snapshots')
            ->whereNull('valid_to')
            ->where('scraped_at', '<', $cutoff)
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId));

        $currentQuery = fn () => DB::table('current_offers')
            ->whereNull('valid_to')
            ->where('last_seen_at', '<', $cutoff)
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId));

        $snapshotCount = $snapshotQuery()->count();
        $currentCount = $currentQuery()->count();

        $this->info(sprintf(
            'Scope: %s · cutoff: scraped_at < %s · new valid_to = %s',
            $storeSlug ?? 'all stores',
            $cutoff,
            $newValidTo,
        ));
        $this->info(sprintf(
            'Would update %d offer_snapshots row(s) and %d current_offers row(s).',
            $snapshotCount,
            $currentCount,
        ));

        if ($snapshotCount === 0 && $currentCount === 0) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        $this->showSample($snapshotQuery());

        if (! $apply) {
            $this->line('--apply not set: this was a dry run, no changes persisted.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Apply backdate to {$snapshotCount} snapshot(s) and {$currentCount} current_offer(s)?", false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        DB::beginTransaction();
        try {
            // offer_snapshots is append-only — only `created_at` exists, no `updated_at`.
            $snapshotsUpdated = $snapshotQuery()->update([
                'valid_to' => $newValidTo,
            ]);

            $currentUpdated = $currentQuery()->update([
                'valid_to' => $newValidTo,
                'updated_at' => now(),
            ]);

            DB::commit();

            $this->info(sprintf(
                'Updated %d offer_snapshots row(s), %d current_offers row(s).',
                $snapshotsUpdated,
                $currentUpdated,
            ));
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return self::SUCCESS;
    }

    /**
     * Show up to 5 sample rows so the operator can sanity-check what's about
     * to change before they pass --apply.
     */
    private function showSample(Builder $query): void
    {
        $sample = (clone $query)
            ->join('stores', 'stores.id', '=', 'offer_snapshots.store_id')
            ->join('product_variants', 'product_variants.id', '=', 'offer_snapshots.product_variant_id')
            ->join('normalized_products', 'normalized_products.id', '=', 'product_variants.normalized_product_id')
            ->orderBy('offer_snapshots.id')
            ->limit(5)
            ->get([
                'offer_snapshots.id',
                'stores.slug as store',
                'normalized_products.canonical_name as name',
                'offer_snapshots.price',
                'offer_snapshots.scraped_at',
            ]);

        if ($sample->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('Sample rows that would be backdated:');
        foreach ($sample as $row) {
            $this->line(sprintf(
                '  #%d [%s] %s · %s · scraped %s',
                $row->id,
                $row->store,
                $row->name,
                $row->price ?? '(no price)',
                $row->scraped_at,
            ));
        }
        $this->newLine();
    }
}
