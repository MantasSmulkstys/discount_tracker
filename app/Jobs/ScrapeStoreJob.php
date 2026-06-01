<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Shells out to the Python scraper for one store.
 *
 * `$tries = 1` is intentional: scrapes are weekly and a transient
 * fetcher failure should not double up on the upstream site. The
 * weekly cron picks it up on the next firing.
 */
class ScrapeStoreJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $storeId) {}

    public function handle(): void
    {
        $store = Store::find($this->storeId);
        if (! $store) {
            Log::warning('ScrapeStoreJob skipped: store missing', ['store_id' => $this->storeId]);

            return;
        }

        $python = (string) config('scraper.python');
        $path = (string) config('scraper.path');
        $timeout = (int) config('scraper.timeout');

        Log::info('ScrapeStoreJob start', ['store' => $store->slug]);

        $result = Process::path($path)
            ->timeout($timeout)
            ->run([$python, '-m', 'scripts.scrape_one_store', '--store', $store->slug]);

        if ($result->failed()) {
            Log::error('ScrapeStoreJob failed', [
                'store' => $store->slug,
                'exit' => $result->exitCode(),
                'stderr' => $result->errorOutput(),
            ]);

            throw new \RuntimeException(
                "Scraper for {$store->slug} exited {$result->exitCode()}"
            );
        }

        Log::info('ScrapeStoreJob done', ['store' => $store->slug]);
    }
}
