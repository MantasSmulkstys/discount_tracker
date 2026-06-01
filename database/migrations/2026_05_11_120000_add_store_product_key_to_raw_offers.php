<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-store stable product identity for `raw_offers`.
 *
 * `content_hash` mixes the AI-volatile product name and unit_price, so even
 * tiny scrape drift produces a fresh hash and the normalizer re-asks the AI,
 * which then disagrees with itself and creates a duplicate normalized_product.
 *
 * `store_product_key` is derived from a priority list inside the scraper —
 * canonical product URL > image URL > text fingerprint — so the same physical
 * product gets the same key across scrapes. `key_source` records which level
 * matched so we can audit and re-key later if a better signal appears.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_offers', function (Blueprint $table): void {
            $table->text('store_product_key')->nullable();
            $table->string('key_source', 16)->nullable();
        });

        $driver = Schema::getConnection()->getDriverName();

        // Composite index for the (store_id, store_product_key) lookup the
        // normalizer runs before falling back to AI. Not unique — many
        // raw_offers rows share the same key across weeks/batches.
        DB::statement(
            'CREATE INDEX raw_offers_store_product_key_idx '
            .'ON raw_offers (store_id, store_product_key)'
        );

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE raw_offers ADD CONSTRAINT raw_offers_key_source_check '
                ."CHECK (key_source IS NULL OR key_source IN ('url', 'image', 'text'))"
            );
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE raw_offers DROP CONSTRAINT IF EXISTS raw_offers_key_source_check');
        }

        DB::statement('DROP INDEX IF EXISTS raw_offers_store_product_key_idx');

        Schema::table('raw_offers', function (Blueprint $table): void {
            $table->dropColumn(['store_product_key', 'key_source']);
        });
    }
};
