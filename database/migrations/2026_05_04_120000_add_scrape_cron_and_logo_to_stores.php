<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->string('scrape_cron')->nullable()->after('is_active');
            $table->string('logo_path')->nullable()->after('scrape_cron');
        });

        $defaults = [
            'iki' => ['cron' => '0 6 * * 1', 'logo' => '/storage/store-logos/iki.png'],
            'maxima' => ['cron' => '0 6 * * 4', 'logo' => '/storage/store-logos/maxima.png'],
            'lidl' => ['cron' => '0 6 * * 1,4', 'logo' => '/storage/store-logos/lidl.png'],
            'rimi' => ['cron' => '0 6 * * 1,4', 'logo' => '/storage/store-logos/rimi.png'],
            'norfa' => ['cron' => '0 6 * * 4', 'logo' => '/storage/store-logos/norfa.png'],
            'aibe' => ['cron' => '0 6 * * 1', 'logo' => '/storage/store-logos/aibe.png'],
        ];

        foreach ($defaults as $slug => $values) {
            DB::table('stores')
                ->where('slug', $slug)
                ->update([
                    'scrape_cron' => $values['cron'],
                    'logo_path' => $values['logo'],
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['scrape_cron', 'logo_path']);
        });
    }
};
