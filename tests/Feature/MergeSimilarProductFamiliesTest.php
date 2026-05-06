<?php

declare(strict_types=1);

use App\Models\NormalizedProduct;
use App\Models\ProductFamily;
use Illuminate\Support\Facades\DB;

function ensureCategory(int $id): void
{
    if (! DB::table('categories')->where('id', $id)->exists()) {
        DB::table('categories')->insert([
            'id' => $id,
            'name_lt' => "Kategorija $id",
            'slug' => "kategorija-$id",
        ]);
    }
}

function seedFamily(string $name, ?int $categoryId = null): ProductFamily
{
    if ($categoryId !== null) {
        ensureCategory($categoryId);
    }
    $normalized = strtolower(strtr($name, ['ė' => 'e', 'š' => 's', 'ų' => 'u', 'ą' => 'a', 'į' => 'i']));

    return ProductFamily::create([
        'family_name' => $name,
        'normalized_family_name' => $normalized,
        'category_id' => $categoryId,
    ]);
}

test('dry-run reports proposed merges without touching data', function () {
    $f1 = seedFamily('raudonosios vynuoges');
    $f2 = seedFamily('raudonos vynuoges');
    NormalizedProduct::factory()->create(['family_id' => $f1->id]);
    NormalizedProduct::factory()->create(['family_id' => $f2->id]);

    $this->artisan('products:merge-similar-families')
        ->expectsOutputToContain('Dry-run only')
        ->assertExitCode(0);

    expect(ProductFamily::count())->toBe(2);
});

test('apply merges similar families and reassigns products', function () {
    $f1 = seedFamily('raudonosios vynuoges');
    $f2 = seedFamily('raudonos vynuoges');
    $p1 = NormalizedProduct::factory()->create(['family_id' => $f1->id]);
    $p2 = NormalizedProduct::factory()->create(['family_id' => $f2->id]);
    $p3 = NormalizedProduct::factory()->create(['family_id' => $f2->id]);

    $this->artisan('products:merge-similar-families', ['--apply' => true])
        ->assertExitCode(0);

    expect(ProductFamily::count())->toBe(1);
    expect(ProductFamily::find($f2->id))->not->toBeNull();
    expect(ProductFamily::find($f1->id))->toBeNull();
    expect($p1->fresh()->family_id)->toBe($f2->id);
    expect($p2->fresh()->family_id)->toBe($f2->id);
    expect($p3->fresh()->family_id)->toBe($f2->id);
});

test('does not merge families across categories', function () {
    seedFamily('raudonosios vynuoges', categoryId: 1);
    seedFamily('raudonos vynuoges', categoryId: 2);

    $this->artisan('products:merge-similar-families', ['--apply' => true])
        ->assertExitCode(0);

    expect(ProductFamily::count())->toBe(2);
});

test('does not merge dietary variants like lactose-free vs regular', function () {
    seedFamily('pienas');
    seedFamily('pienas be laktozes');

    $this->artisan('products:merge-similar-families', ['--apply' => true])
        ->assertExitCode(0);

    expect(ProductFamily::count())->toBe(2);
});

test('threshold below 50 is rejected', function () {
    $this->artisan('products:merge-similar-families', ['--threshold' => 30])
        ->assertExitCode(1);
});
