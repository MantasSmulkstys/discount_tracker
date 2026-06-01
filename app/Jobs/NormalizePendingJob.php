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
 * Shells out to the Python normalization pipeline.
 *
 * `$tries = 1` is intentional: each run drains its own batch from
 * `raw_offers` and re-running for transient OpenAI failures would
 * double-bill credits. The script itself is idempotent — the next
 * scheduled run will pick up rows still marked pending.
 */
class NormalizePendingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    private const BATCH_SIZE = 25;

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
                (string) self::BATCH_SIZE,
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
