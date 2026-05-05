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
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('website_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_families', function (Blueprint $table): void {
            $table->id();
            $table->string('family_name')->nullable();
            $table->string('normalized_family_name')->unique();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('subcategories')->nullOnDelete();
            $table->string('generic_name')->nullable();
            $table->timestamps();

            $table->index('category_id');
        });

        Schema::create('normalized_products', function (Blueprint $table): void {
            $table->id();
            $table->text('canonical_name');
            $table->text('normalized_name');
            $table->string('brand')->nullable();
            $table->string('generic_name')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('subcategories')->nullOnDelete();
            $table->foreignId('family_id')->nullable()->constrained('product_families')->nullOnDelete();
            $table->decimal('ai_confidence', 5, 4)->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('normalization_version')->nullable();
            $table->text('source_name')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamps();

            $table->index('normalized_name');
            $table->index('brand');
            $table->index('needs_review');
        });

        $this->addIdentityKey('normalized_products', [
            'normalized_name',
            "COALESCE(brand,'')",
            "COALESCE(category_id::text,'')",
            "COALESCE(subcategory_id::text,'')",
        ], [
            'normalized_name',
            "COALESCE(brand,'')",
            "COALESCE(CAST(category_id AS TEXT),'')",
            "COALESCE(CAST(subcategory_id AS TEXT),'')",
        ]);

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('normalized_product_id')->constrained('normalized_products')->cascadeOnDelete();
            $table->text('variant_name')->nullable();
            $table->decimal('size_value', 10, 3)->nullable();
            $table->string('size_unit', 16)->nullable();
            $table->decimal('package_count', 10, 3)->nullable();
            $table->string('package_unit', 16)->nullable();
            $table->decimal('net_quantity_value', 10, 3)->nullable();
            $table->string('net_quantity_unit', 16)->nullable();
            $table->jsonb('attributes')->nullable();
            $table->timestamps();
        });

        $this->addIdentityKey('product_variants', [
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
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('normalized_products');
        Schema::dropIfExists('product_families');
        Schema::dropIfExists('stores');
    }

    /**
     * Add a STORED generated column `identity_key` plus a UNIQUE index on it.
     *
     * Why: a plain unique index across nullable columns treats NULLs as
     * distinct (Postgres) or differs across drivers, so deduplication misses
     * rows whose only difference is NULL vs empty. Concatenating a normalized
     * key with COALESCE collapses NULLs and gives us an honest unique key.
     *
     * @param  array<int, string>  $pgExpressions  Expressions for PostgreSQL `||` concat.
     * @param  array<int, string>  $sqliteExpressions  Expressions for SQLite `||` concat.
     */
    private function addIdentityKey(string $table, array $pgExpressions, array $sqliteExpressions): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $expression = implode(" || '|' || ", $pgExpressions);
            DB::statement("ALTER TABLE {$table} ADD COLUMN identity_key text GENERATED ALWAYS AS ({$expression}) STORED");
            DB::statement("CREATE UNIQUE INDEX {$table}_identity_key_unique ON {$table} (identity_key)");

            return;
        }

        if ($driver === 'sqlite') {
            // SQLite forbids STORED generated columns added via ALTER TABLE,
            // but VIRTUAL columns can be UNIQUE-indexed and the index is
            // materialized — same effective guarantee.
            $expression = implode(" || '|' || ", $sqliteExpressions);
            DB::statement("ALTER TABLE {$table} ADD COLUMN identity_key TEXT GENERATED ALWAYS AS ({$expression}) VIRTUAL");
            DB::statement("CREATE UNIQUE INDEX {$table}_identity_key_unique ON {$table} (identity_key)");

            return;
        }

        // For other drivers (mysql, etc.) no-op — the application can fall back
        // to its existing IS NOT DISTINCT FROM lookups.
    }
};
