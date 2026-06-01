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
     * @param  list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, quantity: int, storeSlug: ?string, priceCents: ?int}>  $items
     * @return list<array{
     *     input: array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, quantity: int, storeSlug: ?string, priceCents: ?int},
     *     fixed: bool,
     *     fallbackName: string,
     *     offers: array<string, array{priceCents: int, name: string, variantId: int}>
     * }>
     */
    private function buildCandidates(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $offers = [];
            $fallbackName = '';

            if ($item['familyId'] !== null) {
                $rows = $this->fetchFreshOffersForFamily($item['familyId']);
                foreach ($rows as $row) {
                    $slug = Str::slug((string) $row->store_name);
                    $price = (int) round(((float) $row->price) * 100);

                    if (! isset($offers[$slug]) || $offers[$slug]['priceCents'] > $price) {
                        $offers[$slug] = [
                            'priceCents' => $price,
                            'name' => (string) $row->product_name,
                            'variantId' => (int) $row->product_variant_id,
                        ];
                        if ($fallbackName === '') {
                            $fallbackName = (string) $row->product_name;
                        }
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
     * Get all current fresh offers for a family, grouped by store.
     *
     * @return array<int, object>
     */
    private function fetchFreshOffersForFamily(int $familyId): array
    {
        $today = now()->toDateString();
        $cutoff = now()->subDays(KainuCatalog::FRESHNESS_WINDOW_DAYS)->toDateString();

        return DB::table('offer_snapshots')
            ->join('product_variants', 'product_variants.id', '=', 'offer_snapshots.product_variant_id')
            ->join('normalized_products', 'normalized_products.id', '=', 'product_variants.normalized_product_id')
            ->join('stores', 'stores.id', '=', 'offer_snapshots.store_id')
            ->whereIn('offer_snapshots.id', OfferSnapshot::latestIdsPerProductStoreSubquery())
            ->where(function ($q) use ($today): void {
                $q->whereNull('offer_snapshots.valid_to')
                    ->orWhere('offer_snapshots.valid_to', '>=', $today);
            })
            ->where('offer_snapshots.scraped_at', '>=', $cutoff)
            ->where('normalized_products.family_id', $familyId)
            ->whereNotNull('offer_snapshots.price')
            ->select([
                'offer_snapshots.id',
                'offer_snapshots.price',
                'offer_snapshots.product_variant_id',
                'normalized_products.canonical_name as product_name',
                'stores.slug as store_slug',
                'stores.name as store_name',
            ])
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
     * @param  list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, quantity: int, storeSlug: ?string, priceCents: ?int}>  $items
     * @param  list<array{
     *     input: array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, quantity: int, storeSlug: ?string, priceCents: ?int},
     *     fixed: bool,
     *     fallbackName: string,
     *     offers: array<string, array{priceCents: int, name: string, variantId: int}>
     * }>  $candidates
     * @param  list<string>  $subset
     * @return array{totalPriceCents: int, totalDurationSec: int, stores: list<string>, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, fixed: bool}>}|null
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
                    'storeSlug' => (string) ($input['storeSlug'] ?? 'other'),
                    'priceCents' => $fallbackPrice,
                    'quantity' => $input['quantity'],
                    'name' => $cand['fallbackName'] !== ''
                        ? $cand['fallbackName']
                        : 'Prekė',
                    'fixed' => true,
                ];

                continue;
            }

            $bestSlug = null;
            $bestPrice = null;
            $bestName = '';
            $bestVariantId = 0;

            foreach ($subset as $slug) {
                if (! isset($cand['offers'][$slug])) {
                    continue;
                }
                $offer = $cand['offers'][$slug];
                if ($bestPrice === null || $offer['priceCents'] < $bestPrice) {
                    $bestPrice = $offer['priceCents'];
                    $bestSlug = $slug;
                    $bestName = $offer['name'];
                    $bestVariantId = $offer['variantId'];
                }
            }

            if ($bestSlug === null || $bestPrice === null) {
                return null;
            }

            $total += $bestPrice * $input['quantity'];
            $picked[] = [
                'familyId' => $input['familyId'],
                'normalizedProductId' => $input['normalizedProductId'],
                'variantId' => $bestVariantId,
                'storeSlug' => $bestSlug,
                'priceCents' => $bestPrice,
                'quantity' => $input['quantity'],
                'name' => $bestName,
                'fixed' => false,
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
     * @param  list<array{totalPriceCents: int, totalDurationSec: int, stores: list<string>, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, fixed: bool}>}>  $points
     * @return list<array{totalPriceCents: int, totalDurationSec: int, stores: list<string>, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, fixed: bool}>}>
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
     * @param  list<array{totalPriceCents: int, totalDurationSec: int, stores: list<string>, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, fixed: bool}>}>  $points
     * @return list<array{totalPriceCents: int, totalDurationSec: int, stores: list<string>, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, fixed: bool}>}>
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
     * @param  list<array{totalPriceCents: int, totalDurationSec: int, stores: list<string>, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, fixed: bool}>}>  $points
     * @param  array{totalPriceCents: int, totalDurationSec: int, stores: list<string>}  $baseline
     * @return list<array{label: string, stores: list<string>, totalPriceCents: int, totalDurationSec: int, priceDeltaCents: int, durationDeltaSec: int, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, fixed: bool}>}>
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
     * @param  list<array{totalPriceCents: int, totalDurationSec: int, stores: list<string>, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, fixed: bool}>}>  $points
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
     * @param  list<array{totalPriceCents: int, totalDurationSec: int, stores: list<string>, items: list<array{familyId: ?int, normalizedProductId: ?int, variantId: ?int, storeSlug: string, priceCents: int, quantity: int, name: string, fixed: bool}>}>  $points
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
