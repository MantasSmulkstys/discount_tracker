<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OfferSnapshot;
use App\Services\KainuCatalog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public product listing + detail page, backed by the normalized
 * `offer_snapshots` / `normalized_products` / `stores` schema.
 *
 * The only identifier exposed to the URL is `offer_snapshots.id` — cross-store
 * price comparisons are resolved by walking back to `normalized_product_id`.
 */
class ProductsController extends Controller
{
    public function __construct(private readonly KainuCatalog $catalog) {}

    public function index(Request $request): Response
    {
        if (! $this->catalogTablesReady()) {
            return $this->renderEmpty($request);
        }

        $category = (string) $request->string('category')->lower();
        $search = trim((string) $request->string('q'));
        $selectedStores = collect(explode(',', (string) $request->string('stores')))
            ->map(fn (string $s): string => Str::lower(trim($s)))
            ->filter()
            ->values();
        $discountOnly = (bool) $request->boolean('discount');
        $maxPrice = (float) $request->input('max_price', 0);
        $sort = (string) $request->string('sort', 'discount');

        $query = $this->catalog->offersQuery();
        $query = $this->catalog->applyCategory($query, $category);

        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $q) use ($needle): void {
                $q->whereRaw('LOWER(normalized_products.canonical_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(normalized_products.normalized_name) LIKE ?', [$needle])
                    ->orWhereRaw("LOWER(COALESCE(normalized_products.brand, '')) LIKE ?", [$needle]);
            });
        }

        if ($selectedStores->isNotEmpty()) {
            $query->whereIn(DB::raw('LOWER(stores.name)'), $selectedStores->all());
        }

        if ($discountOnly) {
            $query->where(function (Builder $q): void {
                $q->whereNotNull('offer_snapshots.discount_percent')
                    ->orWhere(function (Builder $q2): void {
                        $q2->whereNotNull('offer_snapshots.old_price')
                            ->whereColumn('offer_snapshots.old_price', '>', 'offer_snapshots.price');
                    });
            });
        }

        if ($maxPrice > 0) {
            $query->where('offer_snapshots.price', '<=', $maxPrice);
        }

        $query = $this->applySort($query, $sort);

        $paginator = $query->paginate(12)->withQueryString();

        $products = collect($paginator->items())
            ->map(fn (object $row) => $this->catalog->hydrate($row))
            ->values();

        return Inertia::render('kainu/Products/Index', [
            'products' => [
                'data' => $products,
                'meta' => $this->paginatorMeta($paginator),
                'links' => $this->paginatorLinks($paginator),
            ],
            'allStores' => $this->storesWithCounts(),
            'filters' => [
                'category' => $category ?: 'all',
                'q' => $search,
                'stores' => $selectedStores->all(),
                'discount' => $discountOnly,
                'maxPrice' => $maxPrice > 0 ? $maxPrice : null,
                'sort' => $sort,
            ],
        ]);
    }

    public function show(int $id): Response
    {
        if (! $this->catalogTablesReady()) {
            throw new NotFoundHttpException('Produktas nerastas');
        }

        $row = $this->catalog
            ->offersQuery()
            ->where('offer_snapshots.id', $id)
            ->first();

        if (! $row) {
            // The user may have deep-linked to a snapshot that is no longer the
            // latest for its (product, store). Fall back to looking it up
            // without the "latest" restriction so the page still renders.
            $row = DB::table('offer_snapshots')
                ->join('product_variants', 'product_variants.id', '=', 'offer_snapshots.product_variant_id')
                ->join('normalized_products', 'normalized_products.id', '=', 'product_variants.normalized_product_id')
                ->join('stores', 'stores.id', '=', 'offer_snapshots.store_id')
                ->leftJoin('raw_offers', 'raw_offers.id', '=', 'offer_snapshots.raw_offer_id')
                ->where('offer_snapshots.id', $id)
                ->select([
                    'offer_snapshots.id',
                    'offer_snapshots.price',
                    'offer_snapshots.old_price',
                    'offer_snapshots.discount_percent',
                    'offer_snapshots.unit_price_value',
                    'offer_snapshots.unit_price_unit',
                    'offer_snapshots.valid_from',
                    'offer_snapshots.valid_to',
                    'offer_snapshots.loyalty_card',
                    'offer_snapshots.scraped_at',
                    'offer_snapshots.is_promotion',
                    'product_variants.variant_name',
                    'product_variants.size_value',
                    'product_variants.size_unit',
                    'normalized_products.id as normalized_product_id',
                    'normalized_products.canonical_name as product_name',
                    'normalized_products.normalized_name',
                    'normalized_products.brand',
                    'normalized_products.category_id',
                    'normalized_products.subcategory_id',
                    'normalized_products.image_url',
                    'stores.id as store_db_id',
                    'stores.name as store_name',
                    'raw_offers.url as source_url',
                ])
                ->selectRaw('
                    COALESCE(
                        offer_snapshots.discount_percent,
                        CASE
                            WHEN offer_snapshots.old_price IS NOT NULL
                                AND offer_snapshots.old_price > 0
                                AND offer_snapshots.price IS NOT NULL
                            THEN ROUND(((offer_snapshots.old_price - offer_snapshots.price) / offer_snapshots.old_price) * 100, 2)
                        END
                    ) as effective_discount
                ')
                ->first();
        }

        if (! $row) {
            throw new NotFoundHttpException('Produktas nerastas');
        }

        $product = $this->catalog->hydrate($row);

        $priceOffers = $this->latestOffersForNormalizedProduct((int) $row->normalized_product_id);

        if ($priceOffers->isEmpty() && $row->price !== null) {
            $priceOffers = collect([[
                'id' => (int) $row->id,
                'store' => Str::slug((string) $row->store_name),
                'storeName' => Str::title((string) $row->store_name),
                'price' => (float) $row->price,
                'oldPrice' => $row->old_price !== null ? (float) $row->old_price : null,
                'url' => $row->source_url,
            ]]);
        }

        $alternatives = $this->catalog
            ->offersQuery()
            ->where('offer_snapshots.id', '!=', $id)
            ->where('normalized_products.id', '!=', (int) $row->normalized_product_id)
            ->whereNotNull('offer_snapshots.price')
            ->when($product['category'] !== 'all', function (Builder $q) use ($product): void {
                $this->catalog->applyCategory($q, $product['category']);
            })
            ->inRandomOrder()
            ->limit(4)
            ->get()
            ->map(fn (object $r) => $this->catalog->hydrate($r))
            ->values();

        $current = $product['bestPrice'] ?? 0.0;
        $real = $this->catalog->priceHistory((int) $row->normalized_product_id);
        $isRealHistory = $real['isReal'];
        $history = $isRealHistory
            ? $real['values']
            : $this->catalog->simulatedHistory($current, $product['oldPrice']);
        $historyLabels = $isRealHistory ? $real['labels'] : [];

        $prediction = $this->predictNextPrice($history, $current, $isRealHistory);

        return Inertia::render('kainu/Products/Show', [
            'product' => $product,
            'offers' => $priceOffers,
            'alternatives' => $alternatives,
            'history' => $history,
            'historyLabels' => $historyLabels,
            'historyIsReal' => $isRealHistory,
            'prediction' => $prediction,
        ]);
    }

    /**
     * Build the prediction block from a price-history series.
     *
     * Real series: project the most recent slope (last 4 points if available,
     * otherwise as many as we have) onto the next period and clamp at -10%.
     * Simulated series: use the original heuristic to keep the demo coherent
     * when no scrape history exists yet.
     *
     * @param  array<int, float>  $history
     * @return array{
     *     willDrop: bool,
     *     predictedPrice: float,
     *     confidence: int,
     *     window: string,
     *     basedOn: string,
     * }
     */
    private function predictNextPrice(array $history, float $current, bool $isReal): array
    {
        if (! $isReal || count($history) < 2) {
            $points = max(count($history), 1);
            $tail = $history[count($history) - 1] ?? $current;
            $head = $history[max(0, count($history) - 4)] ?? $tail;
            $predicted = max($current * 0.9, round($current + ($tail - $head) / 1.5, 2));

            return [
                'willDrop' => $predicted < $current,
                'predictedPrice' => round($predicted, 2),
                'confidence' => 55,
                'window' => 'per artimiausias 2 savaites',
                'basedOn' => 'Modeliuota – nepakanka kainų istorijos',
            ];
        }

        $points = count($history);
        $window = min(4, $points);
        $tail = $history[$points - 1];
        $head = $history[$points - $window];
        $slope = ($tail - $head) / max($window - 1, 1);
        $predicted = max($current * 0.9, round($current + $slope, 2));

        $confidence = (int) round(min(95, 50 + ($points * 8)));

        return [
            'willDrop' => $predicted < $current,
            'predictedPrice' => round($predicted, 2),
            'confidence' => $confidence,
            'window' => 'per artimiausią savaitę',
            'basedOn' => 'Remiantis '.$points.' savaičių stebėjimais',
        ];
    }

    /**
     * Return the latest offer snapshot for the given normalized product, one
     * row per store, sorted cheapest first. Drives the "palyginimas"
     * comparison table on the detail page.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function latestOffersForNormalizedProduct(int $normalizedProductId): Collection
    {
        $query = DB::table('offer_snapshots')
            ->join('product_variants', 'product_variants.id', '=', 'offer_snapshots.product_variant_id')
            ->join('stores', 'stores.id', '=', 'offer_snapshots.store_id')
            ->leftJoin('raw_offers', 'raw_offers.id', '=', 'offer_snapshots.raw_offer_id')
            ->where('product_variants.normalized_product_id', $normalizedProductId)
            ->whereIn('offer_snapshots.id', OfferSnapshot::latestIdsPerProductStoreSubquery())
            ->whereNotNull('offer_snapshots.price');

        $this->catalog->applyFreshness($query);

        return $query
            ->orderBy('offer_snapshots.price')
            ->select([
                'offer_snapshots.id',
                'offer_snapshots.price',
                'offer_snapshots.old_price',
                'stores.name as store_name',
                'raw_offers.url as source_url',
            ])
            ->get()
            ->map(fn (object $offer): array => [
                'id' => (int) $offer->id,
                'store' => Str::slug((string) $offer->store_name),
                'storeName' => Str::title((string) $offer->store_name),
                'price' => (float) $offer->price,
                'oldPrice' => $offer->old_price !== null ? (float) $offer->old_price : null,
                'url' => $offer->source_url,
            ])
            ->values();
    }

    /**
     * Side panel: list every store together with how many current offers it
     * has in the normalized listing.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function storesWithCounts(): Collection
    {
        $latestIds = OfferSnapshot::latestIdsPerProductStoreSubquery();
        $today = now()->toDateString();
        $cutoff = now()->subDays(KainuCatalog::FRESHNESS_WINDOW_DAYS)->toDateString();

        return DB::table('stores')
            ->leftJoin('offer_snapshots', function ($join) use ($latestIds, $today, $cutoff): void {
                $join->on('offer_snapshots.store_id', '=', 'stores.id')
                    ->whereIn('offer_snapshots.id', $latestIds)
                    ->where(function ($q) use ($today): void {
                        $q->whereNull('offer_snapshots.valid_to')
                            ->orWhere('offer_snapshots.valid_to', '>=', $today);
                    })
                    ->where('offer_snapshots.scraped_at', '>=', $cutoff);
            })
            ->selectRaw('stores.id, stores.name, COUNT(offer_snapshots.id) as offers_count')
            ->groupBy('stores.id', 'stores.name')
            ->orderByDesc('offers_count')
            ->get()
            ->map(fn (object $s): array => [
                'id' => Str::lower((string) $s->name),
                'name' => Str::title((string) $s->name),
                'count' => (int) $s->offers_count,
            ])
            ->values();
    }

    private function catalogTablesReady(): bool
    {
        return Schema::hasTable('offer_snapshots')
            && Schema::hasTable('product_variants')
            && Schema::hasTable('normalized_products')
            && Schema::hasTable('stores');
    }

    private function renderEmpty(Request $request): Response
    {
        return Inertia::render('kainu/Products/Index', [
            'products' => [
                'data' => [],
                'meta' => [
                    'from' => null,
                    'to' => null,
                    'total' => 0,
                    'currentPage' => 1,
                    'lastPage' => 1,
                    'hasPages' => false,
                ],
                'links' => [],
            ],
            'allStores' => [],
            'filters' => [
                'category' => (string) ($request->string('category')->lower() ?: 'all'),
                'q' => trim((string) $request->string('q')),
                'stores' => [],
                'discount' => (bool) $request->boolean('discount'),
                'maxPrice' => null,
                'sort' => (string) $request->string('sort', 'discount'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paginatorMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'hasPages' => $paginator->hasPages(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function paginatorLinks(LengthAwarePaginator $paginator): array
    {
        return $paginator->linkCollection()
            ->map(fn (array $link): array => [
                'url' => $link['url'],
                'label' => strip_tags(html_entity_decode($link['label'], ENT_QUOTES | ENT_HTML5)),
                'active' => (bool) $link['active'],
            ])
            ->all();
    }

    private function applySort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'price_asc' => $query
                ->orderByRaw('offer_snapshots.price IS NULL')
                ->orderBy('offer_snapshots.price'),
            'price_desc' => $query
                ->orderByRaw('offer_snapshots.price IS NULL')
                ->orderByDesc('offer_snapshots.price'),
            'newest' => $query->orderByDesc('offer_snapshots.scraped_at'),
            default => $query
                ->orderByRaw('CASE WHEN COALESCE(offer_snapshots.discount_percent, offer_snapshots.old_price) IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('effective_discount')
                ->orderByDesc('offer_snapshots.scraped_at'),
        };
    }
}
