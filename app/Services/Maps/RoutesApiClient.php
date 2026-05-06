<?php

declare(strict_types=1);

namespace App\Services\Maps;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * @phpstan-type LatLng array{lat: float, lng: float}
 * @phpstan-type RouteResult array{
 *     order: list<int>,
 *     polyline: string,
 *     totalDurationSec: int,
 *     totalDistanceMeters: int,
 *     legs: list<array{durationSec: int, distanceMeters: int}>
 * }
 */
class RoutesApiClient
{
    private const ENDPOINT = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    private const FIELD_MASK = 'routes.optimizedIntermediateWaypointIndex,'
        .'routes.duration,'
        .'routes.distanceMeters,'
        .'routes.polyline.encodedPolyline,'
        .'routes.legs.duration,'
        .'routes.legs.distanceMeters';

    public function __construct(private readonly ?string $apiKey) {}

    /**
     * @param  LatLng  $origin
     * @param  LatLng  $destination
     * @param  list<LatLng>  $intermediates
     * @param  'WALK'|'DRIVE'  $mode
     * @return RouteResult
     */
    public function computeRoute(
        array $origin,
        array $destination,
        array $intermediates,
        string $mode,
    ): array {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new RuntimeException('Google Routes API key is not configured.');
        }

        $payload = [
            'origin' => self::asWaypoint($origin),
            'destination' => self::asWaypoint($destination),
            'intermediates' => array_map(self::asWaypoint(...), $intermediates),
            'travelMode' => $mode,
            'optimizeWaypointOrder' => true,
            'polylineQuality' => 'OVERVIEW',
            'computeAlternativeRoutes' => false,
            'languageCode' => 'lt-LT',
            'units' => 'METRIC',
        ];

        if ($mode === 'DRIVE') {
            $payload['routingPreference'] = 'TRAFFIC_AWARE';
        }

        try {
            $response = Http::asJson()
                ->withHeaders([
                    'X-Goog-Api-Key' => $this->apiKey,
                    'X-Goog-FieldMask' => self::FIELD_MASK,
                ])
                ->timeout(8)
                ->post(self::ENDPOINT, $payload)
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException(
                'Routes API request failed: '.$e->getMessage(),
                previous: $e,
            );
        }

        $route = $response->json('routes.0');

        if (! is_array($route)) {
            throw new RuntimeException('Routes API returned no routes.');
        }

        return [
            'order' => array_map('intval', $route['optimizedIntermediateWaypointIndex'] ?? []),
            'polyline' => (string) ($route['polyline']['encodedPolyline'] ?? ''),
            'totalDurationSec' => self::durationToSeconds($route['duration'] ?? null),
            'totalDistanceMeters' => (int) ($route['distanceMeters'] ?? 0),
            'legs' => array_map(
                fn (array $leg): array => [
                    'durationSec' => self::durationToSeconds($leg['duration'] ?? null),
                    'distanceMeters' => (int) ($leg['distanceMeters'] ?? 0),
                ],
                $route['legs'] ?? [],
            ),
        ];
    }

    /**
     * @param  LatLng  $coord
     * @return array{location: array{latLng: array{latitude: float, longitude: float}}}
     */
    private static function asWaypoint(array $coord): array
    {
        return [
            'location' => [
                'latLng' => [
                    'latitude' => $coord['lat'],
                    'longitude' => $coord['lng'],
                ],
            ],
        ];
    }

    private static function durationToSeconds(mixed $duration): int
    {
        if (! is_string($duration)) {
            return 0;
        }

        return (int) rtrim($duration, 's');
    }
}
