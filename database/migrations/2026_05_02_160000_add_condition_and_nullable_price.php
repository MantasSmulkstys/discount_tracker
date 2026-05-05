<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Surface conditional promotions ("1+1", "2+1", coupon discounts) and
 * priceless promos (discount-only offers with no headline EUR amount) in the
 * public listing. Both were previously dropped on the snapshot stage.
 *
 * - `offer_snapshots.condition` / `current_offers.condition`: the raw promo
 *   condition text scraped from the store ("1+1", "Galioja su pigintuvu", …),
 *   shown as a red badge on the product card.
 * - `price` becomes nullable so a promo with no explicit price (only a
 *   discount % or a "1+1" tag) can still be persisted.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: the squashed `create_offer_tables` migration was updated
        // to bake these in too, so this only runs against databases created
        // before that update.
        if (! Schema::hasColumn('offer_snapshots', 'condition')) {
            Schema::table('offer_snapshots', function (Blueprint $table): void {
                $table->text('condition')->nullable()->after('discount_percent');
            });
        }

        if (! Schema::hasColumn('current_offers', 'condition')) {
            Schema::table('current_offers', function (Blueprint $table): void {
                $table->text('condition')->nullable()->after('discount_percent');
            });
        }

        $this->makePriceNullable('offer_snapshots');
        $this->makePriceNullable('current_offers');
    }

    public function down(): void
    {
        Schema::table('offer_snapshots', function (Blueprint $table): void {
            $table->dropColumn('condition');
        });

        Schema::table('current_offers', function (Blueprint $table): void {
            $table->dropColumn('condition');
        });

        // We don't restore NOT NULL on rollback — by the time you rollback,
        // there may be priceless rows in the table that would block the
        // change. Leaving price nullable on rollback is harmless.
    }

    /**
     * Driver-portable "drop NOT NULL on price". Postgres does this online via
     * ALTER COLUMN; SQLite needs Laravel's table-rebuild path which `change()`
     * provides via doctrine/dbal — but to keep migrations runnable without
     * dbal we use raw SQL on Postgres and rely on the fact that SQLite never
     * enforces a freshly-added NOT NULL on `change()`-rewritten tables.
     */
    private function makePriceNullable(string $table): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN price DROP NOT NULL");

            return;
        }

        if ($driver === 'sqlite') {
            // SQLite ignores ALTER COLUMN; a clean migrate:fresh under the
            // adjusted table-creation migration is the only way to flip the
            // constraint. Tests run migrate:fresh per case so this is a no-op
            // in test contexts where the test schema would already match.
            return;
        }
    }
};
