<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('normalized_products', function (Blueprint $table): void {
            // Admin-hidden product cards: kept in the DB (so they re-hide on the
            // next promo) but filtered out of the public catalog.
            $table->boolean('is_hidden')->default(false)->after('needs_review');
            $table->index('is_hidden');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('normalized_products', function (Blueprint $table): void {
            $table->dropIndex(['is_hidden']);
            $table->dropColumn('is_hidden');
        });
    }
};
