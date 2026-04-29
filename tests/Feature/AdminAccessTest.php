<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'admin'])
        ->get('/admin-test', fn () => 'ok');
});

test('guests are redirected away from admin routes', function () {
    $response = $this->get('/admin-test');

    $response->assertRedirect(route('login'));
});

test('registered non-admin users are forbidden from admin routes', function () {
    $response = $this->actingAs(User::factory()->create())
        ->get('/admin-test');

    $response->assertForbidden();
});

test('admin users can access admin routes', function () {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->get('/admin-test');

    $response->assertOk();
    $response->assertSee('ok');
});
