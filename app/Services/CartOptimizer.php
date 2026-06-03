<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OfferSnapshot;
use App\Services\Maps\PlacesApiClient;
use App\Services\Maps\RoutesApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Multi-objective basket optimiser over the Pareto frontier of price + time.
 *
 * Inputs: cart items (family_id + variant_id + qty), and an origin.
 * Output: up to 4 labelled alternatives, each with the cart re-priced to
 * a particular subset of stores.
 *
 * Routes API calls are the cost ceiling — cached for 6 hours and capped at
 * MAX_ROUTES_CALLS per request.
 */
class CartOptimizer
{
    public const MAX_STORES_IN_UNIVERSE = 6;

    public const MAX_STORES_PER_SUBSET = 4;

    public const MAX_ROUTES_CALLS = 12;

    public const PER_STOP_OVERHEAD_SEC = 600;

    public const COLLAPSE_PRICE_EPSILON = 0.30;

    public const COLLAPSE_TIME_EPSILON_SEC = 120;

    /** @var array<string, array{durationSec: int, distanceMeters: int}> */
    private array $routeMemo = [];

    private int $routeCallsUsed = 0;

    public function __construct(
        private readonly KainuCatalog $catalog,
        private readonly RoutesApiClient $routes,
        private readonly PlacesApiClient $places,
    ) {}

    /**
     * @param  list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, quantity: int, storeSlug: ?string, priceCents: ?int}>  $items
     * @param  array{lat: float, lng: float}  $origin
     * @param  'WALK'|'DRIVE'  $mode
     * @return array{
     *     baseline: array{totalPriceCents: int, totalDurationSec: int, stores: list<string>}|null,
     *     alternatives: list<array{
     *         label: string,
     *         stores: list<string>,
     *         totalPriceCents: int,
     *         totalDurationSec: int,
     *         priceDeltaCents: int,
     *         durationDeltaSec: int,
     *         items: list<array{
     *             familyId: ?int,
     *             normalizedProductId: ?int,
     *             variantId: ?int,
     *             storeSlug: string,
     *             priceCents: int,
     *             quantity: int,
     *             name: string,
     *             fixed: bool
     *         }>
     *     }>
     * }
     */
    public function optimize(array $items, array $origin, string $mode = 'DRIVE'): array
    {
        $this->routeMemo = [];
        $this->routeCallsUsed = 0;

        if ($items === []) {
            return ['baseline' => null, 'alternatives' => []];
        }

        $candidates = $this->buildCandidates($items);

        $universe = $this->coverageUniverse($candidates);

        $baseline = $this->computeBaseline($items, $candidates);
        $baselineStores = $baseline['stores'];

        if ($universe === []) {
            return ['baseline' => $baseline, 'alternatives' => []];
        }

        $stopCoords = $this->resolveStopCoords($universe, $origin);

        $usableStores = array_values(array_intersect($universe, array_keys($stopCoords)));
        if ($usableStores === []) {
            return ['baseline' => $baseline, 'alternatives' => []];
        }

        $subsets = $this->enumerateSubsets($usableStores);

        $points = [];
        foreach ($subsets as $subset) {
            $built = $this->buildSubset($items, $candidates, $subset);
            if ($built === null) {
                continue;
            }

            $time = $this->routeTimeForSubset($origin, $subset, $stopCoords, $mode);
            $built['totalDurationSec'] = $time + (count($subset) * self::PER_STOP_OVERHEAD_SEC);
            $built['stores'] = $subset;

            $points[] = $built;
        }

        $pareto = $this->paretoFilter($points);
        $pareto = $this->collapseNearDuplicates($pareto);

        $labelled = $this->labelPoints($pareto, $baseline);

        return [
            'baseline' => $baseline,
            'alternatives' => array_slice($labelled, 0, 4),
        ];
    }

    /**
     * Map each cart item to its candidate offers, keyed by store_slug.
     *
     * Matching is "same family + same package size": an alternative may swap to
     * a sibling product in the same family only when it comes in the same size
     * the shopper already picked. This is the middle ground between matching the
     * exact product (too strict — misses the same water sold under a slightly
     * different name at another store) and the whole family (too loose — offers
     * 0.5 l / 2 l / flavoured variants as if interchangeable). When the size of
     * the shopper's own offer can't be determined, we fall back to the exact
     * product so we never substitute blindly.
     *
     * @param  list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, quantity: int, storeSlug: ?string, priceCents: ?int}>  $items
     * @return list<array{
     *     input: array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, quantity: int, storeSlug: ?string, priceCents: ?int},
     *     fixed: bool,
     *     fallbackName: string,
     *     offers: array<string, array{priceCents: int, name: string, normalizedProductId: int, variantId: int, snapshotId: int, image: ?string}>
     * }>
     */
    private function buildCandidates(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $offers = [];
            $fallbackName = '';
            $npId = $item['normalizedProductId'];

            if ($npId !== null) {
                $rows = $this->fetchFreshOfferRows($item['familyId'], $npId);
                $referenceSizeKey = $this->referenceSizeKey($rows, $npId, $item['storeSlug']);

                foreach ($rows as $row) {
                    $rowNpId = (int) $row->normalized_product_id;

                    // Same family + same size, or — when the shopper's own size
                    // is unknown — only the exact same product.
                    $matches = $referenceSizeKey === null
                        ? ($rowNpId === $npId)
                        : ($this->sizeKey($row) === $referenceSizeKey);

                    if (! $matches) {
                        continue;
                    }

                    $slug = (string) $row->store_slug;
                    $candidate = [
                        'priceCents' => (int) round(((float) $row->price) * 100),
                        'name' => $this->offerName($row),
                        'normalizedProductId' => $rowNpId,
                        'variantId' => (int) $row->product_variant_id,
                        'snapshotId' => (int) $row->id,
                        'image' => $row->image_url !== null ? (string) $row->image_url : null,
                    ];

                    if ($fallbackName === '' && $rowNpId === $npId) {
                        $fallbackName = $candidate['name'];
                    }

                    if (! isset($offers[$slug]) || $this->preferOffer($candidate, $offers[$slug], $npId)) {
                        $offers[$slug] = $candidate;
                    }
                }
            }

            $out[] = [
                'input' => $item,
                'fixed' => $offers === [],
                'fallbackName' => $fallbackName,
                'offers' => $offers,
            ];
        }

        return $out;
    }

    /**
     * Pick the better of two offers for the same store: the shopper's exact
     * product wins over a mere sibling, then cheaper, then a stable id so the
     * choice is deterministic when prices tie.
     *
     * @param  array{priceCents: int, name: string, normalizedProductId: int, variantId: int, snapshotId: int, image: ?string}  $candidate
     * @param  array{priceCents: int, name: string, normalizedProductId: int, variantId: int, snapshotId: int, image: ?string}  $current
     */
    private function preferOffer(array $candidate, array $current, int $npId): bool
    {
        $candidateExact = $candidate['normalizedProductId'] === $npId;
        $currentExact = $current['normalizedProductId'] === $npId;

        if ($candidateExact !== $currentExact) {
            return $candidateExact;
        }

        if ($candidate['priceCents'] !== $current['priceCents']) {
            return $candidate['priceCents'] < $current['priceCents'];
        }

        return $candidate['snapshotId'] < $current['snapshotId'];
    }

    /**
     * The package-size signature shared by interchangeable offers. Prefers the
     * scraped net quantity (the real total amount), falling back to size × pack
     * count. Returns null when the variant carries no size at all.
     */
    private function sizeKey(object $row): ?string
    {
        $net = $row->net_quantity_value ?? null;
        $netUnit = $row->net_quantity_unit ?? null;
        if ($net !== null && $netUnit) {
            return round((float) $net, 3).'|'.mb_strtolower((string) $netUnit);
        }

        $size = $row->size_value ?? null;
        $sizeUnit = $row->size_unit ?? null;
        if ($size !== null && $sizeUnit) {
            $pack = $row->package_count !== null ? (float) $row->package_count : 1.0;
            $total = (float) $size * ($pack > 0 ? $pack : 1.0);

            return round($total, 3).'|'.mb_strtolower((string) $sizeUnit);
        }

        return null;
    }

    /**
     * The size of the offer the shopper actually has: their exact product at
     * their store, falling back to any offer of that product. Null when the
     * product carries no size, which forces exact-product matching.
     *
     * @param  array<int, object>  $rows
     */
    private function referenceSizeKey(array $rows, int $npId, ?string $storeSlug): ?string
    {
        $fallback = null;

        foreach ($rows as $row) {
            if ((int) $row->normalized_product_id !== $npId) {
                continue;
            }

            $key = $this->sizeKey($row);

            if ($storeSlug !== null && (string) $row->store_slug === $storeSlug) {
                return $key;
            }

            $fallback ??= $key;
        }

        return $fallback;
    }

    /**
     * Display name for an offer — the raw scraped name (grammatically correct,
     * with diacritics) when present, else the canonical name. Mirrors the
     * cart/listing which prefer the raw name.
     */
    private function offerName(object $row): string
    {
        $raw = isset($row->raw_name) && $row->raw_name !== null ? trim((string) $row->raw_name) : '';

        return $raw !== '' ? $raw : (string) $row->product_name;
    }

    /**
     * Fetch fresh offers to choose alternatives from. Scoped to the item's
     * family when it has one (so same-size siblings are eligible), else to the
     * exact product. One latest row per (product, store); the caller narrows by
     * size and keeps the best offer per store.
     *
     * @return array<int, object>
     */
    private function fetchFreshOfferRows(?int $familyId, int $normalizedProductId): array
    {
        $today = now()->toDateString();
        $cutoff = now()->subDays(KainuCatalog::FRESHNESS_WINDOW_DAYS)->toDateString();

        $query = DB::table('offer_snapshots')
            ->join('product_variants', 'product_variants.id', '=', 'offer_snapshots.product_variant_id')
            ->join('normalized_products', 'normalized_products.id', '=', 'product_variants.normalized_product_id')
            ->join('stores', 'stores.id', '=', 'offer_snapshots.store_id')
            ->leftJoin('raw_offers', 'raw_offers.id', '=', 'offer_snapshots.raw_offer_id')
            ->whereIn('offer_snapshots.id', OfferSnapshot::latestIdsPerProductStoreSubquery())
            ->where(function ($q) use ($today): void {
                $q->whereNull('offer_snapshots.valid_to')
                    ->orWhere('offer_snapshots.valid_to', '>=', $today);
            })
            ->where('offer_snapshots.scraped_at', '>=', $cutoff)
            ->whereNotNull('offer_snapshots.price');

        if ($familyId !== null) {
            $query->where('normalized_products.family_id', $familyId);
        } else {
            $query->where('product_variants.normalized_product_id', $normalizedProductId);
        }

        return $query
            ->select([
                'offer_snapshots.id',
                'offer_snapshots.price',
                'offer_snapshots.product_variant_id',
                'product_variants.size_value',
                'product_variants.size_unit',
                'product_variants.package_count',
                'product_variants.package_unit',
                'product_variants.net_quantity_value',
                'product_variants.net_quantity_unit',
                'normalized_products.id as normalized_product_id',
                'normalized_products.canonical_name as product_name',
                'stores.slug as store_slug',
                'stores.name as store_name',
                'raw_offers.name as raw_name',
            ])
            ->selectRaw('COALESCE(raw_offers.image, normalized_products.image_url) as image_url')
            ->get()
            ->all();
    }

    /**
     * Compute the cost and stores used for the cart as the user submitted it.
     *
     * @param  list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, quantity: int, storeSlug: ?string, priceCents: ?int}>  $items
     * @param  list<array{
     *     input: array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, quantity: int, storeSlug: ?string, priceCents: ?int},
     *     fixed: bool,
     *     fallbackName: string,
     *     offers: array<string, array{priceCents: int, name: string, variantId: int}>
     * }>  $candidates
     * @return array{totalPriceCents: int, totalDurationSec: int, stores: list<string>}
     */
    private function computeBaseline(array $items, array $candidates): array
    {
        $totalCents = 0;
        $stores = [];

        foreach ($candidates as $cand) {
            $item = $cand['input'];
            $slug = $item['storeSlug'];
            $price = null;

            if ($slug !== null && isset($cand['offers'][$slug])) {
                $price = $cand['offers'][$slug]['priceCents'];
            } elseif ($item['priceCents'] !== null) {
                $price = $item['priceCents'];
            }

            if ($price !== null) {
                $totalCents += $price * $item['quantity'];
            }

            if ($slug !== null && ! in_array($slug, $stores, true)) {
                $stores[] = $slug;
            }
        }

        return [
            'totalPriceCents' => $totalCents,
            'totalDurationSec' => 0,
            'stores' => $stores,
        ];
    }

    /**
     * The union of candidate stores across all items, capped by coverage.
     *
     * @param  list<array{
     *     input: array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, quantity: int, storeSlug: ?string, priceCents: ?int},
     *     fixed: bool,
     *     fallbackName: string,
     *     offers: array<string, array{priceCents: int, name: string, variantId: int}>
     * }>  $candidates
     * @return list<string>
     */
    private function coverageUniverse(array $candidates): array
    {
        $counts = [];
        foreach ($candidates as $cand) {
            foreach (array_keys($cand['offers']) as $slug) {
                $counts[$slug] = ($counts[$slug] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return [];
        }

        arsort($counts);

        return array_slice(array_keys($counts), 0, self::MAX_STORES_IN_UNIVERSE);
    }

    /**
     * @param  list<string>  $universe
     * @return list<list<string>>
     */
    private function enumerateSubsets(array $universe): array
    {
        $n = count($universe);
        $subsets = [];

        for ($mask = 1; $mask < (1 << $n); $mask++) {
            $subset = [];
            for ($i = 0; $i < $n; $i++) {
                if ($mask & (1 << $i)) {
                    $subset[] = $universe[$i];
                }
            }
            if (count($subset) <= self::MAX_STORES_PER_SUBSET) {
                $subsets[] = $subset;
            }
        }

        usort(
            $subsets,
            fn (array $a, array $b): int => count($a) <=> count($b),
        );

        return $subsets;
    }

    /**
     * For a given subset, pick the cheapest offer per item from that subset.
     *
     * The picked item carries the *actually chosen* offer's identity (its own
     * product id, variant, snapshot and name/image) rather than the cart item's
     * — so when a same-size sibling is substituted, the UI links to and shows
     * that real product, not the original.
     *
     * @param  list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, quantity: int, storeSlug: ?string, priceCents: ?int}>  $items
     * @param  list<array{
     *     input: array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, quantity: int, storeSlug: ?string, priceCents: ?int},
     *     fixed: bool,
     *     fallbackName: string,
     *     offers: array<string, array{priceCents: int, name: string, normalizedProductId: int, variantId: int, snapshotId: int, image: ?string}>
     * }>  $candidates
     * @param  list<string>  $subset
     * @return array{totalPriceCents: int, totalDurationSec: int, stores: list<string>, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, snapshotId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, image: ?string, fixed: bool, changed: bool}>}|null
     */
    private function buildSubset(array $items, array $candidates, array $subset): ?array
    {
        $total = 0;
        $picked = [];

        foreach ($candidates as $cand) {
            $input = $cand['input'];

            if ($cand['fixed']) {
                $fallbackPrice = $input['priceCents'];
                if ($fallbackPrice === null) {
                    return null;
                }
                $total += $fallbackPrice * $input['quantity'];
                $picked[] = [
                    'familyId' => $input['familyId'],
                    'normalizedProductId' => $input['normalizedProductId'],
                    'variantId' => $input['variantId'],
                    'snapshotId' => null,
                    'storeSlug' => (string) ($input['storeSlug'] ?? 'other'),
                    'priceCents' => $fallbackPrice,
                    'quantity' => $input['quantity'],
                    'name' => $cand['fallbackName'] !== ''
                        ? $cand['fallbackName']
                        : 'Prekė',
                    'image' => null,
                    'fixed' => true,
                    'changed' => false,
                ];

                continue;
            }

            $bestSlug = null;
            $best = null;

            foreach ($subset as $slug) {
                if (! isset($cand['offers'][$slug])) {
                    continue;
                }
                $offer = $cand['offers'][$slug];
                if ($best === null || $offer['priceCents'] < $best['priceCents']) {
                    $best = $offer;
                    $bestSlug = $slug;
                }
            }

            if ($bestSlug === null || $best === null) {
                return null;
            }

            $total += $best['priceCents'] * $input['quantity'];
            $picked[] = [
                'familyId' => $input['familyId'],
                'normalizedProductId' => $best['normalizedProductId'],
                'variantId' => $best['variantId'],
                'snapshotId' => $best['snapshotId'],
                'storeSlug' => $bestSlug,
                'priceCents' => $best['priceCents'],
                'quantity' => $input['quantity'],
                'name' => $best['name'],
                'image' => $best['image'],
                'fixed' => false,
                // A line "changes" only when it moves store or swaps to a
                // sibling product — a same-store/same-product re-price is not a
                // change the shopper has to act on, so the UI hides it.
                'changed' => $best['normalizedProductId'] !== $input['normalizedProductId']
                    || $bestSlug !== $input['storeSlug'],
            ];
        }

        return [
            'totalPriceCents' => $total,
            'totalDurationSec' => 0,
            'stores' => $subset,
            'items' => $picked,
        ];
    }

    /**
     * Resolve coordinates for each store-slug via PlacesApiClient. Cached.
     *
     * @param  list<string>  $stores
     * @param  array{lat: float, lng: float}  $origin
     * @return array<string, array{lat: float, lng: float}>
     */
    private function resolveStopCoords(array $stores, array $origin): array
    {
        $rows = DB::table('stores')
            ->whereIn('slug', $stores)
            ->get(['slug', 'name'])
            ->keyBy('slug');

        $out = [];
        foreach ($stores as $slug) {
            $row = $rows->get($slug);
            $queryName = $row !== null ? Str::title((string) $row->name) : Str::title($slug);

            $cacheKey = sprintf(
                'place_lookup:%s:%.2f,%.2f',
                $slug,
                $origin['lat'],
                $origin['lng'],
            );

            try {
                $place = Cache::remember(
                    $cacheKey,
                    now()->addHours(24),
                    fn (): ?array => $this->places->findNearestStore($queryName, $origin),
                );
            } catch (RuntimeException) {
                continue;
            }

            if ($place === null) {
                continue;
            }

            $out[$slug] = ['lat' => $place['lat'], 'lng' => $place['lng']];
        }

        return $out;
    }

    /**
     * Compute (and cache) the round-trip duration for a subset of stores.
     *
     * @param  array{lat: float, lng: float}  $origin
     * @param  list<string>  $subset
     * @param  array<string, array{lat: float, lng: float}>  $stopCoords
     * @param  'WALK'|'DRIVE'  $mode
     */
    private function routeTimeForSubset(array $origin, array $subset, array $stopCoords, string $mode): int
    {
        $sortedSubset = $subset;
        sort($sortedSubset);
        $memoKey = $mode.':'.implode(',', $sortedSubset);

        if (isset($this->routeMemo[$memoKey])) {
            return $this->routeMemo[$memoKey]['durationSec'];
        }

        $cacheKey = 'route_time:'.$mode.':'.sha1(sprintf('%.2f,%.2f', $origin['lat'], $origin['lng']).'#'.implode('|', $sortedSubset));

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['durationSec'])) {
            $this->routeMemo[$memoKey] = ['durationSec' => (int) $cached['durationSec'], 'distanceMeters' => (int) $cached['distanceMeters']];

            return (int) $cached['durationSec'];
        }

        if ($this->routeCallsUsed >= self::MAX_ROUTES_CALLS) {
            return 0;
        }

        $intermediates = [];
        foreach ($sortedSubset as $slug) {
            if (isset($stopCoords[$slug])) {
                $intermediates[] = $stopCoords[$slug];
            }
        }

        if ($intermediates === []) {
            return 0;
        }

        try {
            $route = $this->routes->computeRoute(
                origin: $origin,
                destination: $origin,
                intermediates: $intermediates,
                mode: $mode,
            );
            $this->routeCallsUsed++;
        } catch (RuntimeException) {
            return 0;
        }

        $entry = [
            'durationSec' => (int) $route['totalDurationSec'],
            'distanceMeters' => (int) $route['totalDistanceMeters'],
        ];

        Cache::put($cacheKey, $entry, now()->addHours(6));
        $this->routeMemo[$memoKey] = $entry;

        return $entry['durationSec'];
    }

    /**
     * Drop points that are dominated on both price AND time.
     *
     * @param  list<array{totalPriceCents: int, totalDurationSec: int, stores: list<string>, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, snapshotId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, image: ?string, fixed: bool, changed: bool}>}>  $points
     * @return list<array{totalPriceCents: int, totalDurationSec: int, stores: list<string>, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, snapshotId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, image: ?string, fixed: bool, changed: bool}>}>
     */
    private function paretoFilter(array $points): array
    {
        $out = [];
        foreach ($points as $i => $a) {
            $dominated = false;
            foreach ($points as $j => $b) {
                if ($i === $j) {
                    continue;
                }
                if (
                    $b['totalPriceCents'] <= $a['totalPriceCents']
                    && $b['totalDurationSec'] <= $a['totalDurationSec']
                    && ($b['totalPriceCents'] < $a['totalPriceCents']
                        || $b['totalDurationSec'] < $a['totalDurationSec'])
                ) {
                    $dominated = true;
                    break;
                }
            }
            if (! $dominated) {
                $out[] = $a;
            }
        }

        return $out;
    }

    /**
     * Within ε on both axes, keep the one with fewer stores.
     *
     * @param  list<array{totalPriceCents: int, totalDurationSec: int, stores: list<string>, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, snapshotId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, image: ?string, fixed: bool, changed: bool}>}>  $points
     * @return list<array{totalPriceCents: int, totalDurationSec: int, stores: list<string>, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, snapshotId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, image: ?string, fixed: bool, changed: bool}>}>
     */
    private function collapseNearDuplicates(array $points): array
    {
        usort($points, fn (array $a, array $b): int => count($a['stores']) <=> count($b['stores'])
            ?: $a['totalPriceCents'] <=> $b['totalPriceCents']);

        $kept = [];
        foreach ($points as $p) {
            $isDuplicate = false;
            foreach ($kept as $k) {
                $priceClose = abs($p['totalPriceCents'] - $k['totalPriceCents']) <= self::COLLAPSE_PRICE_EPSILON * 100;
                $timeClose = abs($p['totalDurationSec'] - $k['totalDurationSec']) <= self::COLLAPSE_TIME_EPSILON_SEC;
                if ($priceClose && $timeClose) {
                    $isDuplicate = true;
                    break;
                }
            }
            if (! $isDuplicate) {
                $kept[] = $p;
            }
        }

        return $kept;
    }

    /**
     * @param  list<array{totalPriceCents: int, totalDurationSec: int, stores: list<string>, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, snapshotId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, image: ?string, fixed: bool, changed: bool}>}>  $points
     * @param  array{totalPriceCents: int, totalDurationSec: int, stores: list<string>}  $baseline
     * @return list<array{label: string, stores: list<string>, totalPriceCents: int, totalDurationSec: int, priceDeltaCents: int, durationDeltaSec: int, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, snapshotId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, image: ?string, fixed: bool, changed: bool}>}>
     */
    private function labelPoints(array $points, array $baseline): array
    {
        if ($points === []) {
            return [];
        }

        $cheapest = $this->argMin($points, fn (array $p): int => $p['totalPriceCents']);
        $fastest = $this->argMin($points, fn (array $p): int => $p['totalDurationSec']);
        $singleStore = $this->argMin(
            array_values(array_filter($points, fn (array $p): bool => count($p['stores']) === 1)),
            fn (array $p): int => $p['totalPriceCents'],
        );

        $labelled = [];
        $usedKeys = [];

        $appendLabel = function (?array $point, string $label) use (&$labelled, &$usedKeys, $baseline): void {
            if ($point === null) {
                return;
            }
            $key = $this->pointKey($point);
            if (isset($usedKeys[$key])) {
                return;
            }
            $baselineStoresSorted = $baseline['stores'];
            sort($baselineStoresSorted);
            $pointStoresSorted = $point['stores'];
            sort($pointStoresSorted);
            if ($baselineStoresSorted !== [] && $baselineStoresSorted === $pointStoresSorted
                && $point['totalPriceCents'] === $baseline['totalPriceCents']) {
                return;
            }
            $usedKeys[$key] = true;
            $labelled[] = [
                'label' => $label,
                'stores' => $point['stores'],
                'totalPriceCents' => $point['totalPriceCents'],
                'totalDurationSec' => $point['totalDurationSec'],
                'priceDeltaCents' => $point['totalPriceCents'] - $baseline['totalPriceCents'],
                'durationDeltaSec' => $point['totalDurationSec'] - $baseline['totalDurationSec'],
                'items' => $point['items'],
            ];
        };

        $appendLabel($cheapest, 'Pigesnis');
        $appendLabel($fastest, 'Greitesnis');
        $appendLabel($singleStore, 'Vienoje parduotuvėje');

        $balanced = $this->balancedPoint($points, $baseline);
        $appendLabel($balanced, 'Subalansuotas');

        return $labelled;
    }

    /**
     * @param  list<array{totalPriceCents: int, totalDurationSec: int, stores: list<string>, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, snapshotId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, image: ?string, fixed: bool, changed: bool}>}>  $points
     * @param  callable(array<string, mixed>): int  $key
     * @return array<string, mixed>|null
     */
    private function argMin(array $points, callable $key): ?array
    {
        if ($points === []) {
            return null;
        }

        $bestIdx = 0;
        $bestVal = $key($points[0]);
        for ($i = 1; $i < count($points); $i++) {
            $v = $key($points[$i]);
            if ($v < $bestVal) {
                $bestVal = $v;
                $bestIdx = $i;
            }
        }

        return $points[$bestIdx];
    }

    /**
     * @param  list<array{totalPriceCents: int, totalDurationSec: int, stores: list<string>, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, snapshotId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, image: ?string, fixed: bool, changed: bool}>}>  $points
     * @param  array{totalPriceCents: int, totalDurationSec: int, stores: list<string>}  $baseline
     * @return array<string, mixed>|null
     */
    private function balancedPoint(array $points, array $baseline): ?array
    {
        if ($points === []) {
            return null;
        }

        $priceMax = max(array_map(fn (array $p): int => $p['totalPriceCents'], $points));
        $priceMin = min(array_map(fn (array $p): int => $p['totalPriceCents'], $points));
        $timeMax = max(array_map(fn (array $p): int => $p['totalDurationSec'], $points));
        $timeMin = min(array_map(fn (array $p): int => $p['totalDurationSec'], $points));

        $priceRange = max(1, $priceMax - $priceMin);
        $timeRange = max(1, $timeMax - $timeMin);

        $bestScore = -INF;
        $best = null;
        foreach ($points as $p) {
            $priceSaved = ($baseline['totalPriceCents'] - $p['totalPriceCents']) / $priceRange;
            $timeSaved = ($baseline['totalDurationSec'] - $p['totalDurationSec']) / $timeRange;
            $score = $priceSaved + $timeSaved;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $p;
            }
        }

        return $best;
    }

    /**
     * @param  array{totalPriceCents: int, totalDurationSec: int, stores: list<string>}  $point
     */
    private function pointKey(array $point): string
    {
        $stores = $point['stores'];
        sort($stores);

        return implode(',', $stores).'|'.$point['totalPriceCents'].'|'.$point['totalDurationSec'];
    }
}
