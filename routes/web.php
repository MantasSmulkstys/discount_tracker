<?php

use App\Http\Controllers\Auth\GoogleAuthenticatedSessionController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProductsController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/products', [ProductsController::class, 'index'])->name('products.index');
Route::get('/products/{id}', [ProductsController::class, 'show'])
    ->whereNumber('id')
    ->name('products.show');

Route::get('/cart', [CartController::class, 'index'])->name('cart.index');

Route::middleware('guest')->prefix('auth/google')->name('auth.google.')->group(function () {
    Route::get('/', [GoogleAuthenticatedSessionController::class, 'redirect'])
        ->name('redirect');
    Route::get('/callback', [GoogleAuthenticatedSessionController::class, 'callback'])
        ->name('callback');
});

Route::middleware(['auth', 'verified', 'admin'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';
