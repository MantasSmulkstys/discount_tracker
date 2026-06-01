<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FamilyKeySeeder extends Seeder
{
    /**
     * Seed the controlled family-key vocabulary into `product_families`.
     *
     * Each entry maps a normalized family key (the diacritic-stripped match key
     * the scraper assigns, e.g. `cesnakai`) to a proper Lithuanian display name
     * (`Česnakai`). The key is the stable identity in `normalized_family_name`;
     * the display name lands in `family_name`.
     *
     * This is intentionally non-destructive: existing families are matched by
     * `normalized_family_name` and only their `family_name` is refreshed —
     * `category_id`, `subcategory_id`, `generic_name` and any product links are
     * left untouched. Rows that exist in the DB but not in the JSON are never
     * deleted (use the scraper's `prune_empty_families` for that).
     */
    public function run(): void
    {
        $path = database_path('data/family_keys.json');
        if (! is_file($path)) {
            $this->command?->warn("family_keys.json not found at {$path}; skipping.");

            return;
        }

        /** @var array<string, string> $names */
        $names = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $now = now();
        $inserted = 0;
        $updated = 0;

        foreach ($names as $key => $displayName) {
            $key = trim((string) $key);
            $displayName = trim((string) $displayName);
            if ($key === '' || $displayName === '') {
                continue;
            }

            $existingId = DB::table('product_families')
                ->where('normalized_family_name', $key)
                ->value('id');

            if ($existingId !== null) {
                DB::table('product_families')
                    ->where('id', $existingId)
                    ->update([
                        'family_name' => $displayName,
                        'updated_at' => $now,
                    ]);
                $updated++;

                continue;
            }

            DB::table('product_families')->insert([
                'family_name' => $displayName,
                'normalized_family_name' => $key,
                'generic_name' => $key,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $inserted++;
        }

        $this->command?->info("Family keys seeded: {$inserted} inserted, {$updated} updated.");
    }
}
