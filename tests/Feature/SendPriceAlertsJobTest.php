<?php

declare(strict_types=1);

use App\Jobs\SendPriceAlertsJob;
use App\Mail\PriceAlertDigest;
use App\Models\FamilySubscription;
use App\Models\NormalizedProduct;
use App\Models\NotificationLog;
use App\Models\ProductFamily;
use App\Models\Store;
use App\Models\User;
use App\Services\KainuCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

function seedFreshOffer(NormalizedProduct $product, Store $store, float $price, ?float $previousPrice = null): int
{
    $variantId = DB::table('product_variants')->insertGetId([
        'normalized_product_id' => $product->id,
        'variant_name' => 'default',
        'size_unit' => 'l',
        'size_value' => 1.0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($previousPrice !== null) {
        DB::table('offer_snapshots')->insertGetId([
            'product_variant_id' => $variantId,
            'store_id' => $store->id,
            'week_starting' => now()->subWeek()->startOfWeek()->toDateString(),
            'price' => $previousPrice,
            'is_promotion' => false,
            'loyalty_card' => false,
            'scraped_at' => now()->subDays(1),
            'created_at' => now()->subDays(1),
        ]);
    }

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

    return $snapshotId;
}

test('job queues mail when a subscribed family has a fresh new offer', function () {
    Mail::fake();

    $user = User::factory()->create();
    $store = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);
    $family = ProductFamily::create([
        'family_name' => 'Pienas',
        'normalized_family_name' => 'pienas-'.uniqid(),
    ]);
    $product = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
        'canonical_name' => 'Pienas 2.5%',
    ]);

    FamilySubscription::create([
        'user_id' => $user->id,
        'family_id' => $family->id,
    ]);

    seedFreshOffer($product, $store, 1.29);

    (new SendPriceAlertsJob)->handle(app(KainuCatalog::class));

    Mail::assertQueued(PriceAlertDigest::class, function (PriceAlertDigest $mail) use ($user): bool {
        return $mail->hasTo($user->email);
    });

    expect(NotificationLog::count())->toBe(1);
});

test('job does not re-queue mail when the offer was already sent', function () {
    Mail::fake();

    $user = User::factory()->create();
    $store = Store::factory()->create(['name' => 'maxima', 'slug' => 'maxima']);
    $family = ProductFamily::create([
        'family_name' => 'Pienas',
        'normalized_family_name' => 'pienas-'.uniqid(),
    ]);
    $product = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
    ]);

    FamilySubscription::create([
        'user_id' => $user->id,
        'family_id' => $family->id,
    ]);

    seedFreshOffer($product, $store, 1.29);

    (new SendPriceAlertsJob)->handle(app(KainuCatalog::class));
    Mail::assertQueuedCount(1);

    Mail::fake();
    (new SendPriceAlertsJob)->handle(app(KainuCatalog::class));
    Mail::assertNothingQueued();
});

test('a price drop on a tracked family triggers a price_drop alert', function () {
    Mail::fake();

    $user = User::factory()->create();
    $store = Store::factory()->create(['name' => 'rimi', 'slug' => 'rimi']);
    $family = ProductFamily::create([
        'family_name' => 'Sviestas',
        'normalized_family_name' => 'sviestas-'.uniqid(),
    ]);
    $product = NormalizedProduct::factory()->create([
        'family_id' => $family->id,
    ]);

    FamilySubscription::create([
        'user_id' => $user->id,
        'family_id' => $family->id,
    ]);

    seedFreshOffer($product, $store, 1.99, previousPrice: 2.49);

    (new SendPriceAlertsJob)->handle(app(KainuCatalog::class));

    Mail::assertQueued(PriceAlertDigest::class);
    expect(NotificationLog::where('trigger_type', 'price_drop')->count())->toBe(1);
});

test('users without subscriptions are skipped', function () {
    Mail::fake();
    User::factory()->create();

    (new SendPriceAlertsJob)->handle(app(KainuCatalog::class));

    Mail::assertNothingQueued();
});
