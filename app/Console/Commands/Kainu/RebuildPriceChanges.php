<?php

declare(strict_types=1);

namespace App\Console\Commands\Kainu;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('kainu:rebuild-price-changes')]
#[Description('Rebuild price_changes from offer_snapshots by matching the exact raw card name within each store, comparing the actual displayed price week over week.')]
class RebuildPriceChanges extends Command
{
    public function handle(): int
    {
        $inserted = 0;

        DB::transaction(function () use (&$inserted): void {
            DB::table('price_changes')->delete();

            /**
             * Per (store + raw_name) series: the previous week and its price.
             *
             * @var array<string, array{week: string, price: float}> $previous
             */
            $previous = [];
            $buffer = [];

            DB::table('offer_snapshots')
                ->join('raw_offers', 'raw_offers.id', '=', 'offer_snapshots.raw_offer_id')
                ->whereNotNull('offer_snapshots.price')
                ->orderBy('offer_snapshots.store_id')
                ->orderBy('raw_offers.name')
                ->orderBy('offer_snapshots.week_starting')
                ->orderBy('offer_snapshots.id')
                ->select([
                    'offer_snapshots.id',
                    'offer_snapshots.store_id',
                    'offer_snapshots.product_variant_id',
                    'offer_snapshots.week_starting',
                    'offer_snapshots.scraped_at',
                    'offer_snapshots.price',
                    'raw_offers.name as raw_name',
                ])
                ->chunk(1000, function ($snapshots) use (&$previous, &$buffer, &$inserted): void {
                    foreach ($snapshots as $snapshot) {
                        $key = $snapshot->store_id.'|'.$snapshot->raw_name;
                        $price = (float) $snapshot->price;
                        $prior = $previous[$key] ?? null;

                        // Same week as the last record for this card (a parallel
                        // variant) — not a week-over-week move; keep the first.
                        if ($prior !== null && $snapshot->week_starting === $prior['week']) {
                            continue;
                        }

                        if ($prior !== null) {
                            $changePct = $this->changePct($prior['price'], $price);
                            if ($changePct !== null) {
                                $buffer[] = [
                                    'product_variant_id' => $snapshot->product_variant_id,
                                    'store_id' => $snapshot->store_id,
                                    'previous_price' => $prior['price'],
                                    'new_price' => $price,
                                    'change_pct' => $changePct,
                                    'week_starting' => $snapshot->week_starting,
                                    'changed_at' => $snapshot->scraped_at,
                                    'snapshot_id' => $snapshot->id,
                                ];
                                $inserted++;

                                if (count($buffer) >= 500) {
                                    DB::table('price_changes')->insert($buffer);
                                    $buffer = [];
                                }
                            }
                        }

                        $previous[$key] = ['week' => $snapshot->week_starting, 'price' => $price];
                    }
                });

            if ($buffer !== []) {
                DB::table('price_changes')->insert($buffer);
            }
        });

        $this->info("Rebuilt price_changes: {$inserted} change(s) recorded.");

        return self::SUCCESS;
    }

    private function changePct(float $previousPrice, float $newPrice): ?float
    {
        if ($previousPrice == 0.0 || abs($newPrice - $previousPrice) < 1e-9) {
            return null;
        }

        return round(($newPrice - $previousPrice) / $previousPrice * 100, 2);
    }
}
