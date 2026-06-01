<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Store;
use Illuminate\Database\Seeder;

class StoreSeeder extends Seeder
{
    public function run(): void
    {
        $stores = [
            ['slug' => 'maxima', 'name' => 'maxima', 'website_url' => 'https://www.maxima.lt'],
            ['slug' => 'lidl', 'name' => 'lidl', 'website_url' => 'https://www.lidl.lt'],
            ['slug' => 'rimi', 'name' => 'rimi', 'website_url' => 'https://www.rimi.lt'],
            ['slug' => 'iki', 'name' => 'iki', 'website_url' => 'https://www.iki.lt'],
            ['slug' => 'norfa', 'name' => 'norfa', 'website_url' => 'https://www.norfa.lt'],
        ];

        foreach ($stores as $store) {
            Store::updateOrCreate(
                ['slug' => $store['slug']],
                [
                    'name' => $store['name'],
                    'website_url' => $store['website_url'],
                    'is_active' => true,
                ],
            );
        }
    }
}
