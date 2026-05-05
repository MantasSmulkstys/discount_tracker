<?php

use App\Jobs\ExpiryGuardJob;
use App\Jobs\NormalizePendingJob;
use App\Jobs\ScrapeStoreJob;
use App\Models\Store;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Per-store scheduled scrapes. Each store row carries its own cron expression
// so admins can tune the schedule from the UI without a code deploy. We chain
// normalization after the scrape so freshly-inserted raw_offers get processed
// in the same run.
//
// Wrapped defensively because this file is parsed every time artisan boots —
// during install, in CI, or when the DB driver is missing — and we don't want
// to break unrelated commands just because the stores table isn't reachable.
try {
    if (Schema::hasTable('stores') && Schema::hasColumn('stores', 'scrape_cron')) {
        Store::query()
            ->where('is_active', true)
            ->whereNotNull('scrape_cron')
            ->get()
            ->each(function (Store $store): void {
                Schedule::call(function () use ($store): void {
                    Bus::chain([
                        new ScrapeStoreJob($store->id),
                        new NormalizePendingJob,
                    ])->dispatch();
                })
                    ->cron($store->scrape_cron)
                    ->name("scrape-store-{$store->slug}")
                    ->withoutOverlapping();
            });
    }
} catch (Throwable) {
    // DB unreachable at boot — skip per-store scheduling, fall through.
}

// Daily safety net: refresh any store whose freshest snapshot is about to
// expire. Catches stores that shift their leaflet release day.
Schedule::job(new ExpiryGuardJob)->dailyAt('05:00')->name('expiry-guard');
