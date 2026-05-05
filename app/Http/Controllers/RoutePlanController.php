<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CartRoutePlanRequest;
use App\Models\Store;
use App\Services\Maps\PlacesApiClient;
use App\Services\Maps\RoutesApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class RoutePlanController extends Controller
{
    /**
     * Round-trip route planner for a cart spread across N store chains.
     *
     * For each requested chain, asks Google Places (Text Search, biased to a
     * 15 km circle around the user's origin) for the nearest physical store —
     * no static lookup table involved. The resulting stops are sent to the
     * Routes API which optimises the visit order and returns a polyline plus
     * per-leg durations. Both the per-chain Places lookup and the final
     * Routes call are cached so retries and shared links don't re-bill.
     */
    public function __invoke(
        CartRoutePlanRequest $request,
        RoutesApiClient $routes,
        PlacesApiClient $places,
    ): JsonResponse {
        $origin = $request->origin();
        $chains = $request->chains();
        $travelMode = $request->travelMode();

        try {
            $stops = $this->resolveStops($places, $origin, $chains);
        } catch (RuntimeException $e) {
            Log::warning('RoutePlan: Places lookup failed', [
                'detail' => $e->getMessage(),
                'origin' => $origin,
                'chains' => $chains,
            ]);

            return response()->json([
                'message' => 'Nepavyko surasti parduotuvių. Patikrinkite Google Maps konfigūraciją.',
                'detail' => $e->getMessage(),
            ], 502);
        }

        if ($stops === []) {
            return response()->json([
                'message' => 'Nerasta nė vienos parduotuvės pasirinktoms tinkloms.',
            ], 422);
        }

        $cacheKey = $this->routeCacheKey($origin, $stops, $travelMode);

        try {
            $payload = Cache::remember(
                $cacheKey,
                now()->addHours(6),
                fn (): array => $this->buildRoute($routes, $origin, $stops, $travelMode),
            );
        } catch (RuntimeException $e) {
            Log::warning('RoutePlan: Routes API failed', [
                'detail' => $e->getMessage(),
                'origin' => $origin,
                'stops' => $stops,
                'mode' => $travelMode,
            ]);

            return response()->json([
                'message' => 'Nepavyko apskaičiuoti maršruto. Patikrinkite Google Maps konfigūraciją.',
                'detail' => $e->getMessage(),
            ], 502);
        }

        return response()->json($payload);
    }

    /**
     * Resolve each requested chain to a single Place near the user. Cached
     * per (chain, rounded origin) so repeat planning of the same cart on the
     * same day doesn't re-bill Places.
     *
     * @param  array{lat: float, lng: float}  $origin
     * @param  list<string>  $chains
     * @return list<array{chain: string, name: string, address: string, city: string, lat: float, lng: float}>
     */
    private function resolveStops(PlacesApiClient $places, array $origin, array $chains): array
    {
        $queries = $this->chainQueryMap($chains);
        $stops = [];

        foreach ($chains as $chain) {
            $query = $queries[$chain] ?? Str::title($chain);

            $cacheKey = sprintf(
                'place_lookup:%s:%.2f,%.2f',
                $chain,
                $origin['lat'],
                $origin['lng'],
            );

            $place = Cache::remember(
                $cacheKey,
                now()->addHours(24),
                fn (): ?array => $places->findNearestStore($query, $origin),
            );

            if ($place === null) {
                continue;
            }

            $stops[] = [
                'chain' => $chain,
                'name' => $place['name'],
                'address' => $place['address'],
                'city' => $place['city'],
                'lat' => $place['lat'],
                'lng' => $place['lng'],
            ];
        }

        return $stops;
    }

    /**
     * Per-chain Places search query. We default to title-casing the slug
     * (`maxima` → `Maxima`), but the `stores` table can override via a
     * (future) `search_query` column — we read it here so admins can tune
     * specific chains without code changes.
     *
     * @param  list<string>  $chains
     * @return array<string, string>
     */
    private function chainQueryMap(array $chains): array
    {
        $rows = Store::query()
            ->whereIn('slug', $chains)
            ->get(['slug', 'name']);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->slug] = Str::title((string) $row->name);
        }

        return $map;
    }

    /**
     * @param  array{lat: float, lng: float}  $origin
     * @param  list<array{chain: string, name: string, address: string, city: string, lat: float, lng: float}>  $stops
     * @param  'WALK'|'DRIVE'  $travelMode
     * @return array{
     *     mode: string,
     *     stops: list<array{chain: string, name: string, address: string, city: string, lat: float, lng: float, durationFromPreviousSec: int, distanceFromPreviousMeters: int}>,
     *     returnLeg: array{durationSec: int, distanceMeters: int},
     *     polyline: string,
     *     totalDurationSec: int,
     *     totalDistanceMeters: int
     * }
     */
    private function buildRoute(
        RoutesApiClient $client,
        array $origin,
        array $stops,
        string $travelMode,
    ): array {
        $intermediates = array_map(
            fn (array $s): array => ['lat' => $s['lat'], 'lng' => $s['lng']],
            $stops,
        );

        $route = $client->computeRoute(
            origin: $origin,
            destination: $origin,
            intermediates: $intermediates,
            mode: $travelMode,
        );

        $orderedStops = $route['order'] !== []
            ? array_map(fn (int $idx): array => $stops[$idx], $route['order'])
            : $stops;

        $legs = $route['legs'];
        $orderedWithLegs = [];

        foreach ($orderedStops as $i => $stop) {
            $leg = $legs[$i] ?? ['durationSec' => 0, 'distanceMeters' => 0];

            $orderedWithLegs[] = [
                ...$stop,
                'durationFromPreviousSec' => $leg['durationSec'],
                'distanceFromPreviousMeters' => $leg['distanceMeters'],
            ];
        }

        $returnLeg = $legs[count($orderedStops)] ?? ['durationSec' => 0, 'distanceMeters' => 0];

        return [
            'mode' => $travelMode === 'WALK' ? 'walking' : 'driving',
            'stops' => $orderedWithLegs,
            'returnLeg' => $returnLeg,
            'polyline' => $route['polyline'],
            'totalDurationSec' => $route['totalDurationSec'],
            'totalDistanceMeters' => $route['totalDistanceMeters'],
        ];
    }

    /**
     * @param  array{lat: float, lng: float}  $origin
     * @param  list<array{chain: string, lat: float, lng: float}>  $stops
     * @param  'WALK'|'DRIVE'  $mode
     */
    private function routeCacheKey(array $origin, array $stops, string $mode): string
    {
        $originKey = sprintf('%.2f,%.2f', $origin['lat'], $origin['lng']);
        $stopKey = collect($stops)
            ->map(fn (array $s): string => $s['chain'].':'.sprintf('%.4f,%.4f', $s['lat'], $s['lng']))
            ->sort()
            ->implode('|');

        return 'route_plan:'.$mode.':'.sha1($originKey.'#'.$stopKey);
    }
}
