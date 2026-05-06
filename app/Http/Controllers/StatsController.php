<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\KainuCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StatsController extends Controller
{
    private const CHANGES_LIMIT = 5;

    public function __construct(private readonly KainuCatalog $catalog) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('kainu/Statistics', [
            'biggestChanges' => $this->biggestChanges(),
            'summary' => $this->summary(),
            'personal' => $user !== null ? $this->personal((int) $user->id) : null,
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
            'totalProducts' => (int) $this->catalog->offersQuery()->count(),
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

    /**
     * @return array<string, mixed>
     */
    private function personal(int $userId): array
    {
        $aggregates = DB::table('shopping_lists')
            ->where('user_id', $userId)
            ->selectRaw('COUNT(*) as lists_count, COALESCE(SUM(savings), 0) as total_savings, COALESCE(SUM(item_count), 0) as total_items')
            ->first();

        $listsCount = (int) ($aggregates->lists_count ?? 0);
        $totalSavings = (float) ($aggregates->total_savings ?? 0);
        $totalItems = (int) ($aggregates->total_items ?? 0);

        $lists = DB::table('shopping_lists')
            ->where('user_id', $userId)
            ->get(['items', 'store_ids']);

        $productCounts = [];
        $storeCounts = [];

        foreach ($lists as $list) {
            $items = json_decode((string) $list->items, true) ?: [];

            foreach ($items as $item) {
                $name = isset($item['name']) ? (string) $item['name'] : null;
                $qty = (int) ($item['qty'] ?? 1);

                if ($name !== null && $name !== '') {
                    $productCounts[$name] = ($productCounts[$name] ?? 0) + $qty;
                }

                $storeId = isset($item['storeId']) ? (string) $item['storeId'] : null;

                if ($storeId !== null && $storeId !== '') {
                    $storeCounts[$storeId] = ($storeCounts[$storeId] ?? 0) + 1;
                }
            }
        }

        arsort($productCounts);
        arsort($storeCounts);

        $topProductName = array_key_first($productCounts);
        $topProductQty = $topProductName !== null ? $productCounts[$topProductName] : 0;
        $topStoreId = array_key_first($storeCounts);

        return [
            'listsCount' => $listsCount,
            'totalSavings' => $totalSavings,
            'totalItems' => $totalItems,
            'topProduct' => $topProductName !== null
                ? ['name' => $topProductName, 'qty' => $topProductQty]
                : null,
            'topStoreId' => $topStoreId,
        ];
    }
}
