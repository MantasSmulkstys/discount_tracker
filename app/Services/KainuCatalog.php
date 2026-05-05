<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OfferSnapshot;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Maps rows from the normalized `offer_snapshots` pipeline into the product
 * DTO shape consumed by the Kainų seklys Vue pages.
 *
 * The service always queries through `offer_snapshots` joined with
 * `product_variants`, `normalized_products`, and `stores`, and filters down
 * to the **latest snapshot per (normalized product, store)** so the public
 * listing never shows duplicate rows from different scrape batches.
 *
 * `raw_offers` is intentionally **not** queried here — it remains a
 * scraper/admin-only table. We do left-join it as an optional deeplink source
 * for the product URL, which is the only user-facing field the normalized
 * pipeline does not yet surface.
 */
class KainuCatalog
{
    /**
     * Window of days that a snapshot stays "fresh" for the public listing.
     * Anything scraped before that drops out, even if its `valid_to` is null
     * or unset, so we don't keep showing month-old leaflet prices.
     */
    public const FRESHNESS_WINDOW_DAYS = 14;

    /**
     * Base query for the public listing. Starts from `offer_snapshots`, joins
     * `product_variants`, `normalized_products` and `stores`, and restricts to
     * the latest snapshot per (normalized product, store) pair.
     *
     * The row shape returned is consumed by {@see hydrate()} and is also the
     * source of truth for the columns requested by the frontend.
     */
    public function offersQuery(): Builder
    {
        $query = DB::table('offer_snapshots')
            ->join('product_variants', 'product_variants.id', '=', 'offer_snapshots.product_variant_id')
            ->join('normalized_products', 'normalized_products.id', '=', 'product_variants.normalized_product_id')
            ->join('stores', 'stores.id', '=', 'offer_snapshots.store_id')
            ->leftJoin('raw_offers', 'raw_offers.id', '=', 'offer_snapshots.raw_offer_id')
            ->whereIn('offer_snapshots.id', OfferSnapshot::latestIdsPerProductStoreSubquery());

        $this->applyFreshness($query);

        return $query
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
            ->selectRaw('
                (
                    SELECT os_prev.price
                    FROM offer_snapshots os_prev
                    WHERE os_prev.product_variant_id = offer_snapshots.product_variant_id
                      AND os_prev.store_id = offer_snapshots.store_id
                      AND os_prev.id < offer_snapshots.id
                      AND os_prev.price IS NOT NULL
                    ORDER BY os_prev.id DESC
                    LIMIT 1
                ) as previous_price
            ');
    }

    /**
     * Restrict an offers query to offers that are still relevant today:
     *
     * - `valid_to` is null or in the future, AND
     * - `scraped_at` is within {@see FRESHNESS_WINDOW_DAYS} days
     *
     * Both conditions are required: the first drops expired flyer dates, the
     * second drops stale rows that have no validity dates at all (~35% of
     * snapshots) once a couple of fresh batches have rolled in.
     */
    public function applyFreshness(Builder $query): Builder
    {
        $today = Carbon::now()->toDateString();
        $cutoff = Carbon::now()->subDays(self::FRESHNESS_WINDOW_DAYS)->toDateString();

        return $query
            ->where(function (Builder $q) use ($today): void {
                $q->whereNull('offer_snapshots.valid_to')
                    ->orWhere('offer_snapshots.valid_to', '>=', $today);
            })
            ->where('offer_snapshots.scraped_at', '>=', $cutoff);
    }

    /**
     * Load the full category taxonomy from the DB, grouped by parent.
     *
     * Returns an ordered list shaped for direct consumption by the frontend:
     * top-level categories with nested subcategories. Used by ProductsController
     * to render the filter UI.
     *
     * @return array<int, array{id: int, slug: string, name: string, subcategories: array<int, array{id: int, slug: string, name: string}>}>
     */
    public function loadCategoryTree(): array
    {
        $rows = DB::table('categories')
            ->leftJoin('subcategories', 'subcategories.category_id', '=', 'categories.id')
            ->orderBy('categories.name_lt')
            ->orderBy('subcategories.name_lt')
            ->get([
                'categories.id as category_id',
                'categories.name_lt as category_name',
                'categories.slug as category_slug',
                'subcategories.id as subcategory_id',
                'subcategories.name_lt as subcategory_name',
                'subcategories.slug as subcategory_slug',
            ]);

        $tree = [];
        foreach ($rows as $row) {
            $cid = (int) $row->category_id;

            if (! isset($tree[$cid])) {
                $tree[$cid] = [
                    'id' => $cid,
                    'slug' => (string) $row->category_slug,
                    'name' => $this->sentenceCase((string) $row->category_name),
                    'subcategories' => [],
                ];
            }

            if ($row->subcategory_id !== null) {
                $tree[$cid]['subcategories'][] = [
                    'id' => (int) $row->subcategory_id,
                    'slug' => (string) $row->subcategory_slug,
                    'name' => $this->sentenceCase((string) $row->subcategory_name),
                ];
            }
        }

        return array_values($tree);
    }

    /**
     * First-letter capitalised, rest lowercase — "MĖSA IR DEŠRA" → "Mėsa ir dešra".
     * Uses multibyte functions so Lithuanian letters (Š, Č, Ž …) are handled
     * correctly regardless of the stored case.
     */
    private function sentenceCase(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $lower = mb_strtolower($value);

        return mb_strtoupper(mb_substr($lower, 0, 1))
            .mb_substr($lower, 1);
    }

    /**
     * Filter offers by category slug and (optional) subcategory slug.
     *
     * Slug-only — no keyword fallback. Products whose AI normalization didn't
     * assign a category are simply absent from filtered views and only show
     * in the unfiltered listing.
     */
    public function applyCategory(Builder $query, ?string $categorySlug, ?string $subcategorySlug = null): Builder
    {
        if (! $categorySlug || $categorySlug === 'all') {
            return $query;
        }

        $categoryId = DB::table('categories')->where('slug', $categorySlug)->value('id');
        if ($categoryId === null) {
            return $query;
        }

        $query->where('normalized_products.category_id', (int) $categoryId);

        if ($subcategorySlug && $subcategorySlug !== 'all') {
            $subcategoryId = DB::table('subcategories')
                ->where('category_id', $categoryId)
                ->where('slug', $subcategorySlug)
                ->value('id');

            if ($subcategoryId !== null) {
                $query->where('normalized_products.subcategory_id', (int) $subcategoryId);
            }
        }

        return $query;
    }

    /**
     * Resolve the category slug for a product so the frontend knows which
     * filter pill to highlight when navigating to a related-products view.
     *
     * Reads the slug straight from the seeded taxonomy via category_id; falls
     * back to 'all' when the product wasn't categorised.
     */
    public function resolveCategory(string $name, ?int $categoryId = null, ?int $subcategoryId = null): string
    {
        if ($categoryId !== null) {
            $slug = DB::table('categories')->where('id', $categoryId)->value('slug');

            if ($slug !== null) {
                return (string) $slug;
            }
        }

        return 'all';
    }

    /**
     * Hydrate one row from {@see offersQuery()} into the DTO the Vue frontend
     * consumes (shape of `KainuProduct` in `resources/js/types/kainu.ts`).
     *
     * @return array<string, mixed>
     */
    public function hydrate(object $row): array
    {
        $productName = (string) ($row->product_name ?? '');
        $normalizedName = $row->normalized_name ?? null;
        $brand = $row->brand ?: $this->extractBrand($productName);
        $unit = $this->composeUnit($row);
        $validDates = $this->composeValidDates($row->valid_from ?? null, $row->valid_to ?? null);

        $discount = $row->effective_discount ?? null;

        $categoryId = isset($row->category_id) && $row->category_id !== null
            ? (int) $row->category_id
            : null;
        $subcategoryId = isset($row->subcategory_id) && $row->subcategory_id !== null
            ? (int) $row->subcategory_id
            : null;

        $currentPrice = $row->price !== null ? (float) $row->price : null;
        $previousPrice = isset($row->previous_price) && $row->previous_price !== null
            ? (float) $row->previous_price
            : null;
        $priceChange = ($currentPrice !== null && $previousPrice !== null)
            ? round($currentPrice - $previousPrice, 2)
            : null;
        $priceChangePct = ($currentPrice !== null && $previousPrice !== null && $previousPrice > 0)
            ? round((($currentPrice - $previousPrice) / $previousPrice) * 100, 2)
            : null;

        return [
            'id' => (int) $row->id,
            'normalizedProductId' => (int) $row->normalized_product_id,
            'name' => $productName,
            'normalizedName' => $normalizedName,
            'brand' => $brand,
            'category' => $this->resolveCategory($productName, $categoryId, $subcategoryId),
            'unit' => $unit,
            'emoji' => '',
            'bestPrice' => $currentPrice,
            'oldPrice' => $row->old_price !== null ? (float) $row->old_price : null,
            'discount' => $discount !== null ? (float) $discount : null,
            'condition' => $row->condition ?? null,
            'bestStore' => Str::slug($row->store_name ?? 'other'),
            'bestStoreName' => Str::title($row->store_name ?? 'Kita'),
            'image' => $row->image_url ?? null,
            'url' => $row->source_url ?? null,
            'validDates' => $validDates,
            'validFrom' => $this->stringifyDate($row->valid_from ?? null),
            'validTo' => $this->stringifyDate($row->valid_to ?? null),
            'loyaltyCard' => (bool) ($row->loyalty_card ?? false),
            'scrapedAt' => $row->scraped_at ?? null,
            'previousPrice' => $previousPrice,
            'priceChange' => $priceChange,
            'priceChangePct' => $priceChangePct,
        ];
    }

    /**
     * Try to extract a brand/producer from the product name. Used as a fallback
     * when the normalized record has no explicit brand.
     */
    private function extractBrand(string $name): ?string
    {
        if (preg_match('/["„]([^"”]+?)["”]/u', $name, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/^([A-ZĄČĘĖĮŠŲŪŽ]{3,})\s/u', $name, $m)) {
            return Str::title(mb_strtolower($m[1]));
        }

        return null;
    }

    /**
     * Build a human-readable unit price string from the normalized snapshot
     * fields, e.g. "2,50 €/kg", falling back to the variant size + unit.
     */
    private function composeUnit(object $row): ?string
    {
        $unitValue = $row->unit_price_value ?? null;
        $unitUnit = $row->unit_price_unit ?? null;
        if ($unitValue !== null && $unitUnit) {
            return $this->formatDecimal((float) $unitValue).' €/'.$unitUnit;
        }

        $size = $row->size_value ?? null;
        $sizeUnit = $row->size_unit ?? null;
        if ($size !== null && $sizeUnit) {
            return $this->formatDecimal((float) $size).' '.$sizeUnit;
        }

        return $row->variant_name ?? null;
    }

    /**
     * Render the validity window as a short Lithuanian-friendly string.
     */
    private function composeValidDates(mixed $from, mixed $to): ?string
    {
        $fromStr = $this->stringifyDate($from);
        $toStr = $this->stringifyDate($to);

        if ($fromStr && $toStr) {
            return $fromStr.' – '.$toStr;
        }

        if ($toStr) {
            return 'Iki '.$toStr;
        }

        if ($fromStr) {
            return 'Nuo '.$fromStr;
        }

        return null;
    }

    private function stringifyDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }

    private function formatDecimal(float $value): string
    {
        $formatted = number_format($value, 2, ',', '');

        return rtrim(rtrim($formatted, '0'), ',') ?: '0';
    }

    /**
     * Real time-series history for a normalized product, aggregated across all
     * stores. One data point per `scraped_at` calendar day, value = cheapest
     * available price across all stores that day.
     *
     * "Cheapest across all stores" is the right aggregate for a discount
     * tracker — the user's question is "what was the best deal that week",
     * not "what did Maxima alone charge". Charts that compare specific stores
     * over time can be added later as a separate query.
     *
     * @return array{
     *     values: array<int, float>,
     *     labels: array<int, string>,
     *     dates: array<int, string>,
     *     isReal: bool
     * }
     */
    public function priceHistory(int $normalizedProductId, int $maxPoints = 12): array
    {
        $rows = DB::table('offer_snapshots')
            ->join('product_variants', 'product_variants.id', '=', 'offer_snapshots.product_variant_id')
            ->where('product_variants.normalized_product_id', $normalizedProductId)
            ->whereNotNull('offer_snapshots.price')
            ->whereNotNull('offer_snapshots.scraped_at')
            ->selectRaw('CAST(offer_snapshots.scraped_at AS DATE) AS day')
            ->selectRaw('MIN(offer_snapshots.price) AS best_price')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $values = [];
        $labels = [];
        $dates = [];
        foreach ($rows as $row) {
            $day = $this->stringifyDate($row->day);
            if ($day === null) {
                continue;
            }
            $values[] = round((float) $row->best_price, 2);
            $dates[] = $day;
            $labels[] = $this->shortDateLabel($day);
        }

        if (count($values) > $maxPoints) {
            $values = array_slice($values, -$maxPoints);
            $labels = array_slice($labels, -$maxPoints);
            $dates = array_slice($dates, -$maxPoints);
        }

        return [
            'values' => $values,
            'labels' => $labels,
            'dates' => $dates,
            'isReal' => count($values) >= 2,
        ];
    }

    /**
     * Render a `YYYY-MM-DD` date as a compact `MM-DD` label for chart axes.
     */
    private function shortDateLabel(string $isoDate): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $isoDate, $m)) {
            return $m[2].'-'.$m[3];
        }

        return $isoDate;
    }
}
