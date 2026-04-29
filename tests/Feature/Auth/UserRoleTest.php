<?php

use App\Actions\Fortify\CreateNewUser;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Str;

test('fortify user creation assigns the user role', function () {
    $user = app(CreateNewUser::class)->create([
        'name' => 'Role Test User',
        'email' => 'role-test-'.Str::lower(Str::random(8)).'@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($user->role)->toBe(UserRole::User);
    $this->assertModelExists($user);
});

test('user factory creates regular users by default', function () {
    $user = User::factory()->create();

    expect($user->role)->toBe(UserRole::User);
});

test('admin factory state creates admin users', function () {
    $user = User::factory()->admin()->create();

    expect($user->role)->toBe(UserRole::Admin);
    expect($user->isAdmin())->toBeTrue();
});
