<?php

declare(strict_types=1);

use App\Models\NormalizedProduct;
use App\Models\ProductFamily;
use App\Models\SavedCart;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function seedFamilyOffer(ProductFamily $family, Store $store, float $price): array
{
    $product = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
        'canonical_name' => 'Pienas 2.5%',
        'normalized_name' => 'pienas 2.5%',
    ]);

    $variantId = DB::table('product_variants')->insertGetId([
        'normalized_product_id' => $product->id,
        'variant_name' => 'default',
        'size_unit' => 'l',
        'size_value' => 1.0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $snapshotId = DB::table('offer_snapshots')->insertGetId([
        'product_variant_id' => $variantId,
        'store_id' => $store->id,
        'week_starting' => now()->startOfWeek()->toDateString(),
        'price' => $price,
        'is_promotion' => false,
        'loyalty_card' => false,
        'scraped_at' => now(),
        'created_at' => now(),
    ]);

    DB::table('current_offers')->insert([
        'store_id' => $store->id,
        'product_variant_id' => $variantId,
        'price' => $price,
        'is_promotion' => false,
        'loyalty_card' => false,
        'last_seen_at' => now(),
        'week_starting' => now()->startOfWeek()->toDateString(),
        'last_snapshot_id' => $snapshotId,
        'updated_at' => now(),
    ]);

    return [
        'product' => $product,
        'variantId' => $variantId,
        'snapshotId' => $snapshotId,
    ];
}

test('guest hitting /sarasai is redirected to login', function () {
    $this->get('/sarasai')->assertRedirect('/login');
});

test('user A cannot view, edit, or delete user B saved cart', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $bCart = SavedCart::create([
        'user_id' => $b->id,
        'name' => 'B krepšelis',
    ]);

    $this->actingAs($a)
        ->patch("/sarasai/{$bCart->id}", ['name' => 'Hacked'])
        ->assertForbidden();

    $this->actingAs($a)
        ->delete("/sarasai/{$bCart->id}")
        ->assertForbidden();

    $this->actingAs($a)
        ->post("/sarasai/{$bCart->id}/atkurti")
        ->assertForbidden();

    expect($bCart->fresh()->name)->toBe('B krepšelis');
});

test('store then restore round-trips item count and quantities', function () {
    $user = User::factory()->create();
    $store = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);
    $family = ProductFamily::create([
        'family_name' => 'Pienas',
        'normalized_family_name' => 'pienas-'.uniqid(),
    ]);

    $seeded = seedFamilyOffer($family, $store, 1.29);

    $this->actingAs($user)
        ->post('/sarasai', [
            'name' => 'Savaitės pirkiniai',
            'items' => [
                [
                    'familyId' => $family->id,
                    'normalizedProductId' => $seeded['product']->id,
                    'variantId' => $seeded['variantId'],
                    'quantity' => 3,
                    'name' => 'Pienas 2.5%',
                    'storeSlug' => 'maxima',
                    'priceCents' => 129,
                ],
            ],
        ])
        ->assertRedirect();

    $cart = SavedCart::where('user_id', $user->id)->firstOrFail();
    expect($cart->name)->toBe('Savaitės pirkiniai')
        ->and($cart->items()->count())->toBe(1);

    $this->actingAs($user)
        ->postJson("/sarasai/{$cart->id}/atkurti")
        ->assertOk()
        ->assertJsonPath('items.0.quantity', 3)
        ->assertJsonPath('items.0.snapshotName', 'Pienas 2.5%')
        ->assertJsonPath('items.0.available', true)
        ->assertJsonPath('items.0.current.price', 1.29);
});

test('restoring a cart whose family was deleted renders snapshot name', function () {
    $user = User::factory()->create();
    $family = ProductFamily::create([
        'family_name' => 'Sviestas',
        'normalized_family_name' => 'sviestas-'.uniqid(),
    ]);

    $cart = SavedCart::create([
        'user_id' => $user->id,
        'name' => 'Senas sąrašas',
    ]);
    $cart->items()->create([
        'product_family_id' => $family->id,
        'quantity' => 2,
        'snapshot_name' => 'Sviestas „Rokiškio“ 200g',
        'snapshot_store_slug' => 'rimi',
        'snapshot_price_cents' => 219,
    ]);

    $family->delete();

    $this->actingAs($user)
        ->postJson("/sarasai/{$cart->id}/atkurti")
        ->assertOk()
        ->assertJsonPath('items.0.snapshotName', 'Sviestas „Rokiškio“ 200g')
        ->assertJsonPath('items.0.available', false)
        ->assertJsonPath('items.0.familyId', null);
});

test('renaming and deleting own carts works', function () {
    $user = User::factory()->create();
    $cart = SavedCart::create([
        'user_id' => $user->id,
        'name' => 'Originalus',
    ]);

    $this->actingAs($user)
        ->patch("/sarasai/{$cart->id}", ['name' => 'Naujas'])
        ->assertRedirect();

    expect($cart->fresh()->name)->toBe('Naujas');

    $this->actingAs($user)
        ->delete("/sarasai/{$cart->id}")
        ->assertRedirect();

    expect(SavedCart::find($cart->id))->toBeNull();
});

test('index page lists user carts with item counts', function () {
    $user = User::factory()->create();
    $cart = SavedCart::create([
        'user_id' => $user->id,
        'name' => 'Sąrašas A',
    ]);
    $cart->items()->create([
        'quantity' => 1,
        'snapshot_name' => 'Bananai',
    ]);
    $cart->items()->create([
        'quantity' => 2,
        'snapshot_name' => 'Obuoliai',
    ]);

    $this->actingAs($user)
        ->get('/sarasai')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sarasai/Index')
            ->has('carts', 1)
            ->where('carts.0.name', 'Sąrašas A')
            ->where('carts.0.itemCount', 2)
        );
});
