<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('regular users cannot visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))->assertForbidden();
});

test('admin users can visit the dashboard', function () {
    $storeId = DB::table('stores')->insertGetId([
        'name' => 'testmart',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('raw_offers')->insert([
        'store_id' => $storeId,
        'external_id' => 'featured-testmart-offer',
        'name' => 'Featured Testmart Offer',
        'price' => 1.99,
        'old_price' => 4.99,
        'discount' => 100,
        'condition' => null,
        'unit_price' => '3,98 €/kg',
        'image' => 'https://example.com/featured.png',
        'url' => 'https://example.com/featured',
        'valid_dates' => 'Iki 04.27',
        'loyalty_card' => true,
        'scraped_at' => now()->addMinute(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $response = $this->get(route('dashboard'));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('summary', 3)
        ->has('stores')
        ->has('products.data')
        ->where('products.data.0.name', 'Featured Testmart Offer')
        ->where('products.data.0.store', 'Testmart')
    );
});

test('admin users can filter deals by store with pagination', function () {
    $storeId = DB::table('stores')->insertGetId([
        'name' => 'testmart',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach (range(1, 13) as $index) {
        DB::table('raw_offers')->insert([
            'store_id' => $storeId,
            'external_id' => 'testmart-offer-'.$index,
            'name' => "Testmart Product {$index}",
            'price' => 1.99,
            'old_price' => 2.99,
            'discount' => 90 - $index,
            'condition' => null,
            'unit_price' => '3,98 €/kg',
            'image' => 'https://example.com/product.png',
            'url' => 'https://example.com/product-'.$index,
            'valid_dates' => 'Iki 04.27',
            'loyalty_card' => $index % 2 === 0,
            'scraped_at' => now()->subMinutes($index),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $response = $this->get(route('dashboard', ['store' => 'testmart']));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('currentStore', 'Testmart')
        ->has('products.data', 12)
        ->where('products.data.0.name', 'Testmart Product 1')
        ->where('products.meta.currentPage', 1)
        ->where('products.meta.lastPage', 2)
        ->where('products.meta.total', 13)
        ->where('stores', fn ($stores): bool => collect($stores)->contains(
            fn (array $store): bool => $store['slug'] === 'testmart' && $store['active'] === true
        ))
    );
});
