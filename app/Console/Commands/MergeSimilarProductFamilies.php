<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

#[Signature('products:merge-similar-families {--apply : Actually perform the merges} {--threshold=75 : Similarity percentage to merge at (0-100)}')]
#[Description('Merge product_families rows that look like the same item under different phrasings.')]
class MergeSimilarProductFamilies extends Command
{
    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $threshold = (float) $this->option('threshold');
        if ($threshold < 50 || $threshold > 100) {
            $this->error('--threshold must be between 50 and 100.');

            return self::FAILURE;
        }

        $families = DB::table('product_families')
            ->leftJoin('normalized_products', 'normalized_products.family_id', '=', 'product_families.id')
            ->select([
                'product_families.id',
                'product_families.family_name',
                'product_families.normalized_family_name',
                'product_families.category_id',
            ])
            ->selectRaw('COUNT(normalized_products.id) as product_count')
            ->groupBy('product_families.id', 'product_families.family_name', 'product_families.normalized_family_name', 'product_families.category_id')
            ->get();

        if ($families->isEmpty()) {
            $this->info('No product families found.');

            return self::SUCCESS;
        }

        $clusters = $this->cluster($families, $threshold);

        $merges = [];
        foreach ($clusters as $cluster) {
            if (count($cluster) < 2) {
                continue;
            }
            usort($cluster, function (stdClass $a, stdClass $b): int {
                return (int) $b->product_count <=> (int) $a->product_count
                    ?: (int) $a->id <=> (int) $b->id;
            });
            $winner = $cluster[0];
            $losers = array_slice($cluster, 1);
            $merges[] = ['winner' => $winner, 'losers' => $losers];
        }

        if ($merges === []) {
            $this->info("No merge candidates at >= {$threshold}% similarity.");

            return self::SUCCESS;
        }

        $this->table(
            ['Winner (kept)', 'Products', 'Losers (merged in)'],
            array_map(fn (array $m): array => [
                $m['winner']->family_name." (id {$m['winner']->id})",
                (int) $m['winner']->product_count,
                collect($m['losers'])
                    ->map(fn (stdClass $l): string => "{$l->family_name} (id {$l->id}, {$l->product_count}p)")
                    ->implode("\n"),
            ], $merges)
        );

        if (! $apply) {
            $this->newLine();
            $this->info('Dry-run only. Re-run with --apply to perform the merges above.');

            return self::SUCCESS;
        }

        $reassigned = 0;
        $deleted = 0;
        DB::transaction(function () use ($merges, &$reassigned, &$deleted): void {
            foreach ($merges as $merge) {
                /** @var stdClass $winner */
                $winner = $merge['winner'];
                foreach ($merge['losers'] as $loser) {
                    /** @var stdClass $loser */
                    $reassigned += DB::table('normalized_products')
                        ->where('family_id', $loser->id)
                        ->update(['family_id' => $winner->id]);
                    $deleted += DB::table('product_families')
                        ->where('id', $loser->id)
                        ->delete();
                }
            }
        });

        $this->info("Reassigned {$reassigned} normalized_products into surviving families.");
        $this->info("Deleted {$deleted} duplicate product_families rows.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, stdClass>  $families
     * @return array<int, array<int, stdClass>>
     */
    private function cluster($families, float $threshold): array
    {
        $byCategory = $families->groupBy(fn (stdClass $f): string => (string) ($f->category_id ?? 'null'));
        $clusters = [];

        foreach ($byCategory as $group) {
            $list = $group->values()->all();
            $n = count($list);
            $parent = range(0, $n - 1);

            $find = function (int $x) use (&$parent, &$find): int {
                while ($parent[$x] !== $x) {
                    $parent[$x] = $parent[$parent[$x]];
                    $x = $parent[$x];
                }

                return $x;
            };
            $union = function (int $x, int $y) use (&$parent, $find): void {
                $rx = $find($x);
                $ry = $find($y);
                if ($rx !== $ry) {
                    $parent[$rx] = $ry;
                }
            };

            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($this->similarity($list[$i]->normalized_family_name, $list[$j]->normalized_family_name) >= $threshold) {
                        $union($i, $j);
                    }
                }
            }

            $buckets = [];
            for ($i = 0; $i < $n; $i++) {
                $buckets[$find($i)][] = $list[$i];
            }
            foreach ($buckets as $bucket) {
                $clusters[] = $bucket;
            }
        }

        return $clusters;
    }

    private function similarity(?string $a, ?string $b): float
    {
        if ($a === null || $b === null) {
            return 0.0;
        }
        similar_text($a, $b, $percent);

        return (float) $percent;
    }
}
