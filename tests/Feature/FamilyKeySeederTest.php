<?php

use Database\Seeders\FamilyKeySeeder;
use Illuminate\Support\Facades\DB;

test('seeder fills proper Lithuanian display names for vocabulary keys', function () {
    $this->seed(FamilyKeySeeder::class);

    $name = DB::table('product_families')
        ->where('normalized_family_name', 'cesnakai')
        ->value('family_name');

    expect($name)->toBe('Česnakai');
});

test('seeder refreshes an existing family name without duplicating or losing its links', function () {
    $existingId = DB::table('product_families')->insertGetId([
        'family_name' => 'Cesnakai',
        'normalized_family_name' => 'cesnakai',
        'generic_name' => 'cesnakai',
        'category_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->seed(FamilyKeySeeder::class);

    $rows = DB::table('product_families')->where('normalized_family_name', 'cesnakai')->get();
    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect((int) $row->id)->toBe($existingId);
    expect($row->family_name)->toBe('Česnakai');
    // generic_name is left untouched for already-present families.
    expect($row->generic_name)->toBe('cesnakai');
});

test('seeder is idempotent', function () {
    $this->seed(FamilyKeySeeder::class);
    $afterFirst = DB::table('product_families')->count();

    $this->seed(FamilyKeySeeder::class);
    $afterSecond = DB::table('product_families')->count();

    expect($afterSecond)->toBe($afterFirst);

    $distinctKeys = DB::table('product_families')->distinct()->count('normalized_family_name');
    expect($distinctKeys)->toBe($afterFirst);
});
