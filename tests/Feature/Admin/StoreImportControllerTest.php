<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Testing\File as TestFile;
use Illuminate\Support\Facades\File;

function adminUser(): User
{
    return User::factory()->create(['role' => UserRole::Admin]);
}

function uploadConfig(array $payload, string $name = 'aibe.json'): TestFile
{
    return TestFile::createWithContent($name, json_encode($payload));
}

beforeEach(function () {
    $tmp = sys_get_temp_dir().'/store-import-tests-'.uniqid();
    File::makeDirectory($tmp, recursive: true);
    config()->set('scraper.path', $tmp);
    $this->configsDir = $tmp.'/configs';
});

afterEach(function () {
    $root = (string) config('scraper.path');
    if ($root !== '' && File::isDirectory($root)) {
        File::deleteDirectory($root);
    }
});

test('non-admin cannot import a store', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->post('/admin/stores/import', ['config' => uploadConfig(['store' => 'aibe'])])
        ->assertForbidden();
});

test('guest cannot import a store', function () {
    $this->post('/admin/stores/import', ['config' => uploadConfig(['store' => 'aibe'])])
        ->assertRedirect('/login');
});

test('admin upload creates store row + writes config to disk', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->post('/admin/stores/import', [
            'config' => uploadConfig([
                'store' => 'aibe',
                'name' => 'Aibė',
                'url' => 'https://aibe.lt/akcijos',
                'type' => 'static',
            ]),
        ])
        ->assertRedirect(route('admin.scrape.index'))
        ->assertSessionHas('success');

    $store = Store::where('slug', 'aibe')->first();
    expect($store)->not->toBeNull();
    expect($store->name)->toBe('aibė');
    expect($store->is_active)->toBeTrue();
    expect($store->website_url)->toBe('https://aibe.lt/akcijos');
    expect($store->logo_path)->toBe('/store-logos/aibe.png');

    $configPath = $this->configsDir.'/aibe.json';
    expect(File::exists($configPath))->toBeTrue();
    expect(json_decode(File::get($configPath), true))
        ->toMatchArray(['store' => 'aibe', 'url' => 'https://aibe.lt/akcijos']);
});

test('upserting an existing store keeps the same row', function () {
    $admin = adminUser();
    $existing = Store::create([
        'slug' => 'aibe',
        'name' => 'old name',
        'is_active' => false,
    ]);

    $this->actingAs($admin)
        ->post('/admin/stores/import', [
            'config' => uploadConfig(['store' => 'aibe', 'name' => 'Aibė']),
        ])
        ->assertRedirect(route('admin.scrape.index'));

    expect(Store::where('slug', 'aibe')->count())->toBe(1);
    $store = $existing->fresh();
    expect($store->is_active)->toBeTrue();
    expect($store->name)->toBe('aibė');
});

test('rejects upload without a store slug', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->from('/admin/scrape')
        ->post('/admin/stores/import', [
            'config' => uploadConfig(['url' => 'https://aibe.lt']),
        ])
        ->assertRedirect('/admin/scrape')
        ->assertSessionHasErrors('config');

    expect(Store::where('slug', 'aibe')->exists())->toBeFalse();
});

test('rejects malformed JSON', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->from('/admin/scrape')
        ->post('/admin/stores/import', [
            'config' => TestFile::createWithContent('aibe.json', '{ not json'),
        ])
        ->assertRedirect('/admin/scrape')
        ->assertSessionHasErrors('config');
});

test('rejects missing file', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->from('/admin/scrape')
        ->post('/admin/stores/import', [])
        ->assertRedirect('/admin/scrape')
        ->assertSessionHasErrors('config');
});
