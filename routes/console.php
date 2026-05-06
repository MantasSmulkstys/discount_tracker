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
}

Schedule::job(new ExpiryGuardJob)->dailyAt('05:00')->name('expiry-guard');
