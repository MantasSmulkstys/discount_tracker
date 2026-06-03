<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\KainuCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StatsController extends Controller
{
    private const CHANGES_LIMIT = 5;

    /**
     * Week-over-week swings beyond this magnitude are hidden from the
     * "biggest changes" feed — they are almost always a promotion starting or
     * ending rather than a genuine shelf-price move, and dominate the list.
     */
    private const MAX_CHANGE_PCT = 100;

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
     * Full list of price drops or raises behind the summary counts, fetched on
     * demand when the user opens the Atpigimai / Pabrangimai modal.
     */
    public function changes(Request $request): JsonResponse
    {
        $direction = (string) $request->string('direction');
        if (! in_array($direction, ['drops', 'raises'], true)) {
            abort(404);
        }

        $rows = $this->selectChangeColumns($this->changesQuery($direction))->get();

        return response()->json([
            'direction' => $direction,
            'changes' => $rows->map(fn ($row) => $this->mapChangeRow($row))->all(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function biggestChanges(): array
    {
        $floor = $this->catalog->visibilityFloor();

        $latestIds = DB::table('price_changes')
            ->whereNotNull('change_pct')
            ->where('change_pct', '!=', 0)
            ->whereRaw('ABS(change_pct) <= ?', [self::MAX_CHANGE_PCT])
            ->when($floor !== null, fn ($q) => $q->where('changed_at', '>=', $floor))
            ->tap(fn ($q) => $this->whereBaselineExists($q))
            ->groupBy('product_variant_id', 'store_id')
            ->selectRaw('MAX(id) as id');

        $query = DB::table('price_changes')
            ->join('product_variants', 'product_variants.id', '=', 'price_changes.product_variant_id')
            ->join('normalized_products', 'normalized_products.id', '=', 'product_variants.normalized_product_id')
            ->join('stores', 'stores.id', '=', 'price_changes.store_id')
            ->leftJoin('offer_snapshots as os_change', 'os_change.id', '=', 'price_changes.snapshot_id')
            ->leftJoin('raw_offers as ro_change', 'ro_change.id', '=', 'os_change.raw_offer_id')
            ->whereIn('price_changes.id', $latestIds)
            ->whereRaw('ABS(price_changes.change_pct) <= ?', [self::MAX_CHANGE_PCT])
            ->when($floor !== null, fn ($q) => $q->where('price_changes.changed_at', '>=', $floor))
            ->orderByRaw('ABS(price_changes.change_pct) DESC')
            ->limit(self::CHANGES_LIMIT);

        return $this->selectChangeColumns($query)
            ->get()
            ->map(fn ($row) => $this->mapChangeRow($row))
            ->all();
    }

    /**
     * Every price change in one direction ("drops" or "raises"), newest and
     * biggest first. Matches the totalDrops / totalRaises summary counts (same
     * baseline guard and floor, no top-N or ±100 % trimming), so the modal's
     * row count lines up with the headline figure.
     */
    private function changesQuery(string $direction): Builder
    {
        $floor = $this->catalog->visibilityFloor();

        return DB::table('price_changes')
            ->join('product_variants', 'product_variants.id', '=', 'price_changes.product_variant_id')
            ->join('normalized_products', 'normalized_products.id', '=', 'product_variants.normalized_product_id')
            ->join('stores', 'stores.id', '=', 'price_changes.store_id')
            ->leftJoin('offer_snapshots as os_change', 'os_change.id', '=', 'price_changes.snapshot_id')
            ->leftJoin('raw_offers as ro_change', 'ro_change.id', '=', 'os_change.raw_offer_id')
            ->whereNotNull('price_changes.change_pct')
            ->where('price_changes.change_pct', $direction === 'drops' ? '<' : '>', 0)
            ->when($floor !== null, fn ($q) => $q->where('price_changes.changed_at', '>=', $floor))
            ->tap(fn ($q) => $this->whereBaselineExists($q))
            ->orderByDesc('price_changes.week_starting')
            ->orderByRaw('ABS(price_changes.change_pct) DESC');
    }

    /**
     * @param  Builder  $query
     */
    private function selectChangeColumns($query): Builder
    {
        return $query
            ->select([
                'price_changes.id',
                'price_changes.previous_price',
                'price_changes.new_price',
                'price_changes.change_pct',
                'price_changes.week_starting',
                'price_changes.snapshot_id',
                'normalized_products.canonical_name as product_name',
                'ro_change.name as raw_name',
                'normalized_products.brand',
                'product_variants.variant_name',
                'stores.name as store_name',
            ])
            ->selectRaw('COALESCE(ro_change.image, normalized_products.image_url) AS image_url');
    }

    /**
     * @return array<string, mixed>
     */
    private function mapChangeRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'snapshotId' => $row->snapshot_id !== null ? (int) $row->snapshot_id : null,
            'productName' => (string) $row->product_name,
            'rawName' => filled($row->raw_name ?? null) ? (string) $row->raw_name : (string) $row->product_name,
            'brand' => $row->brand ?? null,
            'variant' => $row->variant_name ?? null,
            'image' => $row->image_url ?? null,
            'previousPrice' => $row->previous_price !== null ? (float) $row->previous_price : null,
            'newPrice' => (float) $row->new_price,
            'changePct' => (float) $row->change_pct,
            'weekStarting' => $row->week_starting,
            'storeName' => Str::title((string) $row->store_name),
            'storeSlug' => Str::slug((string) $row->store_name),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        $floor = $this->catalog->visibilityFloor();

        return [
            'totalProducts' => (int) $this->catalog->offersQuery()->count(),
            'totalStores' => (int) DB::table('stores')->count(),
            'totalSnapshots' => (int) DB::table('offer_snapshots')
                ->when($floor !== null, fn ($q) => $q->where('scraped_at', '>=', $floor))
                ->count(),
            'totalChanges' => (int) DB::table('price_changes')
                ->whereNotNull('change_pct')
                ->where('change_pct', '!=', 0)
                ->when($floor !== null, fn ($q) => $q->where('changed_at', '>=', $floor))
                ->tap(fn ($q) => $this->whereBaselineExists($q))
                ->count(),
            'totalDrops' => (int) DB::table('price_changes')
                ->where('change_pct', '<', 0)
                ->when($floor !== null, fn ($q) => $q->where('changed_at', '>=', $floor))
                ->tap(fn ($q) => $this->whereBaselineExists($q))
                ->count(),
            'totalRaises' => (int) DB::table('price_changes')
                ->where('change_pct', '>', 0)
                ->when($floor !== null, fn ($q) => $q->where('changed_at', '>=', $floor))
                ->tap(fn ($q) => $this->whereBaselineExists($q))
                ->count(),
        ];
    }

    /**
     * Keep only findings whose "previous price" data point still exists.
     *
     * A price_changes row stores its baseline as a literal `previous_price`
     * plus a `snapshot_id` pointing at the *new* (current) snapshot — never at
     * the prior one it was compared against. So when an admin deletes a
     * product's earlier price history, the finding is left orphaned: it keeps
     * claiming a change against a price that no longer has a snapshot. This
     * guard hides such findings by requiring a matching earlier snapshot to
     * still exist for the same variant+store.
     *
     * @param  Builder  $query
     */
    private function whereBaselineExists($query): void
    {
        $query->whereExists(function ($sub): void {
            $sub->select(DB::raw(1))
                ->from('offer_snapshots as os_base')
                ->whereColumn('os_base.product_variant_id', 'price_changes.product_variant_id')
                ->whereColumn('os_base.store_id', 'price_changes.store_id')
                ->whereColumn('os_base.week_starting', '<', 'price_changes.week_starting')
                ->whereColumn('os_base.price', 'price_changes.previous_price');
        });
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
