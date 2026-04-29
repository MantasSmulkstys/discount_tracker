<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Introduces the normalized catalog tables (`product_families`,
 * `normalized_products`, `product_variants`, `offer_snapshots`) that power the
 * public product listing, plus the bookkeeping columns on `raw_offers` used by
 * the Python normalizer to link raw rows to their normalized counterparts.
 *
 * The schema here mirrors what `uscraper/scripts/process_raw_offers_normalized.py`
 * writes, so the Laravel app can stand up a clean database on its own (SQLite
 * for tests, fresh dev envs) while remaining a no-op against existing Postgres
 * databases where the normalizer has already created these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_families')) {
            Schema::create('product_families', function (Blueprint $table): void {
                $table->id();
                $table->string('family_name')->nullable();
                $table->string('normalized_family_name')->index();
                $table->unsignedBigInteger('category_id')->nullable()->index();
                $table->unsignedBigInteger('subcategory_id')->nullable();
                $table->string('generic_name')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('normalized_products')) {
            Schema::create('normalized_products', function (Blueprint $table): void {
                $table->id();
                $table->string('canonical_name');
                $table->string('normalized_name')->index();
                $table->string('brand')->nullable();
                $table->string('generic_name')->nullable();
                $table->unsignedBigInteger('category_id')->nullable()->index();
                $table->unsignedBigInteger('subcategory_id')->nullable();
                $table->foreignId('family_id')
                    ->nullable()
                    ->constrained('product_families')
                    ->nullOnDelete();
                $table->decimal('ai_confidence', 5, 4)->nullable();
                $table->boolean('needs_review')->default(true);
                $table->string('normalization_version')->nullable();
                $table->text('source_name')->nullable();
                $table->string('image_url', 1000)->nullable();
                $table->timestamps();

                $table->index(['normalized_name', 'brand']);
            });
        }

        if (! Schema::hasTable('product_variants')) {
            Schema::create('product_variants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('normalized_product_id')
                    ->constrained('normalized_products')
                    ->cascadeOnDelete();
                $table->string('variant_name')->nullable();
                $table->decimal('size_value', 10, 3)->nullable();
                $table->string('size_unit', 16)->nullable();
                $table->integer('package_count')->nullable();
                $table->string('package_unit', 16)->nullable();
                $table->decimal('net_quantity_value', 10, 3)->nullable();
                $table->string('net_quantity_unit', 16)->nullable();
                $table->json('attributes')->nullable();
                $table->timestamps();

                $table->index(['normalized_product_id', 'size_value', 'size_unit']);
            });
        }

        if (! Schema::hasTable('offer_snapshots')) {
            Schema::create('offer_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('raw_offer_id')
                    ->nullable()
                    ->constrained('raw_offers')
                    ->nullOnDelete();
                $table->foreignId('product_variant_id')
                    ->nullable()
                    ->constrained('product_variants')
                    ->nullOnDelete();
                $table->foreignId('store_id')
                    ->constrained('stores')
                    ->cascadeOnDelete();
                $table->decimal('price', 10, 2)->nullable();
                $table->decimal('old_price', 10, 2)->nullable();
                $table->decimal('discount_percent', 5, 2)->nullable();
                $table->boolean('loyalty_card')->default(false);
                $table->decimal('unit_price_value', 12, 4)->nullable();
                $table->string('unit_price_unit', 16)->nullable();
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->timestamp('scraped_at')->nullable();
                $table->boolean('is_promotion')->default(false);
                $table->timestamp('created_at')->useCurrent();

                $table->index(['product_variant_id', 'store_id']);
                $table->index('store_id');
                $table->index('scraped_at');
            });
        }

        if (Schema::hasTable('raw_offers')) {
            Schema::table('raw_offers', function (Blueprint $table): void {
                if (! Schema::hasColumn('raw_offers', 'normalization_status')) {
                    $table->string('normalization_status', 32)->nullable()->index();
                }
                if (! Schema::hasColumn('raw_offers', 'normalization_error')) {
                    $table->text('normalization_error')->nullable();
                }
                if (! Schema::hasColumn('raw_offers', 'normalized_product_id')) {
                    $table->unsignedBigInteger('normalized_product_id')->nullable()->index();
                }
                if (! Schema::hasColumn('raw_offers', 'product_variant_id')) {
                    $table->unsignedBigInteger('product_variant_id')->nullable()->index();
                }
                if (! Schema::hasColumn('raw_offers', 'valid_from')) {
                    $table->date('valid_from')->nullable();
                }
                if (! Schema::hasColumn('raw_offers', 'valid_to')) {
                    $table->date('valid_to')->nullable();
                }
                if (! Schema::hasColumn('raw_offers', 'unit_price_value')) {
                    $table->decimal('unit_price_value', 12, 4)->nullable();
                }
                if (! Schema::hasColumn('raw_offers', 'unit_price_unit')) {
                    $table->string('unit_price_unit', 16)->nullable();
                }
                if (! Schema::hasColumn('raw_offers', 'normalized_at')) {
                    $table->timestamp('normalized_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Intentional no-op. These tables are shared with the Python normalization
        // pipeline and may contain production data; we never want `migrate:rollback`
        // to destroy them. Use a manual migration if a drop is ever required.
    }
};
