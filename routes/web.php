<?php

use App\Http\Controllers\Admin\ScrapeController;
use App\Http\Controllers\Admin\StoreImportController;
use App\Http\Controllers\AdminReportsController;
use App\Http\Controllers\Auth\GoogleAuthenticatedSessionController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\FamilySubscriptionsController;
use App\Http\Controllers\ProductReportController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\RoutePlanController;
use App\Http\Controllers\SavedCartsController;
use App\Http\Controllers\ShoppingListController;
use App\Http\Controllers\StatsController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Route::get('/_health', function () {
    $dbOk = false;
    try {
        $dbOk = Schema::hasTable('stores');
    } catch (Throwable) {
        $dbOk = false;
    }

    return response()->json([
        'status' => $dbOk ? 'ok' : 'degraded',
        'database' => $dbOk,
    ], $dbOk ? 200 : 503);
})->name('health');

Route::get('/', [ProductsController::class, 'index'])->name('home');
Route::get('/products', [ProductsController::class, 'index'])->name('products.index');
Route::get('/products/{id}', [ProductsController::class, 'show'])
    ->whereNumber('id')
    ->name('products.show');

Route::middleware('auth')->group(function () {
    Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('/cart/route-plan', RoutePlanController::class)
        ->middleware('throttle:10,1')
        ->name('cart.route-plan');

    Route::get('/statistika', StatsController::class)->name('stats.index');

    Route::get('/sarasai', [SavedCartsController::class, 'index'])->name('saved-carts.index');
    Route::post('/sarasai', [SavedCartsController::class, 'store'])->name('saved-carts.store');
    Route::patch('/sarasai/{savedCart}', [SavedCartsController::class, 'update'])->name('saved-carts.update');
    Route::delete('/sarasai/{savedCart}', [SavedCartsController::class, 'destroy'])->name('saved-carts.destroy');
    Route::post('/sarasai/{savedCart}/atkurti', [SavedCartsController::class, 'restore'])->name('saved-carts.restore');

    Route::get('/profilis/sekimai', [FamilySubscriptionsController::class, 'index'])->name('subscriptions.index');
    Route::post('/sekimai', [FamilySubscriptionsController::class, 'store'])->name('subscriptions.store');
    Route::delete('/sekimai/{familySubscription}', [FamilySubscriptionsController::class, 'destroy'])->name('subscriptions.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/products/{normalizedProduct}/report', [ProductReportController::class, 'store'])
        ->name('products.report');
    Route::delete('/products/{normalizedProduct}/report', [ProductReportController::class, 'destroy'])
        ->name('products.unreport');

    Route::post('/shopping-lists', [ShoppingListController::class, 'store'])
        ->name('shopping-lists.store');
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

    Route::post('admin/stores/import', [StoreImportController::class, 'store'])->name('admin.stores.import');
});

require __DIR__.'/settings.php';
