<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OfferSnapshot;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KainuCatalog
{
    public const FRESHNESS_WINDOW_DAYS = 14;

    /**
     * Base catalog query: latest offer per (variant, store), freshness- and
     * visibility-floor-filtered, with the standard joins. Both the public
     * listing (offersQuery) and the per-store sidebar counts build on this so
     * the two can never drift apart in grain or filtering.
     */
    public function baseOffersQuery(): Builder
    {
        $query = DB::table('offer_snapshots')
            ->join('product_variants', 'product_variants.id', '=', 'offer_snapshots.product_variant_id')
            ->join('normalized_products', 'normalized_products.id', '=', 'product_variants.normalized_product_id')
            ->join('stores', 'stores.id', '=', 'offer_snapshots.store_id')
            ->leftJoin('raw_offers', 'raw_offers.id', '=', 'offer_snapshots.raw_offer_id')
            ->leftJoin('product_families', 'product_families.id', '=', 'normalized_products.family_id')
            ->whereIn('offer_snapshots.id', OfferSnapshot::latestIdsPerVariantStoreSubquery());

        $this->applyFreshness($query);
        $this->applyVisibilityFloor($query);

        return $query;
    }

    public function offersQuery(): Builder
    {
        return $this->baseOffersQuery()
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
            ')
            ->selectRaw('COALESCE(raw_offers.image, normalized_products.image_url) AS image_url');
    }

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
     * Hide pre-floor offers from the public catalog without deleting them.
     *
     * Why: lets us blank-slate the browsing UI before a fresh rescrape while
     * preserving rows that saved shopping lists still reference. Intentionally
     * NOT called from familyOfferQuery() — old lists must keep resolving.
     */
    public function applyVisibilityFloor(Builder $query): Builder
    {
        $floor = $this->visibilityFloor();
        if ($floor === null) {
            return $query;
        }

        return $query->where('offer_snapshots.scraped_at', '>=', $floor);
    }

    public function visibilityFloor(): ?Carbon
    {
        $raw = Config::get('kainu.visibility_floor');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($raw));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
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
     * The controlled family-key vocabulary for admin selection.
     *
     * `nameLt` is the proper Lithuanian display name (`family_name`, falling
     * back to a sentence-cased key when a family predates the seeder), `key` is
     * the stable `normalized_family_name` match key. Ordered by display name.
     *
     * @return array<int, array{id: int, nameLt: string, key: string}>
     */
    public function loadFamilies(): array
    {
        return DB::table('product_families')
            ->orderByRaw('COALESCE(family_name, normalized_family_name)')
            ->get(['id', 'family_name', 'normalized_family_name'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'nameLt' => $row->family_name !== null && trim((string) $row->family_name) !== ''
                    ? (string) $row->family_name
                    : $this->sentenceCase((string) $row->normalized_family_name),
                'key' => (string) $row->normalized_family_name,
            ])
            ->all();
    }

    private function sentenceCase(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $lower = mb_strtolower($value);

        return mb_strtoupper(mb_substr($lower, 0, 1))
            .mb_substr($lower, 1);
    }

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

    public function resolveCategory(?int $categoryId = null): string
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
     * @return array<string, mixed>
     */
    public function hydrate(object $row): array
    {
        $productName = (string) ($row->product_name ?? '');
        $normalizedName = $row->normalized_name ?? null;
        $rawProductName = isset($row->raw_product_name) && $row->raw_product_name !== null
            ? trim((string) $row->raw_product_name)
            : '';
        $displayName = $rawProductName !== '' ? $rawProductName : $productName;
        $familyName = isset($row->family_name) && $row->family_name !== null
            ? trim((string) $row->family_name)
            : '';
        if ($familyName === '') {
            $familyName = isset($row->family_generic_name) && $row->family_generic_name !== null
                ? trim((string) $row->family_generic_name)
                : '';
        }
        $brand = $row->brand ?: $this->extractBrand($productName);
        $unit = $this->composeUnit($row);
        $unitPrice = $this->composeUnitPrice($row);
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
            'familyId' => isset($row->family_id) && $row->family_id !== null
                ? (int) $row->family_id
                : null,
            'name' => $productName,
            'displayName' => $displayName,
            'canonicalName' => $productName,
            'familyName' => $familyName !== '' ? $familyName : null,
            'normalizedName' => $normalizedName,
            'brand' => $brand,
            'category' => $this->resolveCategory($categoryId),
            'unit' => $unit,
            'unitPrice' => $unitPrice,
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
     * The store's own scraped unit price (already normalized to €/kg, €/l, …),
     * which is more authoritative than dividing price by parsed size.
     */
    private function composeUnitPrice(object $row): ?string
    {
        $unitValue = $row->unit_price_value ?? null;
        $unitUnit = $row->unit_price_unit ?? null;
        if ($unitValue !== null && $unitUnit) {
            return $this->formatDecimal((float) $unitValue).' €/'.$unitUnit;
        }

        return null;
    }

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
     * Re-resolves the cheapest fresh offer for a saved cart row.
     *
     * Returns null if no fresh offer exists for the family.
     *
     * @return array{
     *     snapshot_id: int,
     *     normalized_product_id: int,
     *     variant_id: int,
     *     family_id: int,
     *     store_slug: string,
     *     store_name: string,
     *     price: float,
     *     old_price: ?float,
     *     unit: ?string,
     *     image: ?string,
     *     product_name: string,
     * }|null
     */
    public function latestOfferForFamily(int $familyId, ?int $variantId = null): ?array
    {
        $row = $this->familyOfferQuery($familyId, $variantId)->first();

        if ($row === null && $variantId !== null) {
            $row = $this->familyOfferQuery($familyId, null)->first();
        }

        if ($row === null) {
            return null;
        }

        $hydrated = $this->hydrate($row);

        return [
            'snapshot_id' => (int) $row->id,
            'normalized_product_id' => (int) $row->normalized_product_id,
            'variant_id' => (int) $row->product_variant_id,
            'family_id' => $familyId,
            'store_slug' => (string) $hydrated['bestStore'],
            'store_name' => (string) $hydrated['bestStoreName'],
            'price' => (float) $row->price,
            'old_price' => $row->old_price !== null ? (float) $row->old_price : null,
            'unit' => $hydrated['unit'],
            'image' => $hydrated['image'],
            'product_name' => (string) $row->product_name,
        ];
    }

    private function familyOfferQuery(int $familyId, ?int $variantId): Builder
    {
        $query = DB::table('offer_snapshots')
            ->join('product_variants', 'product_variants.id', '=', 'offer_snapshots.product_variant_id')
            ->join('normalized_products', 'normalized_products.id', '=', 'product_variants.normalized_product_id')
            ->join('stores', 'stores.id', '=', 'offer_snapshots.store_id')
            ->leftJoin('raw_offers', 'raw_offers.id', '=', 'offer_snapshots.raw_offer_id')
            ->leftJoin('product_families', 'product_families.id', '=', 'normalized_products.family_id')
            ->whereIn('offer_snapshots.id', OfferSnapshot::latestIdsPerProductStoreSubquery())
            ->where('normalized_products.family_id', $familyId)
            ->whereNotNull('offer_snapshots.price');

        $this->applyFreshness($query);

        if ($variantId !== null) {
            $query->where('product_variants.id', $variantId);
        }

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
                'offer_snapshots.product_variant_id',
                'product_variants.variant_name',
                'product_variants.size_value',
                'product_variants.size_unit',
                'normalized_products.id as normalized_product_id',
                'normalized_products.canonical_name as product_name',
                'normalized_products.normalized_name',
                'normalized_products.brand',
                'normalized_products.category_id',
                'normalized_products.subcategory_id',
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
            ->orderBy('offer_snapshots.price');
    }

    public function priceHistory(int $normalizedProductId, ?int $storeId = null, int $maxPoints = 12): array
    {
        $query = DB::table('offer_snapshots')
            ->join('product_variants', 'product_variants.id', '=', 'offer_snapshots.product_variant_id')
            ->where('product_variants.normalized_product_id', $normalizedProductId)
            ->whereNotNull('offer_snapshots.price')
            ->whereNotNull('offer_snapshots.scraped_at');

        if ($storeId !== null) {
            $query->where('offer_snapshots.store_id', $storeId);
        }

        $rows = $query
            ->selectRaw('DATE(offer_snapshots.scraped_at) AS day')
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

    private function shortDateLabel(string $isoDate): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $isoDate, $m)) {
            return $m[2].'-'.$m[3];
        }

        return $isoDate;
    }
}
