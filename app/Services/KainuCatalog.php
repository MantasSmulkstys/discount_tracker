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
     * Maps each frontend category slug to:
     *
     * - `category_ids`: top-level `categories.id` values from the AI
     *   normalization pass (the official LT product taxonomy stored in the
     *   `categories` table).
     * - `subcategory_ids`: optional narrower `subcategories.id` values when
     *   the slug should hit only part of an AI category (e.g. dairy is a
     *   subcategory of "ŠVIEŽI MAISTO PRODUKTAI").
     * - `keywords`: legacy heuristic fallback used only for products whose
     *   `category_id` is still NULL (deterministic-only normalization,
     *   currently ~6% of the catalog).
     *
     * @var array<string, array{
     *     label: string,
     *     category_ids: array<int, int>,
     *     subcategory_ids: array<int, int>,
     *     keywords: array<int, string>
     * }>
     */
    public const CATEGORIES = [
        'produce' => [
            'label' => 'Vaisiai ir daržovės',
            'category_ids' => [2],
            'subcategory_ids' => [],
            'keywords' => ['obuol', 'pomidor', 'agurk', 'morkų', 'morkos', 'bulv', 'salot', 'banan', 'citrin', 'apelsin', 'avokad', 'uogos', 'brašk', 'braški', 'kriaušė', 'vynuog', 'kopūst', 'svogūn', 'česnak', 'paprik', 'brokol', 'vaisiai', 'daržovės'],
        ],
        'dairy' => [
            'label' => 'Pieno produktai',
            'category_ids' => [],
            'subcategory_ids' => [20, 22],
            'keywords' => ['pien', 'jogurt', 'sūris', 'suris', 'sviestas', 'varškė', 'varske', 'grietin', 'kiaušin', 'kiausin', 'kefyr'],
        ],
        'meat' => [
            'label' => 'Mėsa ir žuvis',
            'category_ids' => [1],
            'subcategory_ids' => [34],
            'keywords' => ['mėsa', 'mesa', 'vištie', 'vistie', 'kiaulie', 'jautien', 'dešr', 'desr', 'kumpis', 'šoninė', 'sonine', 'šprotai', 'žuvis', 'zuvis', 'lašiš', 'lasis', 'silkė', 'silke', 'tunas'],
        ],
        'bakery' => [
            'label' => 'Duona ir kepiniai',
            'category_ids' => [7],
            'subcategory_ids' => [15],
            'keywords' => ['duona', 'bandel', 'pyrag', 'tortas', 'sausain', 'batonas', 'baguette', 'kepiniai', 'keksas', 'bulk'],
        ],
        'pantry' => [
            'label' => 'Sausi produktai',
            'category_ids' => [4, 5],
            'subcategory_ids' => [21],
            'keywords' => ['makaronai', 'kruopos', 'ryžiai', 'ryziai', 'miltai', 'cukrus', 'druska', 'aliejus', 'actas', 'padaž', 'padaz', 'konservai', 'kava', 'arbata', 'sriuba'],
        ],
        'frozen' => [
            'label' => 'Šaldyti produktai',
            'category_ids' => [],
            'subcategory_ids' => [18],
            'keywords' => ['šaldyt', 'saldyt', 'ledai', 'koldūn', 'koldun', 'picos', 'pica'],
        ],
        'drinks' => [
            'label' => 'Gėrimai',
            'category_ids' => [6],
            'subcategory_ids' => [],
            'keywords' => ['vanduo', 'sultys', 'alus', 'vynas', 'limonad', 'kola', 'fanta', 'sprite', 'gaivusis', 'gazuotas'],
        ],
        'household' => [
            'label' => 'Namų ūkiui',
            'category_ids' => [8, 9],
            'subcategory_ids' => [],
            'keywords' => ['skalbimo', 'plovimo', 'valymo', 'muilas', 'šampū', 'sampu', 'dantų', 'dantu', 'tualet', 'servetėl', 'servetel', 'higien'],
        ],
    ];

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
     * Apply a category filter to an offers query.
     *
     * Primary path uses the structured `category_id` / `subcategory_id`
     * fields populated by AI normalization. Products with NULL category_id
     * (~6% of catalog when AI has run, more when run with `--no-ai`) fall
     * back to the keyword heuristic on `canonical_name` so they're not lost
     * from the listing entirely.
     */
    public function applyCategory(Builder $query, ?string $categoryId): Builder
    {
        if (! $categoryId || $categoryId === 'all' || ! isset(self::CATEGORIES[$categoryId])) {
            return $query;
        }

        $meta = self::CATEGORIES[$categoryId];
        $categoryIds = $meta['category_ids'];
        $subcategoryIds = $meta['subcategory_ids'];
        $keywords = $meta['keywords'];

        return $query->where(function (Builder $q) use ($categoryIds, $subcategoryIds, $keywords): void {
            if (! empty($categoryIds)) {
                $q->orWhereIn('normalized_products.category_id', $categoryIds);
            }

            if (! empty($subcategoryIds)) {
                $q->orWhereIn('normalized_products.subcategory_id', $subcategoryIds);
            }

            if (! empty($keywords)) {
                $q->orWhere(function (Builder $kq) use ($keywords): void {
                    $kq->whereNull('normalized_products.category_id')
                        ->where(function (Builder $kkq) use ($keywords): void {
                            foreach ($keywords as $kw) {
                                $needle = '%'.mb_strtolower($kw).'%';
                                $kkq->orWhereRaw('LOWER(normalized_products.canonical_name) LIKE ?', [$needle])
                                    ->orWhereRaw('LOWER(normalized_products.normalized_name) LIKE ?', [$needle]);
                            }
                        });
                });
            }
        });
    }

    /**
     * Resolve the frontend category slug for a product, preferring the
     * structured `categories.id` / `subcategories.id` values populated by AI
     * normalization and falling back to keyword matching on the product name
     * for rows that bypassed AI.
     */
    public function resolveCategory(string $name, ?int $categoryId = null, ?int $subcategoryId = null): string
    {
        if ($categoryId !== null) {
            foreach (self::CATEGORIES as $id => $meta) {
                if (in_array($categoryId, $meta['category_ids'], true)) {
                    return $id;
                }
            }
        }

        if ($subcategoryId !== null) {
            foreach (self::CATEGORIES as $id => $meta) {
                if (in_array($subcategoryId, $meta['subcategory_ids'], true)) {
                    return $id;
                }
            }
        }

        $lower = mb_strtolower($name);
        foreach (self::CATEGORIES as $id => $meta) {
            foreach ($meta['keywords'] as $kw) {
                if (mb_strpos($lower, mb_strtolower($kw)) !== false) {
                    return $id;
                }
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
            'name' => $productName,
            'normalizedName' => $normalizedName,
            'brand' => $brand,
            'category' => $this->resolveCategory($productName, $categoryId, $subcategoryId),
            'unit' => $unit,
            'emoji' => '',
            'bestPrice' => $currentPrice,
            'oldPrice' => $row->old_price !== null ? (float) $row->old_price : null,
            'discount' => $discount !== null ? (float) $discount : null,
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
     * Build a simulated 8-week price history when none is available.
     *
     * @return array<int, float>
     */
    public function simulatedHistory(float $currentPrice, ?float $oldPrice, int $points = 8): array
    {
        $high = $oldPrice && $oldPrice > $currentPrice ? $oldPrice : $currentPrice * 1.22;
        $history = [];

        for ($i = 0; $i < $points; $i++) {
            $t = $i / max($points - 1, 1);
            $value = $high + ($currentPrice - $high) * $t;
            $jitter = (sin(($i + 1) * 1.8) * 0.04) * $value;
            $history[] = round($value + $jitter, 2);
        }

        $history[$points - 1] = round($currentPrice, 2);

        return $history;
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
