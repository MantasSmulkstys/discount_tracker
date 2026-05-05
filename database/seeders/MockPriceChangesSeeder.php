<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo-only seeder: synthesises `price_changes` rows from existing
 * `current_offers` so the Statistics page has something to display before
 * the second weekly scrape lands.
 *
 * Reads up to 30 priced current_offers, fabricates a "previous price" 8–35%
 * higher than the real one, and writes a synthetic price_changes row dated
 * to the current Monday. Run AFTER `python main.py` finishes a real scrape
 * so it has something to base the mocks on.
 *
 * Usage:
 *
 *   php artisan db:seed --class=MockPriceChangesSeeder
 *
 * Re-running wipes prior mocks first (we identify them via `previous_price`
 * being a synthetic round-up — not perfect, but adequate for a demo).
 *
 * Once the second real weekly scrape lands and populates the table for real,
 * stop running this and let the real data take over.
 */
class MockPriceChangesSeeder extends Seeder
{
    private const TARGET_ROWS = 25;

    public function run(): void
    {
        // Wipe any previous mock rows so re-runs don't accumulate. We don't
        // try to discriminate real vs. mock rows — by design this seeder only
        // makes sense before real data exists.
        DB::table('price_changes')->delete();

        $offers = DB::table('current_offers')
            ->whereNotNull('current_offers.price')
            ->where('current_offers.price', '>', 0)
            ->whereNotNull('current_offers.last_snapshot_id')
            ->inRandomOrder()
            ->limit(self::TARGET_ROWS)
            ->get([
                'current_offers.product_variant_id',
                'current_offers.store_id',
                'current_offers.price',
                'current_offers.week_starting',
                'current_offers.last_snapshot_id',
            ]);

        if ($offers->isEmpty()) {
            $this->command?->warn(
                'No priced current_offers rows to mock against. Run `python main.py` first.'
            );

            return;
        }

        $now = Carbon::now();
        $rows = [];

        foreach ($offers as $offer) {
            // Synthesise a previous price 8–35% higher than the current one.
            // The wider band gives a satisfying spread on the page.
            $bumpPct = mt_rand(800, 3500) / 100;
            $previous = round((float) $offer->price * (1 + $bumpPct / 100), 2);
            $changePct = round(((float) $offer->price - $previous) / $previous * 100, 2);

            $rows[] = [
                'product_variant_id' => $offer->product_variant_id,
                'store_id' => $offer->store_id,
                'previous_price' => $previous,
                'new_price' => $offer->price,
                'change_pct' => $changePct,
                'week_starting' => $offer->week_starting,
                'changed_at' => $now,
                'snapshot_id' => $offer->last_snapshot_id,
            ];
        }

        DB::table('price_changes')->insert($rows);

        $this->command?->info(
            sprintf('Inserted %d mock price_changes rows for the Statistics page demo.', count($rows))
        );
    }
}
