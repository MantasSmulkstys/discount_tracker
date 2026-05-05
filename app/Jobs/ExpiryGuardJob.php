<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily safety net: for every active store, if the freshest snapshot's
 * `valid_to` is expiring tomorrow or already past, schedule a fresh scrape +
 * normalization.
 *
 * This catches stores that shifted their leaflet day without us noticing —
 * the per-store cron handles the common case, this fills the gap.
 */
class ExpiryGuardJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        $threshold = now()->addDay()->toDateString();
        $expiring = [];

        Store::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->each(function (Store $store) use ($threshold, &$expiring): void {
                $maxValidTo = DB::table('offer_snapshots')
                    ->where('store_id', $store->id)
                    ->max('valid_to');

                if ($maxValidTo === null || $maxValidTo < $threshold) {
                    $expiring[] = $store;
                }
            });

        if (empty($expiring)) {
            Log::info('ExpiryGuardJob: nothing to refresh');

            return;
        }

        $chain = array_map(
            fn (Store $s): ScrapeStoreJob => new ScrapeStoreJob($s->id),
            $expiring,
        );
        $chain[] = new NormalizePendingJob;

        Bus::chain($chain)->dispatch();

        Log::info('ExpiryGuardJob queued refresh', [
            'stores' => array_map(fn (Store $s): string => $s->slug, $expiring),
        ]);
    }
}
