<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make `normalized_products.identity_key` case-insensitive on `brand`, so AI
 * runs that emit `'Mlekovita'` and `'MLEKOVITA'` for the same brand collide
 * on the unique index instead of inserting two separate rows.
 *
 * Must be run AFTER `php artisan products:dedupe` — the new key collapses
 * existing case-only siblings, so the unique index would refuse to be created
 * while duplicates still exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS normalized_products_identity_key_unique');
            DB::statement('ALTER TABLE normalized_products DROP COLUMN IF EXISTS identity_key');
            DB::statement("
                ALTER TABLE normalized_products
                ADD COLUMN identity_key text GENERATED ALWAYS AS (
                    normalized_name
                    || '|' || COALESCE(LOWER(TRIM(brand)),'')
                    || '|' || COALESCE(category_id::text,'')
                    || '|' || COALESCE(subcategory_id::text,'')
                ) STORED
            ");
            DB::statement('CREATE UNIQUE INDEX normalized_products_identity_key_unique ON normalized_products (identity_key)');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS normalized_products_identity_key_unique');
            // SQLite cannot drop a generated column via ALTER, so rebuild the
            // table. Use a temporary table copy of everything except the
            // generated column, swap, then re-add identity_key with the new
            // expression.
            DB::statement('
                CREATE TABLE normalized_products_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    canonical_name TEXT NOT NULL,
                    normalized_name TEXT NOT NULL,
                    brand TEXT,
                    generic_name TEXT,
                    category_id INTEGER,
                    subcategory_id INTEGER,
                    family_id INTEGER,
                    ai_confidence DECIMAL(5,4),
                    needs_review BOOLEAN DEFAULT 0 NOT NULL,
                    normalization_version TEXT,
                    source_name TEXT,
                    image_url TEXT,
                    created_at DATETIME,
                    updated_at DATETIME
                )
            ');
            DB::statement('
                INSERT INTO normalized_products_new
                (id, canonical_name, normalized_name, brand, generic_name, category_id, subcategory_id, family_id,
                 ai_confidence, needs_review, normalization_version, source_name, image_url, created_at, updated_at)
                SELECT id, canonical_name, normalized_name, brand, generic_name, category_id, subcategory_id, family_id,
                       ai_confidence, needs_review, normalization_version, source_name, image_url, created_at, updated_at
                FROM normalized_products
            ');
            DB::statement('DROP TABLE normalized_products');
            DB::statement('ALTER TABLE normalized_products_new RENAME TO normalized_products');
            DB::statement("
                ALTER TABLE normalized_products
                ADD COLUMN identity_key TEXT GENERATED ALWAYS AS (
                    normalized_name
                    || '|' || COALESCE(LOWER(TRIM(brand)),'')
                    || '|' || COALESCE(CAST(category_id AS TEXT),'')
                    || '|' || COALESCE(CAST(subcategory_id AS TEXT),'')
                ) VIRTUAL
            ");
            DB::statement('CREATE UNIQUE INDEX normalized_products_identity_key_unique ON normalized_products (identity_key)');
            DB::statement('CREATE INDEX normalized_products_normalized_name_index ON normalized_products (normalized_name)');
            DB::statement('CREATE INDEX normalized_products_brand_index ON normalized_products (brand)');
            DB::statement('CREATE INDEX normalized_products_needs_review_index ON normalized_products (needs_review)');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS normalized_products_identity_key_unique');
            DB::statement('ALTER TABLE normalized_products DROP COLUMN IF EXISTS identity_key');
            DB::statement("
                ALTER TABLE normalized_products
                ADD COLUMN identity_key text GENERATED ALWAYS AS (
                    normalized_name
                    || '|' || COALESCE(brand,'')
                    || '|' || COALESCE(category_id::text,'')
                    || '|' || COALESCE(subcategory_id::text,'')
                ) STORED
            ");
            DB::statement('CREATE UNIQUE INDEX normalized_products_identity_key_unique ON normalized_products (identity_key)');
        }
    }
};
