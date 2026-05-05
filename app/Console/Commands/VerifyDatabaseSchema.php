<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('db:verify-schema')]
#[Description('Verify that all required Discount Tracker database tables and columns exist')]
class VerifyDatabaseSchema extends Command
{
    private array $requiredTables = [
        'stores' => [
            'id',
            'name',
            'slug',
            'website_url',
            'is_active',
            'created_at',
            'updated_at',
        ],
        'scrape_batches' => [
            'id',
            'store_id',
            'source_name',
            'notes',
            'started_at',
            'finished_at',
            'status',
            'offers_found',
            'offers_inserted',
            'offers_updated',
            'offers_failed',
            'error_message',
            'created_at',
            'updated_at',
        ],
        'raw_offers' => [
            'id',
            'store_id',
            'scrape_batch_id',
            'external_id',
            'name',
            'price',
            'old_price',
            'discount',
            'condition',
            'unit_price',
            'image',
            'url',
            'valid_dates',
            'valid_from',
            'valid_to',
            'loyalty_card',
            'scraped_at',
            'normalization_status',
            'normalized_product_id',
            'product_variant_id',
            'normalized_at',
            'normalization_error',
            'raw_payload',
            'created_at',
            'updated_at',
        ],
        'normalized_products' => [
            'id',
            'canonical_name',
            'normalized_name',
            'brand',
            'generic_name',
            'category',
            'subcategory',
            'ai_confidence',
            'needs_review',
            'normalization_version',
            'source_name',
            'image_url',
            'created_at',
            'updated_at',
        ],
        'product_variants' => [
            'id',
            'normalized_product_id',
            'variant_name',
            'size_value',
            'size_unit',
            'package_count',
            'package_unit',
            'net_quantity_value',
            'net_quantity_unit',
            'attributes',
            'created_at',
            'updated_at',
        ],
        'offer_snapshots' => [
            'id',
            'raw_offer_id',
            'product_variant_id',
            'store_id',
            'scrape_batch_id',
            'price',
            'old_price',
            'discount_percent',
            'loyalty_card',
            'unit_price_value',
            'unit_price_unit',
            'valid_from',
            'valid_to',
            'scraped_at',
            'is_promotion',
            'created_at',
        ],
    ];

    public function handle(): int
    {
        $this->components->info('Verifying Discount Tracker database schema...');
        $this->newLine();

        $allTables = $this->getAllTables();
        $missingTables = [];
        $missingColumns = [];

        foreach ($this->requiredTables as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                $missingTables[] = $tableName;
                $this->components->error("✗ Table '{$tableName}' does not exist");

                continue;
            }

            $this->components->info("✓ Table '{$tableName}' exists");

            foreach ($columns as $columnName) {
                if (! Schema::hasColumn($tableName, $columnName)) {
                    $missingColumns[] = "{$tableName}.{$columnName}";
                    $this->components->warn("  ✗ Column '{$columnName}' is missing");
                } else {
                    $this->components->info("  ✓ Column '{$columnName}' exists");
                }
            }

            $this->newLine();
        }

        $this->newLine();
        $this->components->info('=== Summary ===');

        if (empty($missingTables) && empty($missingColumns)) {
            $this->components->info('✓ All required tables and columns exist!');

            return self::SUCCESS;
        }

        if (! empty($missingTables)) {
            $this->components->error('Missing tables: '.implode(', ', $missingTables));
        }

        if (! empty($missingColumns)) {
            $this->components->error('Missing columns: '.implode(', ', $missingColumns));
        }

        $this->newLine();
        $this->components->warn('Run: php artisan migrate');

        return self::FAILURE;
    }

    private function getAllTables(): array
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            return DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
        }

        if ($driver === 'sqlite') {
            return DB::select("SELECT name as table_name FROM sqlite_master WHERE type='table' ORDER BY name");
        }

        return [];
    }
}
