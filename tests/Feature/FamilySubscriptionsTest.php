<?php

declare(strict_types=1);

use App\Models\FamilySubscription;
use App\Models\ProductFamily;
use App\Models\User;

function makeFamily(string $name = 'Pienas'): ProductFamily
{
    return ProductFamily::create([
        'family_name' => $name,
        'normalized_family_name' => mb_strtolower($name).'-'.uniqid(),
    ]);
}

test('guest cannot subscribe and is redirected', function () {
    $family = makeFamily();

    $this->post('/sekimai', ['family_id' => $family->id])
        ->assertRedirect('/login');

    expect(FamilySubscription::count())->toBe(0);
});

test('subscribing is idempotent (POST twice creates one row)', function () {
    $user = User::factory()->create();
    $family = makeFamily();

    $this->actingAs($user)
        ->post('/sekimai', ['family_id' => $family->id])
        ->assertRedirect();

    $this->actingAs($user)
        ->post('/sekimai', ['family_id' => $family->id])
        ->assertRedirect();

    expect(FamilySubscription::where('user_id', $user->id)->count())->toBe(1);
});

test('user can only unsubscribe their own subscription', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $family = makeFamily();

    $sub = FamilySubscription::create([
        'user_id' => $b->id,
        'family_id' => $family->id,
    ]);

    $this->actingAs($a)
        ->delete("/sekimai/{$sub->id}")
        ->assertForbidden();

    expect(FamilySubscription::find($sub->id))->not->toBeNull();

    $this->actingAs($b)
        ->delete("/sekimai/{$sub->id}")
        ->assertRedirect();

    expect(FamilySubscription::find($sub->id))->toBeNull();
});

test('subscriptions index renders the Inertia page with the users subscriptions', function () {
    $user = User::factory()->create();
    $family = makeFamily('Sviestas');

    FamilySubscription::create([
        'user_id' => $user->id,
        'family_id' => $family->id,
    ]);

    $this->actingAs($user)
        ->get('/profilis/sekimai')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Profilis/Sekimai')
            ->has('subscriptions', 1)
            ->where('subscriptions.0.familyName', 'Sviestas')
        );
});

test('subscribed family ids are shared via Inertia', function () {
    $user = User::factory()->create();
    $family = makeFamily('Vanduo');
    $sub = FamilySubscription::create([
        'user_id' => $user->id,
        'family_id' => $family->id,
    ]);

    $this->actingAs($user)
        ->get('/profilis/sekimai')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where("subscribedFamilies.{$family->id}", $sub->id)
        );
});

test('rejects subscribing to a non-existent family', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/sekimai', ['family_id' => 999999])
        ->assertSessionHasErrors('family_id');
});
