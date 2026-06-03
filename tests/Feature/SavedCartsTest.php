<?php

declare(strict_types=1);

use App\Models\NormalizedProduct;
use App\Models\ProductFamily;
use App\Models\SavedCart;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

function seedNamedOffer(ProductFamily $family, Store $store, string $name, string $normalizedName, float $price, ?string $validTo = null, ?string $rawName = null): array
{
    $product = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
        'canonical_name' => $name,
        'normalized_name' => $normalizedName,
    ]);

    $variantId = DB::table('product_variants')->insertGetId([
        'normalized_product_id' => $product->id,
        'variant_name' => 'default',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rawOfferId = $rawName === null ? null : DB::table('raw_offers')->insertGetId([
        'store_id' => $store->id,
        'name' => $rawName,
        'price' => $price,
        'loyalty_card' => false,
        'scraped_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $snapshotId = DB::table('offer_snapshots')->insertGetId([
        'product_variant_id' => $variantId,
        'store_id' => $store->id,
        'raw_offer_id' => $rawOfferId,
        'week_starting' => now()->startOfWeek()->toDateString(),
        'price' => $price,
        'valid_to' => $validTo,
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

    return ['product' => $product, 'variantId' => $variantId, 'snapshotId' => $snapshotId];
}

/**
 * A family member with an explicit size unit so substitute size-compatibility
 * (volume vs weight vs count) can be exercised.
 */
function seedSizedMember(ProductFamily $family, Store $store, string $name, float $price, ?string $sizeUnit = null, ?float $sizeValue = null, ?string $validTo = null): array
{
    $product = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
        'canonical_name' => $name,
        'normalized_name' => Str::slug($name).'-'.uniqid(),
    ]);

    $variantId = DB::table('product_variants')->insertGetId([
        'normalized_product_id' => $product->id,
        'variant_name' => 'default',
        'size_unit' => $sizeUnit,
        'size_value' => $sizeValue,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $snapshotId = DB::table('offer_snapshots')->insertGetId([
        'product_variant_id' => $variantId,
        'store_id' => $store->id,
        'week_starting' => now()->startOfWeek()->toDateString(),
        'price' => $price,
        'valid_to' => $validTo,
        'is_promotion' => false,
        'loyalty_card' => false,
        'scraped_at' => now(),
        'created_at' => now(),
    ]);

    return ['product' => $product, 'variantId' => $variantId, 'snapshotId' => $snapshotId];
}

/**
 * A family member placed in a subcategory with an explicit generic (head) noun,
 * so cross-family substitute matching (same subcategory + shared core noun) can
 * be exercised.
 */
function seedRelatedMember(int $familyId, Store $store, string $name, string $genericName, int $subcategoryId, int $categoryId, float $price, ?string $sizeUnit = null, ?float $sizeValue = null, ?string $validTo = null): array
{
    $product = NormalizedProduct::factory()->create([
        'family_id' => $familyId,
        'canonical_name' => $name,
        'normalized_name' => Str::slug($name).'-'.uniqid(),
        'generic_name' => $genericName,
        'subcategory_id' => $subcategoryId,
        'category_id' => $categoryId,
    ]);

    $variantId = DB::table('product_variants')->insertGetId([
        'normalized_product_id' => $product->id,
        'variant_name' => 'default',
        'size_unit' => $sizeUnit,
        'size_value' => $sizeValue,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $snapshotId = DB::table('offer_snapshots')->insertGetId([
        'product_variant_id' => $variantId,
        'store_id' => $store->id,
        'week_starting' => now()->startOfWeek()->toDateString(),
        'price' => $price,
        'valid_to' => $validTo,
        'is_promotion' => false,
        'loyalty_card' => false,
        'scraped_at' => now(),
        'created_at' => now(),
    ]);

    return ['product' => $product, 'variantId' => $variantId, 'snapshotId' => $snapshotId];
}

test('a saved item resolves to the exact product chosen, not the cheapest family sibling', function () {
    $user = User::factory()->create();
    $iki = Store::factory()->create(['name' => 'iki', 'slug' => 'iki']);
    $lidl = Store::factory()->create(['name' => 'lidl', 'slug' => 'lidl']);

    $family = ProductFamily::create([
        'family_name' => 'Bandelės',
        'normalized_family_name' => 'bandeles-'.uniqid(),
    ]);

    // The product the user actually picks (IKI), plus a cheaper but DIFFERENT
    // sibling in the same family (LIDL) that must never be substituted in.
    $chosen = seedNamedOffer($family, $iki, 'Bandelė su varške', 'bandele-su-varske', 0.99);
    seedNamedOffer($family, $lidl, 'Mielinės bandelės su pudingu', 'mielines-bandeles-su-pudingu', 0.79);

    $this->actingAs($user)
        ->post('/sarasai', [
            'name' => 'Pusryčiai',
            'items' => [[
                'familyId' => $family->id,
                'normalizedProductId' => $chosen['product']->id,
                'variantId' => null,
                'quantity' => 1,
                'name' => 'Bandelė su varške',
                'storeSlug' => 'iki',
                'priceCents' => 99,
            ]],
        ])
        ->assertRedirect();

    $cart = SavedCart::where('user_id', $user->id)->firstOrFail();

    $this->actingAs($user)
        ->postJson("/sarasai/{$cart->id}/atkurti")
        ->assertOk()
        ->assertJsonPath('items.0.available', true)
        ->assertJsonPath('items.0.current.name', 'Bandelė su varške')
        ->assertJsonPath('items.0.current.storeSlug', 'iki')
        ->assertJsonPath('items.0.current.price', 0.99);

    // The estimated total must reflect the chosen product, not the 0,79 sibling.
    $this->actingAs($user)
        ->get('/sarasai')
        ->assertInertia(fn ($page) => $page->where('carts.0.estimatedTotal', 0.99));
});

test('a saved item whose discount has expired comes back unavailable with its snapshot intact', function () {
    $user = User::factory()->create();
    $iki = Store::factory()->create(['name' => 'iki', 'slug' => 'iki']);
    $family = ProductFamily::create([
        'family_name' => 'Bandelės',
        'normalized_family_name' => 'bandeles-'.uniqid(),
    ]);

    // Offer whose promotion window has already closed (valid_to in the past).
    $expired = seedNamedOffer($family, $iki, 'Bandelė su varške', 'bandele-su-varske', 0.99, now()->subDay()->toDateString());

    $this->actingAs($user)
        ->post('/sarasai', [
            'name' => 'Pusryčiai',
            'items' => [[
                'familyId' => $family->id,
                'normalizedProductId' => $expired['product']->id,
                'variantId' => null,
                'quantity' => 2,
                'name' => 'Bandelė su varške',
                'storeSlug' => 'iki',
                'priceCents' => 99,
            ]],
        ])
        ->assertRedirect();

    $cart = SavedCart::where('user_id', $user->id)->firstOrFail();

    // The frontend relies on this contract to skip the item on restore: no live
    // offer, but the frozen snapshot is preserved so it can be shown/searched.
    $this->actingAs($user)
        ->postJson("/sarasai/{$cart->id}/atkurti")
        ->assertOk()
        ->assertJsonPath('items.0.available', false)
        ->assertJsonPath('items.0.current', null)
        ->assertJsonPath('items.0.snapshotName', 'Bandelė su varške');

    // It also must not count toward the list's estimated total.
    $this->actingAs($user)
        ->get('/sarasai')
        ->assertInertia(fn ($page) => $page->where('carts.0.estimatedTotal', 0));
});

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

test('a restored available item shows the raw scraped name, not the canonical one', function () {
    $user = User::factory()->create();
    $iki = Store::factory()->create(['name' => 'iki', 'slug' => 'iki']);
    $family = ProductFamily::create([
        'family_name' => 'Indų plovikliai',
        'normalized_family_name' => 'indu-plovikliai-'.uniqid(),
    ]);

    // Canonical name is the flat normalized form; the raw scraped name carries
    // the proper casing/diacritics the shopper should see.
    $seeded = seedNamedOffer(
        $family,
        $iki,
        'indu ploviklis fairy',
        'indu-ploviklis-fairy-'.uniqid(),
        2.49,
        null,
        'Indų ploviklis „Fairy" 450 ml',
    );

    $this->actingAs($user)
        ->post('/sarasai', [
            'name' => 'Buitis',
            'items' => [[
                'familyId' => $family->id,
                'normalizedProductId' => $seeded['product']->id,
                'variantId' => $seeded['variantId'],
                'quantity' => 1,
                'name' => 'Indų ploviklis „Fairy" 450 ml',
                'storeSlug' => 'iki',
                'priceCents' => 249,
            ]],
        ])
        ->assertRedirect();

    $cart = SavedCart::where('user_id', $user->id)->firstOrFail();

    $this->actingAs($user)
        ->postJson("/sarasai/{$cart->id}/atkurti")
        ->assertOk()
        ->assertJsonPath('items.0.available', true)
        ->assertJsonPath('items.0.current.name', 'Indų ploviklis „Fairy" 450 ml');
});

test('restoring an unavailable item offers same-family, size-compatible substitutes excluding the exact product', function () {
    $user = User::factory()->create();
    $iki = Store::factory()->create(['name' => 'iki', 'slug' => 'iki']);
    $family = ProductFamily::create([
        'family_name' => 'Indų plovikliai',
        'normalized_family_name' => 'indu-plovikliai-'.uniqid(),
    ]);

    // Saved 450 ml dish soap whose promo has expired → comes back unavailable.
    $saved = seedSizedMember($family, $iki, 'Indų ploviklis Fairy', 2.49, 'ml', 450, now()->subDay()->toDateString());
    // Live volume-dimension siblings → eligible substitutes (cheapest first).
    $altMl = seedSizedMember($family, $iki, 'Indų ploviklis Fairy Sensitive', 1.99, 'ml', 450);
    $altL = seedSizedMember($family, $iki, 'Indų ploviklis Ludwik', 2.20, 'l', 1);
    // A count-dimension sibling → must NOT be offered for a volume product.
    seedSizedMember($family, $iki, 'Indaplovių tabletės', 5.00, 'vnt', 30);

    $this->actingAs($user)
        ->post('/sarasai', [
            'name' => 'Buitis',
            'items' => [[
                'familyId' => $family->id,
                'normalizedProductId' => $saved['product']->id,
                'variantId' => $saved['variantId'],
                'quantity' => 1,
                'name' => 'Indų ploviklis Fairy',
                'storeSlug' => 'iki',
                'priceCents' => 249,
            ]],
        ])
        ->assertRedirect();

    $cart = SavedCart::where('user_id', $user->id)->firstOrFail();

    $response = $this->actingAs($user)
        ->postJson("/sarasai/{$cart->id}/atkurti")
        ->assertOk()
        ->assertJsonPath('items.0.available', false);

    $suggestions = $response->json('items.0.suggestions');
    $ids = array_column($suggestions, 'normalizedProductId');

    expect($suggestions)->toHaveCount(2)
        ->and($ids)->toContain($altMl['product']->id)
        ->and($ids)->toContain($altL['product']->id)
        ->and($ids)->not->toContain($saved['product']->id);

    // Cheapest substitute is offered first.
    expect($suggestions[0]['normalizedProductId'])->toBe($altMl['product']->id);
});

test('substitutes can come from a different family that shares the subcategory and core noun', function () {
    $user = User::factory()->create();
    $iki = Store::factory()->create(['name' => 'iki', 'slug' => 'iki']);

    $categoryId = DB::table('categories')->insertGetId([
        'name_lt' => 'Buitis',
        'slug' => 'buitis-'.uniqid(),
    ]);
    $subId = DB::table('subcategories')->insertGetId([
        'category_id' => $categoryId,
        'name_lt' => 'Indų plovikliai',
        'slug' => 'indu-plovikliai-'.uniqid(),
    ]);

    // The saved product and its substitute sit in DIFFERENT families — as real
    // normalization often produces — but share a subcategory and the "ploviklis"
    // head noun, so family-only matching would (wrongly) find nothing.
    $familyA = ProductFamily::create(['family_name' => 'Fairy', 'normalized_family_name' => 'fairy-'.uniqid()]);
    $familyB = ProductFamily::create(['family_name' => 'Ludwik', 'normalized_family_name' => 'ludwik-'.uniqid()]);

    $saved = seedRelatedMember($familyA->id, $iki, 'Indų ploviklis Fairy', 'ploviklis', $subId, $categoryId, 2.49, 'ml', 450, now()->subDay()->toDateString());
    $alt = seedRelatedMember($familyB->id, $iki, 'Indų ploviklis Ludwik', 'ploviklis', $subId, $categoryId, 1.89, 'ml', 500);

    $this->actingAs($user)
        ->post('/sarasai', [
            'name' => 'Buitis',
            'items' => [[
                'familyId' => $familyA->id,
                'normalizedProductId' => $saved['product']->id,
                'variantId' => $saved['variantId'],
                'quantity' => 1,
                'name' => 'Indų ploviklis Fairy',
                'storeSlug' => 'iki',
                'priceCents' => 249,
            ]],
        ])
        ->assertRedirect();

    $cart = SavedCart::where('user_id', $user->id)->firstOrFail();

    $suggestions = $this->actingAs($user)
        ->postJson("/sarasai/{$cart->id}/atkurti")
        ->assertOk()
        ->assertJsonPath('items.0.available', false)
        ->json('items.0.suggestions');

    expect(array_column($suggestions, 'normalizedProductId'))->toContain($alt['product']->id);
});

test('an available restored item carries no substitute suggestions', function () {
    $user = User::factory()->create();
    $store = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);
    $family = ProductFamily::create([
        'family_name' => 'Pienas',
        'normalized_family_name' => 'pienas-'.uniqid(),
    ]);

    $seeded = seedFamilyOffer($family, $store, 1.29);

    $this->actingAs($user)
        ->post('/sarasai', [
            'name' => 'Pirkiniai',
            'items' => [[
                'familyId' => $family->id,
                'normalizedProductId' => $seeded['product']->id,
                'variantId' => $seeded['variantId'],
                'quantity' => 1,
                'name' => 'Pienas 2.5%',
                'storeSlug' => 'maxima',
                'priceCents' => 129,
            ]],
        ])
        ->assertRedirect();

    $cart = SavedCart::where('user_id', $user->id)->firstOrFail();

    $this->actingAs($user)
        ->postJson("/sarasai/{$cart->id}/atkurti")
        ->assertOk()
        ->assertJsonPath('items.0.available', true)
        ->assertJsonPath('items.0.suggestions', []);
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
