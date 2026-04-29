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
        Schema::create('raw_offers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 64)->unique();

            $table->text('name');
            $table->decimal('price', 8, 2)->nullable();
            $table->decimal('old_price', 8, 2)->nullable();
            $table->decimal('discount', 5, 2)->nullable();

            $table->string('condition')->nullable();
            $table->string('unit_price')->nullable();
            $table->string('image')->nullable();
            $table->string('url')->nullable();

            $table->string('valid_dates')->nullable();
            $table->boolean('loyalty_card')->default(false);

            $table->timestamp('scraped_at');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('raw_offers');
    }
};
