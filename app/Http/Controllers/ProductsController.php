<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NormalizedProduct;
use App\Models\OfferSnapshot;
use App\Models\ProductReport;
use App\Services\AdminProductPayload;
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

class ProductsController extends Controller
{
    public function __construct(
        private readonly KainuCatalog $catalog,
        private readonly AdminProductPayload $adminPayload,
    ) {}

    public function index(Request $request): Response
    {
        if (! $this->catalogTablesReady()) {
            return $this->renderEmpty($request);
        }

        $category = (string) $request->string('category')->lower();
        $subcategory = (string) $request->string('subcategory')->lower();
        $search = trim((string) $request->string('q'));
        $selectedStores = collect(explode(',', (string) $request->string('stores')))
            ->map(fn (string $s): string => Str::lower(trim($s)))
            ->filter()
            ->values();
        $discountOnly = (bool) $request->boolean('discount');
        $maxPrice = (float) $request->input('max_price', 0);
        $sort = (string) $request->string('sort', 'discount');

        $query = $this->catalog->offersQuery();
        $query = $this->catalog->applyCategory($query, $category, $subcategory);

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

        $reportedIds = auth()->check()
            ? ProductReport::where('user_id', auth()->id())
                ->pluck('normalized_product_id')
                ->all()
            : [];

        return Inertia::render('kainu/Products/Index', [
            'products' => [
                'data' => $products,
                'meta' => $this->paginatorMeta($paginator),
                'links' => $this->paginatorLinks($paginator),
            ],
            'allStores' => $this->storesWithCounts(),
            'categoryTree' => $this->catalog->loadCategoryTree(),
            'filters' => [
                'category' => $category ?: 'all',
                'subcategory' => $subcategory ?: 'all',
                'q' => $search,
                'stores' => $selectedStores->all(),
                'discount' => $discountOnly,
                'maxPrice' => $maxPrice > 0 ? $maxPrice : null,
                'sort' => $sort,
            ],
            'reportedIds' => $reportedIds,
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
            $row = DB::table('offer_snapshots')
                ->join('product_variants', 'product_variants.id', '=', 'offer_snapshots.product_variant_id')
                ->join('normalized_products', 'normalized_products.id', '=', 'product_variants.normalized_product_id')
                ->join('stores', 'stores.id', '=', 'offer_snapshots.store_id')
                ->leftJoin('raw_offers', 'raw_offers.id', '=', 'offer_snapshots.raw_offer_id')
                ->leftJoin('product_families', 'product_families.id', '=', 'normalized_products.family_id')
                ->where('offer_snapshots.id', $id)
                ->select([
                    'offer_snapshots.id',
                    'offer_snapshots.price',
                    'offer_snapshots.old_price',
                    'offer_snapshots.discount_percent',
                    'offer_snapshots.condition',
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
                    'normalized_products.family_id',
                    'stores.id as store_db_id',
                    'stores.name as store_name',
                    'raw_offers.url as source_url',
                    'raw_offers.name as raw_product_name',
                    'product_families.family_name',
                    'product_families.generic_name as family_generic_name',
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
                ->selectRaw('COALESCE(raw_offers.image, normalized_products.image_url) AS image_url')
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

        $real = $this->catalog->priceHistory(
            (int) $row->normalized_product_id,
            isset($row->store_db_id) ? (int) $row->store_db_id : null,
        );

        $reportedIds = auth()->check()
            ? ProductReport::where('user_id', auth()->id())
                ->pluck('normalized_product_id')
                ->all()
            : [];

        $user = auth()->user();
        $adminEdit = null;
        $adminCategories = null;
        $adminFamilies = null;
        if ($user && $user->isAdmin()) {
            $model = NormalizedProduct::find((int) $row->normalized_product_id);
            if ($model) {
                $adminEdit = $this->adminPayload->forProduct($model);
                $adminCategories = $this->catalog->loadCategoryTree();
                $adminFamilies = $this->catalog->loadFamilies();
            }
        }

        return Inertia::render('kainu/Products/Show', [
            'product' => $product,
            'offers' => $priceOffers,
            'history' => $real['values'],
            'historyLabels' => $real['labels'],
            'historyDates' => $real['dates'],
            'historyIsReal' => $real['isReal'],
            'reportedIds' => $reportedIds,
            'adminEdit' => $adminEdit,
            'adminCategories' => $adminCategories,
            'adminFamilies' => $adminFamilies,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function latestOffersForNormalizedProduct(int $normalizedProductId): Collection
    {
        $productIds = $this->familySiblingProductIds($normalizedProductId);

        $query = DB::table('offer_snapshots')
            ->join('product_variants', 'product_variants.id', '=', 'offer_snapshots.product_variant_id')
            ->join('stores', 'stores.id', '=', 'offer_snapshots.store_id')
            ->leftJoin('raw_offers', 'raw_offers.id', '=', 'offer_snapshots.raw_offer_id')
            ->whereIn('product_variants.normalized_product_id', $productIds)
            ->whereIn('offer_snapshots.id', OfferSnapshot::latestIdsPerProductStoreSubquery())
            ->whereNotNull('offer_snapshots.price');

        $this->catalog->applyFreshness($query);
        $this->catalog->applyVisibilityFloor($query);

        return $query
            ->join('normalized_products', 'normalized_products.id', '=', 'product_variants.normalized_product_id')
            ->orderBy('offer_snapshots.price')
            ->select([
                'offer_snapshots.id',
                'offer_snapshots.price',
                'offer_snapshots.old_price',
                'offer_snapshots.condition',
                'product_variants.normalized_product_id as variant_product_id',
                'product_variants.size_value',
                'product_variants.size_unit',
                'normalized_products.canonical_name as product_name',
                'stores.name as store_name',
                'raw_offers.url as source_url',
                'raw_offers.name as raw_product_name',
            ])
            ->selectRaw('COALESCE(raw_offers.image, normalized_products.image_url) AS product_image')
            ->get()
            ->map(fn (object $offer): array => [
                'id' => (int) $offer->id,
                'productId' => (int) $offer->variant_product_id,
                'productName' => $this->preferRawName($offer),
                'imageUrl' => $offer->product_image,
                'store' => Str::slug((string) $offer->store_name),
                'storeName' => Str::title((string) $offer->store_name),
                'price' => (float) $offer->price,
                'oldPrice' => $offer->old_price !== null ? (float) $offer->old_price : null,
                'condition' => $offer->condition,
                'url' => $offer->source_url,
                'sizeLabel' => $this->formatSize($offer->size_value, $offer->size_unit),
                'unitPriceLabel' => $this->formatUnitPrice((float) $offer->price, $offer->size_value, $offer->size_unit),
                'isExactMatch' => (int) $offer->variant_product_id === $normalizedProductId,
            ])
            ->values();
    }

    /**
     * The store's raw scraped name when present, otherwise the canonical name.
     * Mirrors KainuCatalog::hydrate()'s displayName so the comparison cards
     * show the same wording as the listing.
     */
    private function preferRawName(object $offer): string
    {
        $raw = isset($offer->raw_product_name)
            ? trim((string) $offer->raw_product_name)
            : '';

        return $raw !== '' ? $raw : (string) $offer->product_name;
    }

    private function formatUnitPrice(float $price, mixed $sizeValue, mixed $sizeUnit): ?string
    {
        if ($sizeValue === null || $sizeUnit === null || $sizeUnit === '' || (float) $sizeValue <= 0) {
            return null;
        }

        $unit = mb_strtolower(trim((string) $sizeUnit));

        // Convert the size to a base unit (kg / l) so the resulting price is the
        // familiar €/kg or €/l rather than a tiny €/g or €/ml figure.
        [$factor, $displayUnit] = match ($unit) {
            'mg' => [0.000001, 'kg'],
            'g' => [0.001, 'kg'],
            'kg' => [1.0, 'kg'],
            'ml' => [0.001, 'l'],
            'cl' => [0.01, 'l'],
            'dl' => [0.1, 'l'],
            'l' => [1.0, 'l'],
            default => [1.0, $unit],
        };

        $baseQuantity = (float) $sizeValue * $factor;
        if ($baseQuantity <= 0) {
            return null;
        }

        $perUnit = $price / $baseQuantity;

        return number_format($perUnit, 2, ',', '').' €/'.$displayUnit;
    }

    /**
     * The set of normalized products that should appear in the comparison.
     *
     * Membership = (same AI family) OR (same subcategory/category that shares
     * the family's core noun, e.g. "vanduo" links "gazuotas vanduo" and
     * "mineralinis vanduo"), then narrowed to the same measurement dimension
     * (volume / weight / count) so we still compare like with like.
     *
     * @return array<int, int>
     */
    private function familySiblingProductIds(int $normalizedProductId): array
    {
        $self = DB::table('normalized_products as np')
            ->leftJoin('product_variants as pv', 'pv.normalized_product_id', '=', 'np.id')
            ->leftJoin('product_families as pf', 'pf.id', '=', 'np.family_id')
            ->where('np.id', $normalizedProductId)
            ->select([
                'np.family_id',
                'np.subcategory_id',
                'np.category_id',
                'np.generic_name as np_generic',
                'pv.size_unit',
                'pf.family_name',
                'pf.generic_name as family_generic',
            ])
            ->first();

        if (! $self) {
            return [$normalizedProductId];
        }

        $dimension = $this->unitDimension($self->size_unit);
        // Prefer the AI generic_name as the token source: it is the bare head
        // noun ("sviestas", "vanduo"), so it survives names where the noun is
        // not last, e.g. "sviestas su druska" (whose last word is "druska").
        $coreToken = $this->familyCoreToken(
            $self->np_generic ?? $self->family_generic ?? $self->family_name
        );

        [$scopeColumn, $scopeValue] = match (true) {
            $self->subcategory_id !== null => ['np.subcategory_id', (int) $self->subcategory_id],
            $self->category_id !== null => ['np.category_id', (int) $self->category_id],
            default => [null, null],
        };

        $hasFamily = $self->family_id !== null;
        $hasTextMatch = $coreToken !== null && $scopeColumn !== null;

        if (! $hasFamily && ! $hasTextMatch) {
            return [$normalizedProductId];
        }

        $candidates = DB::table('normalized_products as np')
            ->join('product_variants as pv', 'pv.normalized_product_id', '=', 'np.id')
            ->leftJoin('product_families as pf', 'pf.id', '=', 'np.family_id')
            ->where(function ($q) use ($hasFamily, $self, $hasTextMatch, $scopeColumn, $scopeValue, $coreToken): void {
                if ($hasFamily) {
                    $q->where('np.family_id', (int) $self->family_id);
                }

                if ($hasTextMatch) {
                    $like = '%'.$coreToken.'%';
                    $q->orWhere(function ($q2) use ($scopeColumn, $scopeValue, $like): void {
                        $q2->where($scopeColumn, $scopeValue)
                            ->where(function ($q3) use ($like): void {
                                $q3->whereRaw('LOWER(pf.family_name) LIKE ?', [$like])
                                    ->orWhereRaw('LOWER(pf.generic_name) LIKE ?', [$like])
                                    ->orWhereRaw('LOWER(np.generic_name) LIKE ?', [$like])
                                    ->orWhereRaw('LOWER(np.canonical_name) LIKE ?', [$like]);
                            });
                    });
                }
            })
            ->select(['np.id', 'pv.size_unit'])
            ->get();

        $ids = [$normalizedProductId];
        foreach ($candidates as $row) {
            if ($this->dimensionsCompatible($this->unitDimension($row->size_unit), $dimension)) {
                $ids[] = (int) $row->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Whether two measurement dimensions can be compared. "none" (a product
     * whose size never parsed) is a wildcard so size-less offers are not hidden,
     * while weight vs volume stay separated for an honest comparison.
     */
    private function dimensionsCompatible(string $a, string $b): bool
    {
        return $a === $b || $a === 'none' || $b === 'none';
    }

    /**
     * Group measurement units into comparable dimensions so litres and
     * millilitres (or grams and kilograms) land in the same bucket.
     */
    private function unitDimension(mixed $unit): string
    {
        $u = mb_strtolower(trim((string) $unit));

        return match ($u) {
            'mg', 'g', 'kg' => 'weight',
            'ml', 'cl', 'dl', 'l' => 'volume',
            'vnt', 'pcs', 'pc', 'units' => 'count',
            '' => 'none',
            default => $u,
        };
    }

    /**
     * The core noun of a family name — the last token, which in Lithuanian is
     * the head noun ("gazuotas vanduo" → "vanduo"). Returns null when there is
     * no usable token to avoid over-broad matching.
     */
    private function familyCoreToken(?string $familyName): ?string
    {
        if ($familyName === null) {
            return null;
        }

        $tokens = preg_split('/\s+/', mb_strtolower(trim($familyName)), -1, PREG_SPLIT_NO_EMPTY);
        if (! $tokens) {
            return null;
        }

        $core = (string) end($tokens);

        return mb_strlen($core) >= 4 ? $core : null;
    }

    private function formatSize(mixed $value, mixed $unit): ?string
    {
        if ($value === null || $unit === null || $unit === '') {
            return null;
        }
        $num = rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');

        return $num === '' ? null : "$num $unit";
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function storesWithCounts(): Collection
    {
        $counts = $this->catalog->baseOffersQuery()
            ->selectRaw('LOWER(stores.name) as store_key')
            ->selectRaw('COUNT(*) as offers_count')
            ->groupBy(DB::raw('LOWER(stores.name)'));

        return DB::table('stores')
            ->leftJoinSub($counts, 'store_counts', 'store_counts.store_key', '=', DB::raw('LOWER(stores.name)'))
            ->selectRaw('stores.name as store_name')
            ->selectRaw('COALESCE(store_counts.offers_count, 0) as offers_count')
            ->orderByDesc('offers_count')
            ->get()
            ->map(fn (object $s): array => [
                'id' => Str::lower((string) $s->store_name),
                'name' => Str::title((string) $s->store_name),
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
