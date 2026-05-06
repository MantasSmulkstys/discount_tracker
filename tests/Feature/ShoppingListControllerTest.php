<?php

use App\Models\ShoppingList;
use App\Models\User;

function payload(): array
{
    return [
        'items' => [
            [
                'normalizedProductId' => 1,
                'name' => 'Pienas',
                'brand' => 'Žemaitijos',
                'storeId' => 'maxima',
                'qty' => 2,
                'unitPrice' => 1.29,
                'unitOldPrice' => 1.59,
            ],
            [
                'normalizedProductId' => 2,
                'name' => 'Duona',
                'brand' => null,
                'storeId' => 'lidl',
                'qty' => 1,
                'unitPrice' => 1.10,
                'unitOldPrice' => 1.10,
            ],
        ],
        'subtotal' => 3.68,
        'originalTotal' => 4.28,
        'savings' => 0.60,
    ];
}

test('guest cannot create a shopping list', function () {
    $this->post('/shopping-lists', payload())
        ->assertRedirect('/login');

    expect(ShoppingList::count())->toBe(0);
});

test('authenticated user can create a shopping list', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/shopping-lists', payload())
        ->assertRedirect();

    $list = ShoppingList::first();

    expect($list)->not->toBeNull()
        ->and($list->user_id)->toBe($user->id)
        ->and($list->item_count)->toBe(3)
        ->and((float) $list->savings)->toBe(0.60)
        ->and($list->store_ids)->toEqualCanonicalizing(['maxima', 'lidl'])
        ->and($list->items)->toHaveCount(2);
});

test('iterating within the session window updates a single record', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/shopping-lists', payload());

    $bigger = payload();
    $bigger['items'][0]['qty'] = 5;
    $bigger['savings'] = 1.20;
    $this->actingAs($user)->post('/shopping-lists', $bigger);

    $smaller = payload();
    array_pop($smaller['items']);
    $smaller['savings'] = 0.30;
    $this->actingAs($user)->post('/shopping-lists', $smaller);

    expect(ShoppingList::where('user_id', $user->id)->count())->toBe(1);

    $list = ShoppingList::where('user_id', $user->id)->first();
    expect((float) $list->savings)->toBe(0.30)
        ->and($list->items)->toHaveCount(1);
});

test('a generation outside the session window creates a new record', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/shopping-lists', payload());

    ShoppingList::where('user_id', $user->id)
        ->update(['updated_at' => now()->subHours(2)]);

    $this->actingAs($user)->post('/shopping-lists', payload());

    expect(ShoppingList::where('user_id', $user->id)->count())->toBe(2);
});

test('rejects empty items array', function () {
    $user = User::factory()->create();

    $broken = payload();
    $broken['items'] = [];

    $this->actingAs($user)
        ->post('/shopping-lists', $broken)
        ->assertSessionHasErrors('items');
});
