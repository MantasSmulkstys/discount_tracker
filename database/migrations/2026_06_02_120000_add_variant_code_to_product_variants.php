<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `variant_code` to product_variants and folds it into the generated
 * `identity_key`.
 *
 * Two genuinely different articles that share a normalized name + size (e.g.
 * two kettle models "art. KT-S08" / "art. PK-3058B", or flower bouquets of
 * 30 vs 9 stems) previously collapsed into one variant and shared a single,
 * meaningless price history — and on a same-store/same-week collision the
 * surviving snapshot mixed one offer's image with another's price. The
 * normalizer now derives a deterministic per-SKU `variant_code`; including it
 * in the identity keeps those variants distinct.
 *
 * Non-destructive: existing rows have `variant_code = NULL`, so the regenerated
 * key only gains a trailing empty segment — uniqueness is preserved and no rows
 * are merged or split until they are re-normalized.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->text('variant_code')->nullable()->after('net_quantity_unit');
        });

        $this->regenerateIdentityKey([
            'normalized_product_id::text',
            "COALESCE(size_value::text,'')",
            "COALESCE(size_unit,'')",
            "COALESCE(package_count::text,'')",
            "COALESCE(package_unit,'')",
            "COALESCE(variant_code,'')",
        ], [
            'CAST(normalized_product_id AS TEXT)',
            "COALESCE(CAST(size_value AS TEXT),'')",
            "COALESCE(size_unit,'')",
            "COALESCE(CAST(package_count AS TEXT),'')",
            "COALESCE(package_unit,'')",
            "COALESCE(variant_code,'')",
        ]);
    }

    public function down(): void
    {
        // Restore the original identity_key (without variant_code) first, then
        // drop the column it referenced.
        $this->regenerateIdentityKey([
            'normalized_product_id::text',
            "COALESCE(size_value::text,'')",
            "COALESCE(size_unit,'')",
            "COALESCE(package_count::text,'')",
            "COALESCE(package_unit,'')",
        ], [
            'CAST(normalized_product_id AS TEXT)',
            "COALESCE(CAST(size_value AS TEXT),'')",
            "COALESCE(size_unit,'')",
            "COALESCE(CAST(package_count AS TEXT),'')",
            "COALESCE(package_unit,'')",
        ]);

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('variant_code');
        });
    }

    /**
     * Drop and re-create the STORED/VIRTUAL generated `identity_key` column and
     * its unique index using the given concat expressions.
     *
     * @param  array<int, string>  $pgExpressions
     * @param  array<int, string>  $sqliteExpressions
     */
    private function regenerateIdentityKey(array $pgExpressions, array $sqliteExpressions): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $expression = implode(" || '|' || ", $pgExpressions);
            DB::statement('DROP INDEX IF EXISTS product_variants_identity_key_unique');
            DB::statement('ALTER TABLE product_variants DROP COLUMN identity_key');
            DB::statement("ALTER TABLE product_variants ADD COLUMN identity_key text GENERATED ALWAYS AS ({$expression}) STORED");
            DB::statement('CREATE UNIQUE INDEX product_variants_identity_key_unique ON product_variants (identity_key)');

            return;
        }

        if ($driver === 'sqlite') {
            $expression = implode(" || '|' || ", $sqliteExpressions);
            DB::statement('DROP INDEX IF EXISTS product_variants_identity_key_unique');
            DB::statement('ALTER TABLE product_variants DROP COLUMN identity_key');
            DB::statement("ALTER TABLE product_variants ADD COLUMN identity_key TEXT GENERATED ALWAYS AS ({$expression}) VIRTUAL");
            DB::statement('CREATE UNIQUE INDEX product_variants_identity_key_unique ON product_variants (identity_key)');
        }
    }
};
