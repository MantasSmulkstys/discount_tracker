<?php

use App\Http\Controllers\Admin\ScrapeController;
use App\Http\Controllers\AdminReportsController;
use App\Http\Controllers\Auth\GoogleAuthenticatedSessionController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\ProductReportController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\RoutePlanController;
use App\Http\Controllers\StatsController;
use Illuminate\Support\Facades\Route;

// `/` and `/products` both render the products listing — the listing IS the
// homepage. Two URLs intentionally to keep old `/products` bookmarks working.
Route::get('/', [ProductsController::class, 'index'])->name('home');
Route::get('/products', [ProductsController::class, 'index'])->name('products.index');
Route::get('/products/{id}', [ProductsController::class, 'show'])
    ->whereNumber('id')
    ->name('products.show');

Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::post('/cart/route-plan', RoutePlanController::class)->name('cart.route-plan');

Route::get('/statistika', StatsController::class)->name('stats.index');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/products/{normalizedProduct}/report', [ProductReportController::class, 'store'])
        ->name('products.report');
    Route::delete('/products/{normalizedProduct}/report', [ProductReportController::class, 'destroy'])
        ->name('products.unreport');
});

Route::middleware('guest')->prefix('auth/google')->name('auth.google.')->group(function () {
    Route::get('/', [GoogleAuthenticatedSessionController::class, 'redirect'])
        ->name('redirect');
    Route::get('/callback', [GoogleAuthenticatedSessionController::class, 'callback'])
        ->name('callback');
});

Route::middleware(['auth', 'verified', 'admin'])->group(function () {
    Route::get('admin/reports', [AdminReportsController::class, 'index'])->name('admin.reports');
    Route::patch('admin/products/{normalizedProduct}', [AdminReportsController::class, 'update'])->name('admin.products.update');

    Route::get('admin/scrape', [ScrapeController::class, 'index'])->name('admin.scrape.index');
    Route::post('admin/scrape/run', [ScrapeController::class, 'runStore'])->name('admin.scrape.run');
    Route::post('admin/scrape/normalize', [ScrapeController::class, 'normalize'])->name('admin.scrape.normalize');
    Route::post('admin/scrape/all', [ScrapeController::class, 'runAll'])->name('admin.scrape.all');
});

require __DIR__.'/settings.php';
