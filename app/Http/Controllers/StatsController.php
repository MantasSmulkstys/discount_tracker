<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StatsController extends Controller
{
    private const CHANGES_LIMIT = 30;

    public function __invoke(): Response
    {
        return Inertia::render('kainu/Statistics', [
            'biggestChanges' => $this->biggestChanges(),
            'summary' => $this->summary(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function biggestChanges(): array
    {
        $latestIds = DB::table('price_changes')
            ->whereNotNull('change_pct')
            ->where('change_pct', '!=', 0)
            ->groupBy('product_variant_id', 'store_id')
            ->selectRaw('MAX(id) as id');

        $rows = DB::table('price_changes')
            ->join('product_variants', 'product_variants.id', '=', 'price_changes.product_variant_id')
            ->join('normalized_products', 'normalized_products.id', '=', 'product_variants.normalized_product_id')
            ->join('stores', 'stores.id', '=', 'price_changes.store_id')
            ->whereIn('price_changes.id', $latestIds)
            ->orderByRaw('ABS(price_changes.change_pct) DESC')
            ->limit(self::CHANGES_LIMIT)
            ->get([
                'price_changes.id',
                'price_changes.previous_price',
                'price_changes.new_price',
                'price_changes.change_pct',
                'price_changes.week_starting',
                'price_changes.snapshot_id',
                'normalized_products.canonical_name as product_name',
                'normalized_products.brand',
                'normalized_products.image_url',
                'product_variants.variant_name',
                'stores.name as store_name',
            ]);

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'snapshotId' => $row->snapshot_id !== null ? (int) $row->snapshot_id : null,
            'productName' => (string) $row->product_name,
            'brand' => $row->brand ?? null,
            'variant' => $row->variant_name ?? null,
            'image' => $row->image_url ?? null,
            'previousPrice' => $row->previous_price !== null ? (float) $row->previous_price : null,
            'newPrice' => (float) $row->new_price,
            'changePct' => (float) $row->change_pct,
            'weekStarting' => $row->week_starting,
            'storeName' => Str::title((string) $row->store_name),
            'storeSlug' => Str::slug((string) $row->store_name),
        ])->all();
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        return [
            'totalProducts' => (int) DB::table('normalized_products')->count(),
            'totalStores' => (int) DB::table('stores')->count(),
            'totalSnapshots' => (int) DB::table('offer_snapshots')->count(),
            'totalChanges' => (int) DB::table('price_changes')
                ->whereNotNull('change_pct')
                ->where('change_pct', '!=', 0)
                ->count(),
            'totalDrops' => (int) DB::table('price_changes')
                ->where('change_pct', '<', 0)
                ->count(),
            'totalRaises' => (int) DB::table('price_changes')
                ->where('change_pct', '>', 0)
                ->count(),
        ];
    }
}
