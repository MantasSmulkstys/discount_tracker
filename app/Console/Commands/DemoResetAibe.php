<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Store;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('demo:reset-aibe')]
#[Description('Wipe Aibė store + config so the import-flow demo starts from scratch.')]
class DemoResetAibe extends Command
{
    public function handle(): int
    {
        $slug = 'aibe';

        $store = Store::where('slug', $slug)->first();
        if ($store) {
            $store->delete();
            $this->info("Deleted store row (slug=$slug) — current_offers + offer_snapshots cascaded out.");
        } else {
            $this->line("No store row with slug=$slug — already clean.");
        }

        $configPath = rtrim((string) config('scraper.path'), '/\\').DIRECTORY_SEPARATOR.'configs'.DIRECTORY_SEPARATOR.$slug.'.json';
        if (File::exists($configPath)) {
            File::delete($configPath);
            $this->info("Deleted config: $configPath");
        } else {
            $this->line("No config file at $configPath — already clean.");
        }

        $this->newLine();
        $this->info('Demo state ready. Visit /admin/scrape and use "Importuoti parduotuvę" with your aibe.json to bring it back.');

        return self::SUCCESS;
    }
}
