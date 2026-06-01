<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            StoreSeeder::class,
            CategorySeeder::class,
            FamilyKeySeeder::class,
            UserSeeder::class,
        ]);
    }
}
