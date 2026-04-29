<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The original `create_raw_offers_table` migration was backfilled to already
     * create `external_id` and mark `price` nullable, so this migration only
     * performs work on legacy databases where those attributes are still missing.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! Schema::hasColumn('raw_offers', 'external_id')) {
            Schema::table('raw_offers', function (Blueprint $table) {
                $table->string('external_id', 64)->nullable()->after('store_id');
            });

            if ($driver === 'pgsql') {
                DB::statement(<<<'SQL'
                    UPDATE raw_offers
                    SET external_id = md5(
                        concat_ws(
                            '|',
                            store_id::text,
                            lower(trim(name)),
                            lower(trim(coalesce(url, ''))),
                            lower(trim(coalesce(unit_price, ''))),
                            lower(trim(coalesce(condition, '')))
                        )
                    )
                    WHERE external_id IS NULL
                SQL);
            }

            if ($this->hasUniqueIndex('raw_offers', 'raw_offers_store_id_url_unique')) {
                Schema::table('raw_offers', function (Blueprint $table) {
                    $table->dropUnique(['store_id', 'url']);
                });
            }

            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE raw_offers ALTER COLUMN price DROP NOT NULL');
                DB::statement('ALTER TABLE raw_offers ALTER COLUMN external_id SET NOT NULL');
            }

            Schema::table('raw_offers', function (Blueprint $table) {
                $table->unique('external_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The forward migration is idempotent; rolling back would just drop the
        // column we relied on in the subsequent schema. Leave it untouched.
    }

    private function hasUniqueIndex(string $table, string $indexName): bool
    {
        try {
            $indexes = Schema::getConnection()
                ->getSchemaBuilder()
                ->getIndexes($table);

            foreach ($indexes as $index) {
                if (($index['name'] ?? null) === $indexName) {
                    return (bool) ($index['unique'] ?? false);
                }
            }
        } catch (Throwable) {
            // Older Laravel versions do not expose getIndexes(); assume present.
            return true;
        }

        return false;
    }
};
