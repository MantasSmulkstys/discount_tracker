<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Runs the AI normalization script over all `raw_offers` whose
 * `normalization_status = 'pending'`.
 *
 * The Python script is idempotent — already-normalized rows are not touched
 * and don't consume new AI credits, so chaining this after every scrape is
 * safe even when the scrape produced no new variants.
 */
class NormalizePendingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        $python = (string) config('scraper.python');
        $path = (string) config('scraper.path');
        $timeout = (int) config('scraper.timeout');

        Log::info('NormalizePendingJob start');

        $result = Process::path($path)
            ->timeout($timeout)
            ->run([
                $python,
                '-m',
                'scripts.process_raw_offers_normalized',
                '--use-ai',
                '--batch-size',
                '50',
            ]);

        if ($result->failed()) {
            Log::error('NormalizePendingJob failed', [
                'exit' => $result->exitCode(),
                'stderr' => $result->errorOutput(),
            ]);

            throw new \RuntimeException(
                "Normalization exited {$result->exitCode()}"
            );
        }

        Log::info('NormalizePendingJob done');
    }
}
