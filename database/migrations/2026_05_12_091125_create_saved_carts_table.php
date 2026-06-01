<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('saved_cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_cart_id')->constrained('saved_carts')->cascadeOnDelete();
            $table->foreignId('product_family_id')->nullable()->constrained('product_families')->nullOnDelete();
            $table->foreignId('normalized_product_id')->nullable()->constrained('normalized_products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('snapshot_name', 255);
            $table->string('snapshot_store_slug', 64)->nullable();
            $table->unsignedInteger('snapshot_price_cents')->nullable();
            $table->timestamps();

            $table->index('saved_cart_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_cart_items');
        Schema::dropIfExists('saved_carts');
    }
};
