<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('products:dedupe-variants {--dry-run : Print the plan without changing data}')]
#[Description('Merge duplicate product_variants left by the variant_code backfill — one raw_offer that landed on two variants of the same product.')]
class DedupeVariantSnapshots extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // A raw_offer is one physical listing in one week → it should own exactly
        // one snapshot. More than one means the reset+re-normalize backfill
        // created a parallel (coded) variant beside the old (NULL-code) one.
        $pairs = $this->loserToKeeperPairs();

        if ($pairs->isEmpty()) {
            $this->info('No duplicate variants found.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d variant(s) to merge into their coded twin.', $pairs->count()));
        if ($dryRun) {
            $this->line('--dry-run: no changes will be persisted.');
        }

        $stats = ['merged' => 0, 'snapshots_moved' => 0, 'snapshots_deleted' => 0, 'variants_deleted' => 0];

        DB::beginTransaction();
        try {
            foreach ($pairs as $loserId => $keeperId) {
                $this->line(sprintf(' merge variant %d → %d', $loserId, $keeperId));
                $moved = $this->mergeVariant((int) $loserId, (int) $keeperId, $dryRun);
                $stats['snapshots_moved'] += $moved['moved'];
                $stats['snapshots_deleted'] += $moved['deleted'];
                $stats['variants_deleted']++;
                $stats['merged']++;
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
            'Variants merged: %d · snapshots moved: %d · snapshots deleted (week clash): %d · variants deleted: %d',
            $stats['merged'],
            $stats['snapshots_moved'],
            $stats['snapshots_deleted'],
            $stats['variants_deleted'],
        ));

        return self::SUCCESS;
    }

    /**
     * Build a loser_variant → keeper_variant map from raw_offers that own more
     * than one snapshot. The keeper is the coded variant (the correct post-fix
     * one); the losers are the others (the stale NULL-code variants).
     *
     * @return Collection<int, int>
     */
    private function loserToKeeperPairs(): Collection
    {
        $multiRawOfferIds = DB::table('offer_snapshots')
            ->whereNotNull('raw_offer_id')
            ->groupBy('raw_offer_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('raw_offer_id');

        if ($multiRawOfferIds->isEmpty()) {
            return collect();
        }

        $rows = DB::table('offer_snapshots as os')
            ->join('product_variants as pv', 'pv.id', '=', 'os.product_variant_id')
            ->whereIn('os.raw_offer_id', $multiRawOfferIds)
            ->select(['os.id as snap_id', 'os.raw_offer_id', 'os.product_variant_id as variant_id', 'pv.variant_code'])
            ->get();

        $pairs = collect();

        foreach ($rows->groupBy('raw_offer_id') as $group) {
            // Keeper: a coded variant if present, else the newest snapshot's
            // variant. Coded wins because that is what the fix intends to keep.
            $keeper = $group->first(fn ($r): bool => $r->variant_code !== null)
                ?? $group->sortByDesc('snap_id')->first();
            $keeperVariantId = (int) $keeper->variant_id;

            foreach ($group as $row) {
                $variantId = (int) $row->variant_id;
                if ($variantId === $keeperVariantId) {
                    continue;
                }
                // First keeper wins if a loser somehow appears under two raw
                // offers — never overwrite an existing mapping.
                if (! $pairs->has($variantId)) {
                    $pairs->put($variantId, $keeperVariantId);
                }
            }
        }

        // Guard against chains (a variant marked both loser and keeper).
        return $pairs->reject(fn (int $keeperId, int $loserId): bool => $pairs->has($keeperId));
    }

    /**
     * Move the loser variant's snapshots / current_offers / price_changes onto
     * the keeper variant, then delete the loser. Mirrors
     * DedupeNormalizedProducts::repointVariant.
     *
     * @return array{moved: int, deleted: int}
     */
    private function mergeVariant(int $loserVariantId, int $keeperVariantId, bool $dryRun): array
    {
        $stats = ['moved' => 0, 'deleted' => 0];

        $loserCurrentOffers = DB::table('current_offers')->where('product_variant_id', $loserVariantId)->get();
        foreach ($loserCurrentOffers as $row) {
            $existing = DB::table('current_offers')
                ->where('store_id', $row->store_id)
                ->where('product_variant_id', $keeperVariantId)
                ->first();

            if ($existing) {
                if ($row->last_seen_at > $existing->last_seen_at && ! $dryRun) {
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
                if (! $dryRun) {
                    DB::table('current_offers')
                        ->where('store_id', $row->store_id)
                        ->where('product_variant_id', $loserVariantId)
                        ->delete();
                }
            } elseif (! $dryRun) {
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
                if (! $dryRun) {
                    DB::table('offer_snapshots')->where('id', $snap->id)->delete();
                }
                $stats['deleted']++;
            } else {
                if (! $dryRun) {
                    DB::table('offer_snapshots')->where('id', $snap->id)->update(['product_variant_id' => $keeperVariantId]);
                }
                $stats['moved']++;
            }
        }

        if (! $dryRun) {
            DB::table('price_changes')->where('product_variant_id', $loserVariantId)->update(['product_variant_id' => $keeperVariantId]);
            DB::table('product_variants')->where('id', $loserVariantId)->delete();
        }

        return $stats;
    }
}
