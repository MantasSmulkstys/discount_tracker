<?php

use App\Models\NormalizedProduct;
use App\Models\ProductReport;
use App\Models\User;

test('guest cannot report a product', function () {
    $product = NormalizedProduct::factory()->create();

    $this->post("/products/{$product->id}/report")
        ->assertRedirect('/login');
});

test('authenticated user can report a product', function () {
    $user = User::factory()->create();
    $product = NormalizedProduct::factory()->create();

    $this->actingAs($user)
        ->post("/products/{$product->id}/report")
        ->assertRedirect();

    expect(ProductReport::where([
        'user_id' => $user->id,
        'normalized_product_id' => $product->id,
    ])->exists())->toBeTrue();
});

test('reporting the same product twice does not create duplicate rows', function () {
    $user = User::factory()->create();
    $product = NormalizedProduct::factory()->create();

    $this->actingAs($user)->post("/products/{$product->id}/report");
    $this->actingAs($user)->post("/products/{$product->id}/report");

    expect(ProductReport::where([
        'user_id' => $user->id,
        'normalized_product_id' => $product->id,
    ])->count())->toBe(1);
});

test('authenticated user can un-report a product', function () {
    $user = User::factory()->create();
    $product = NormalizedProduct::factory()->create();

    ProductReport::create([
        'user_id' => $user->id,
        'normalized_product_id' => $product->id,
    ]);

    $this->actingAs($user)
        ->delete("/products/{$product->id}/report")
        ->assertRedirect();

    expect(ProductReport::where([
        'user_id' => $user->id,
        'normalized_product_id' => $product->id,
    ])->exists())->toBeFalse();
});

test('un-reporting a product that was never reported does not error', function () {
    $user = User::factory()->create();
    $product = NormalizedProduct::factory()->create();

    $this->actingAs($user)
        ->delete("/products/{$product->id}/report")
        ->assertRedirect();
});

test('user cannot delete another users report', function () {
    $reporter = User::factory()->create();
    $other = User::factory()->create();
    $product = NormalizedProduct::factory()->create();

    ProductReport::create([
        'user_id' => $reporter->id,
        'normalized_product_id' => $product->id,
    ]);

    $this->actingAs($other)
        ->delete("/products/{$product->id}/report");

    expect(ProductReport::where('user_id', $reporter->id)->exists())->toBeTrue();
});
