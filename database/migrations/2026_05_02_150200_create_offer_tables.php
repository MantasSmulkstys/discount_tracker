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
        Schema::create('scrape_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->text('source_name');
            $table->text('notes')->nullable();
            $table->date('week_starting')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 16)->default('running');
            $table->integer('offers_found')->default(0);
            $table->integer('offers_inserted')->default(0);
            $table->integer('offers_updated')->default(0);
            $table->integer('offers_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('source_name');
            $table->index('status');
            $table->index('started_at');
            $table->unique(['store_id', 'week_starting']);
        });

        $this->addEnumCheck('scrape_batches', 'status', ['running', 'completed', 'failed']);

        Schema::create('raw_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('scrape_batch_id')->nullable()->constrained('scrape_batches')->nullOnDelete();

            $table->string('content_hash', 64)->nullable();
            $table->string('external_sku', 128)->nullable();

            $table->text('name');
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('old_price', 10, 2)->nullable();
            $table->string('discount')->nullable();
            $table->text('condition')->nullable();
            $table->string('unit_price')->nullable();
            $table->text('image')->nullable();
            $table->text('url')->nullable();

            $table->text('valid_dates')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('loyalty_card')->default(false);

            $table->decimal('unit_price_value', 12, 4)->nullable();
            $table->string('unit_price_unit', 16)->nullable();

            $table->timestamp('scraped_at')->nullable();

            $table->string('normalization_status', 16)->nullable();
            $table->text('normalization_error')->nullable();
            $table->timestamp('normalized_at')->nullable();

            $table->jsonb('raw_payload')->nullable();
            $table->timestamps();

            $table->index('content_hash');
            $table->index('external_sku');
            $table->index('scraped_at');
            $table->index('name');
            $table->index('normalization_status');
            $table->unique(['scrape_batch_id', 'content_hash']);
        });

        $this->addEnumCheck('raw_offers', 'normalization_status', ['pending', 'processing', 'done', 'failed']);

        Schema::create('offer_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('raw_offer_id')->nullable()->constrained('raw_offers')->nullOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('scrape_batch_id')->nullable()->constrained('scrape_batches')->nullOnDelete();

            $table->date('week_starting');
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('old_price', 10, 2)->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->text('condition')->nullable();
            $table->boolean('loyalty_card')->default(false);
            $table->decimal('unit_price_value', 10, 3)->nullable();
            $table->string('unit_price_unit', 16)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamp('scraped_at');
            $table->boolean('is_promotion')->default(false);

            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_variant_id', 'scraped_at']);
            $table->index(['store_id', 'scraped_at']);
            $table->index(['week_starting', 'is_promotion']);
            $table->unique(['product_variant_id', 'store_id', 'week_starting'], 'offer_snapshots_pvs_week_unique');
        });

        Schema::create('current_offers', function (Blueprint $table): void {
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('old_price', 10, 2)->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->text('condition')->nullable();
            $table->boolean('is_promotion')->default(false);
            $table->boolean('loyalty_card')->default(false);
            $table->decimal('unit_price_value', 10, 3)->nullable();
            $table->string('unit_price_unit', 16)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamp('last_seen_at');
            $table->date('week_starting');
            $table->foreignId('last_snapshot_id')->nullable()->constrained('offer_snapshots')->nullOnDelete();
            $table->timestamp('updated_at')->useCurrent();

            $table->primary(['store_id', 'product_variant_id']);
            $table->index(['product_variant_id', 'price'], 'current_offers_variant_price_idx');
            $table->index('valid_to');
        });

        $this->addPartialIndex(
            table: 'current_offers',
            indexName: 'current_offers_promotion_idx',
            columns: 'is_promotion',
            condition: 'is_promotion = true',
        );

        Schema::create('price_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->decimal('previous_price', 10, 2)->nullable();
            $table->decimal('new_price', 10, 2);
            $table->decimal('change_pct', 6, 2)->nullable();
            $table->date('week_starting');
            $table->timestamp('changed_at')->useCurrent();
            $table->foreignId('snapshot_id')->nullable()->constrained('offer_snapshots')->nullOnDelete();

            $table->index(['product_variant_id', 'changed_at']);
            $table->index(['store_id', 'changed_at']);
        });

        $this->addPartialIndex(
            table: 'price_changes',
            indexName: 'price_changes_big_drops_idx',
            columns: 'changed_at',
            condition: 'change_pct < -10',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('price_changes');
        Schema::dropIfExists('current_offers');
        Schema::dropIfExists('offer_snapshots');
        Schema::dropIfExists('raw_offers');
        Schema::dropIfExists('scrape_batches');
    }

    /**
     * Add a CHECK constraint that pins a column to a known value set.
     *
     * Postgres only — SQLite cannot ALTER TABLE ADD CONSTRAINT after creation,
     * and we're already enforcing these values in application code, so skipping
     * the constraint in tests does not weaken production safety.
     *
     * @param  array<int, string>  $values
     */
    private function addEnumCheck(string $table, string $column, array $values): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $quoted = collect($values)->map(fn (string $v): string => "'".str_replace("'", "''", $v)."'")->implode(', ');
        $constraint = "{$table}_{$column}_check";
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$column} IN ({$quoted}))");
    }

    /**
     * Partial indexes are a big win for boolean flags ("active promos") and
     * sparse predicates ("big price drops"). Both Postgres and SQLite support
     * them with the same WHERE clause syntax.
     */
    private function addPartialIndex(string $table, string $indexName, string $columns, string $condition): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement("CREATE INDEX {$indexName} ON {$table} ({$columns}) WHERE {$condition}");
    }
};
