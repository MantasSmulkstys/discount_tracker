<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('family_id')->constrained('product_families')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->cascadeOnDelete();
            $table->unsignedInteger('max_price_cents')->nullable();
            $table->timestamps();

            $table->index(['user_id']);
            $table->index(['family_id']);
            // SQLite treats NULL values in unique indexes as distinct, so multiple
            // (user, family, NULL) rows would be possible. We rely on firstOrCreate
            // in the controller to enforce idempotency for the v1 null-store case.
            $table->unique(['user_id', 'family_id', 'store_id'], 'family_subs_user_family_store_unique');
        });

        Schema::create('notification_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('family_id')->constrained('product_families')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->unsignedBigInteger('current_offer_snapshot_id')->nullable();
            $table->string('trigger_type', 16);
            $table->timestamp('sent_at')->useCurrent();

            $table->index(['user_id', 'family_id', 'store_id', 'sent_at'], 'notif_log_user_family_store_sent_idx');
            $table->index(['user_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_log');
        Schema::dropIfExists('family_subscriptions');
    }
};
