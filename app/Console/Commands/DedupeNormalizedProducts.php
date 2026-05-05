<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Merges duplicate `normalized_products` rows.
 *
 * Two cluster strategies, selected via `--by`:
 *
 *  - `brand-case` (default): rows that differ only in brand casing or
 *    NULL-vs-filled brand. Cluster key is
 *    `(LOWER(TRIM(normalized_name)), LOWER(TRIM(COALESCE(brand,''))), category_id, subcategory_id)`.
 *
 *  - `content-hash`: rows reached by `raw_offers` that share the same
 *    `(store_id, content_hash)`. Catches AI-driftation across rescrapes
 *    where the raw scraped input was byte-identical but the AI emitted a
 *    different canonical/normalized name (e.g. expanded a description or
 *    dropped a word).
 *
 * The lowest-id row in the cluster is kept. Variants from losers are re-pointed
 * to the keeper, merging on the `product_variants` shape key
 * (size_value, size_unit, package_count, package_unit) and re-routing
 * `offer_snapshots`, `current_offers`, and `price_changes` along the way to
 * respect their unique constraints. Snapshots, current prices, and raw_offers
 * stay intact — only redundant `normalized_products` and clashing variant
 * rows get removed.
 *
 *     php artisan products:dedupe --dry-run                  # preview
 *     php artisan products:dedupe                            # apply (brand-case)
 *     php artisan products:dedupe --by=content-hash --dry-run
 *     php artisan products:dedupe --by=content-hash          # apply
 */
#[Signature('products:dedupe {--dry-run : Print the plan without changing data} {--by=brand-case : Cluster strategy — brand-case or content-hash}')]
#[Description('Merge duplicate normalized_products into a single row per cluster.')]
class DedupeNormalizedProducts extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $strategy = (string) $this->option('by');

        if (! in_array($strategy, ['brand-case', 'content-hash'], true)) {
            $this->error("Unknown --by strategy: {$strategy}. Allowed: brand-case, content-hash.");

            return self::FAILURE;
        }

        $this->info("Strategy: {$strategy}");

        $clusters = $strategy === 'content-hash'
            ? $this->loadContentHashClusters()
            : $this->loadDuplicateClusters();
        if ($clusters->isEmpty()) {
            $this->info('No duplicate normalized_products clusters found.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d duplicate clusters covering %d extra rows.',
            $clusters->count(),
            $clusters->sum(fn (array $ids): int => count($ids) - 1),
        ));

        if ($dryRun) {
            $this->line('--dry-run: no changes will be persisted.');
        }

        $stats = ['clusters' => 0, 'losers_removed' => 0, 'variants_merged' => 0, 'variants_moved' => 0];

        DB::beginTransaction();
        try {
            foreach ($clusters as $key => $ids) {
                sort($ids);
                $keeperId = $ids[0];
                $losers = array_slice($ids, 1);

                $this->line(sprintf(' %s', $key));
                $this->line(sprintf('   keeper=%d losers=%s', $keeperId, implode(',', $losers)));

                foreach ($losers as $loserId) {
                    $merged = $this->mergeLoser($keeperId, $loserId, $dryRun);
                    $stats['variants_merged'] += $merged['merged'];
                    $stats['variants_moved'] += $merged['moved'];
                    $stats['losers_removed']++;
                }

                $stats['clusters']++;
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->newLine();
        $this->info(sprintf(
            'Clusters processed: %d · losers removed: %d · variants merged: %d · variants moved: %d',
            $stats['clusters'],
            $stats['losers_removed'],
            $stats['variants_merged'],
            $stats['variants_moved'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<string, array<int, int>>
     */
    private function loadDuplicateClusters(): Collection
    {
        $rows = DB::table('normalized_products')
            ->selectRaw("
                LOWER(TRIM(normalized_name)) AS k_name,
                LOWER(TRIM(COALESCE(brand,''))) AS k_brand,
                COALESCE(CAST(category_id AS TEXT),'') AS k_cat,
                COALESCE(CAST(subcategory_id AS TEXT),'') AS k_sub,
                id
            ")
            ->orderBy('id')
            ->get();

        return $rows
            ->groupBy(fn ($row): string => "{$row->k_name}|{$row->k_brand}|{$row->k_cat}|{$row->k_sub}")
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->map(fn (Collection $group): array => $group->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * Cluster normalized_products by `(store_id, content_hash)` reached
     * through raw_offers. Two normalized_products that should logically be
     * one — because raws with byte-identical scraped content map to both —
     * end up grouped together.
     *
     * @return Collection<string, array<int, int>>
     */
    private function loadContentHashClusters(): Collection
    {
        $rows = DB::table('raw_offers')
            ->join('offer_snapshots', 'offer_snapshots.raw_offer_id', '=', 'raw_offers.id')
            ->join('product_variants', 'product_variants.id', '=', 'offer_snapshots.product_variant_id')
            ->whereNotNull('raw_offers.content_hash')
            ->where('raw_offers.normalization_status', 'done')
            ->select([
                'raw_offers.store_id',
                'raw_offers.content_hash',
                'product_variants.normalized_product_id as np_id',
            ])
            ->distinct()
            ->get();

        return $rows
            ->groupBy(fn ($row): string => "{$row->store_id}|{$row->content_hash}")
            ->filter(fn (Collection $group): bool => $group->pluck('np_id')->unique()->count() > 1)
            ->map(fn (Collection $group): array => $group
                ->pluck('np_id')
                ->unique()
                ->sort()
                ->values()
                ->map(fn ($id): int => (int) $id)
                ->all()
            );
    }

    /**
     * @return array{merged: int, moved: int}
     */
    private function mergeLoser(int $keeperId, int $loserId, bool $dryRun): array
    {
        $keeperVariants = DB::table('product_variants')
            ->where('normalized_product_id', $keeperId)
            ->get()
            ->keyBy(fn ($v): string => $this->variantShape($v));

        $loserVariants = DB::table('product_variants')
            ->where('normalized_product_id', $loserId)
            ->get();

        $stats = ['merged' => 0, 'moved' => 0];

        foreach ($loserVariants as $loserVariant) {
            $shape = $this->variantShape($loserVariant);

            if ($keeperVariants->has($shape)) {
                $keeperVariant = $keeperVariants->get($shape);
                $this->line(sprintf('     merge variant %d → %d (shape=%s)', $loserVariant->id, $keeperVariant->id, $shape));
                if (! $dryRun) {
                    $this->repointVariant((int) $loserVariant->id, (int) $keeperVariant->id);
                    DB::table('product_variants')->where('id', $loserVariant->id)->delete();
                }
                $stats['merged']++;
            } else {
                $this->line(sprintf('     move variant %d → np %d (shape=%s)', $loserVariant->id, $keeperId, $shape));
                if (! $dryRun) {
                    DB::table('product_variants')
                        ->where('id', $loserVariant->id)
                        ->update(['normalized_product_id' => $keeperId, 'updated_at' => now()]);
                }
                $stats['moved']++;
            }
        }

        if (! $dryRun) {
            DB::table('normalized_products')->where('id', $loserId)->delete();
        }

        return $stats;
    }

    /**
     * Re-point every reference from `loserVariantId` to `keeperVariantId`,
     * respecting the unique/PK constraints on `current_offers` and
     * `offer_snapshots`. When the keeper already has a row for the same key,
     * the more recent observation wins for `current_offers`; older snapshots
     * are dropped (we keep the keeper's, which is at least as fresh).
     */
    private function repointVariant(int $loserVariantId, int $keeperVariantId): void
    {
        $loserCurrentOffers = DB::table('current_offers')
            ->where('product_variant_id', $loserVariantId)
            ->get();

        foreach ($loserCurrentOffers as $row) {
            $existing = DB::table('current_offers')
                ->where('store_id', $row->store_id)
                ->where('product_variant_id', $keeperVariantId)
                ->first();

            if ($existing) {
                if ($row->last_seen_at > $existing->last_seen_at) {
                    DB::table('current_offers')
                        ->where('store_id', $row->store_id)
                        ->where('product_variant_id', $keeperVariantId)
                        ->update([
                            'price' => $row->price,
                            'old_price' => $row->old_price,
                            'discount_percent' => $row->discount_percent,
                            'condition' => $row->condition,
                            'is_promotion' => $row->is_promotion,
                            'loyalty_card' => $row->loyalty_card,
                            'unit_price_value' => $row->unit_price_value,
                            'unit_price_unit' => $row->unit_price_unit,
                            'valid_from' => $row->valid_from,
                            'valid_to' => $row->valid_to,
                            'last_seen_at' => $row->last_seen_at,
                            'week_starting' => $row->week_starting,
                            'last_snapshot_id' => $row->last_snapshot_id,
                            'updated_at' => now(),
                        ]);
                }
                DB::table('current_offers')
                    ->where('store_id', $row->store_id)
                    ->where('product_variant_id', $loserVariantId)
                    ->delete();
            } else {
                DB::table('current_offers')
                    ->where('store_id', $row->store_id)
                    ->where('product_variant_id', $loserVariantId)
                    ->update(['product_variant_id' => $keeperVariantId, 'updated_at' => now()]);
            }
        }

        $loserSnapshots = DB::table('offer_snapshots')
            ->where('product_variant_id', $loserVariantId)
            ->get(['id', 'store_id', 'week_starting']);

        foreach ($loserSnapshots as $snap) {
            $clash = DB::table('offer_snapshots')
                ->where('product_variant_id', $keeperVariantId)
                ->where('store_id', $snap->store_id)
                ->where('week_starting', $snap->week_starting)
                ->exists();

            if ($clash) {
                DB::table('offer_snapshots')->where('id', $snap->id)->delete();
            } else {
                DB::table('offer_snapshots')->where('id', $snap->id)->update([
                    'product_variant_id' => $keeperVariantId,
                ]);
            }
        }

        DB::table('price_changes')
            ->where('product_variant_id', $loserVariantId)
            ->update(['product_variant_id' => $keeperVariantId]);
    }

    private function variantShape(object $variant): string
    {
        return implode('|', [
            $variant->size_value !== null ? (string) $variant->size_value : '',
            $variant->size_unit ?? '',
            $variant->package_count !== null ? (string) $variant->package_count : '',
            $variant->package_unit ?? '',
        ]);
    }
}
