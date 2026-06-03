<?php

use App\Enums\UserRole;
use App\Jobs\NormalizePendingJob;
use App\Jobs\ScrapeStoreJob;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

function makeScrapeAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin]);
}

function seedStore(string $slug, ?string $name = null, bool $active = true): int
{
    return DB::table('stores')->insertGetId([
        'name' => $name ?? $slug,
        'slug' => $slug,
        'is_active' => $active,
        'scrape_cron' => '0 6 * * 1',
        'logo_path' => "/storage/store-logos/{$slug}.png",
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('non-admin cannot view scrape page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/scrape')
        ->assertForbidden();
});

test('guest cannot view scrape page', function () {
    $this->get('/admin/scrape')->assertRedirect('/login');
});

test('admin sees scrape page with store options', function () {
    seedStore('iki');
    seedStore('maxima');

    $this->actingAs(makeScrapeAdmin())
        ->get('/admin/scrape')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Scrape')
            ->has('stores', 2)
            ->where('stores.0.slug', 'iki')
        );
});

test('store logo path is resolved from the file on disk regardless of stored value', function () {
    // seedStore stores a stale "/storage/store-logos/maxima.png" value, but the
    // real file lives at public/store-logos/maxima.png.
    seedStore('maxima');

    $this->actingAs(makeScrapeAdmin())
        ->get('/admin/scrape')
        ->assertInertia(fn ($page) => $page
            ->where('stores.0.logoPath', '/store-logos/maxima.png')
        );
});

test('store logo resolution self-heals the wrong extension', function () {
    // The stored path assumes .png, but the real Iki logo is public/store-logos/iki.jpg.
    seedStore('iki');

    $this->actingAs(makeScrapeAdmin())
        ->get('/admin/scrape')
        ->assertInertia(fn ($page) => $page
            ->where('stores.0.logoPath', '/store-logos/iki.jpg')
        );
});

test('store without a logo file falls back to the stored path', function () {
    seedStore('nonexistent-store');

    $this->actingAs(makeScrapeAdmin())
        ->get('/admin/scrape')
        ->assertInertia(fn ($page) => $page
            ->where('stores.0.logoPath', '/storage/store-logos/nonexistent-store.png')
        );
});

test('inactive stores are hidden from the picker', function () {
    seedStore('iki');
    seedStore('lidl', active: false);

    $this->actingAs(makeScrapeAdmin())
        ->get('/admin/scrape')
        ->assertInertia(fn ($page) => $page
            ->has('stores', 1)
            ->where('stores.0.slug', 'iki')
        );
});

test('runStore dispatches scrape + normalize chain for selected store', function () {
    Bus::fake();

    seedStore('iki');

    $this->actingAs(makeScrapeAdmin())
        ->from('/admin/scrape')
        ->post('/admin/scrape/run', ['store' => 'iki'])
        ->assertRedirect('/admin/scrape')
        ->assertSessionHas('success');

    Bus::assertChained([ScrapeStoreJob::class, NormalizePendingJob::class]);
});

test('runStore rejects unknown store slug', function () {
    Bus::fake();

    $this->actingAs(makeScrapeAdmin())
        ->from('/admin/scrape')
        ->post('/admin/scrape/run', ['store' => 'doesnotexist'])
        ->assertSessionHasErrors('store');

    Bus::assertNothingDispatched();
});

test('normalize endpoint dispatches a single normalize job', function () {
    Bus::fake();

    $this->actingAs(makeScrapeAdmin())
        ->from('/admin/scrape')
        ->post('/admin/scrape/normalize')
        ->assertRedirect('/admin/scrape');

    Bus::assertDispatched(NormalizePendingJob::class);
});

test('runAll chains scrape jobs for every active store followed by normalize', function () {
    Bus::fake();

    seedStore('iki');
    seedStore('maxima');
    seedStore('lidl', active: false);

    $this->actingAs(makeScrapeAdmin())
        ->from('/admin/scrape')
        ->post('/admin/scrape/all')
        ->assertRedirect('/admin/scrape');

    Bus::assertChained([
        ScrapeStoreJob::class,
        ScrapeStoreJob::class,
        NormalizePendingJob::class,
    ]);
});

test('runAll without active stores returns an error', function () {
    Bus::fake();

    $this->actingAs(makeScrapeAdmin())
        ->from('/admin/scrape')
        ->post('/admin/scrape/all')
        ->assertRedirect('/admin/scrape')
        ->assertSessionHas('error');

    Bus::assertNothingDispatched();
});
