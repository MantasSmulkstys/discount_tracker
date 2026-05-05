<?php

declare(strict_types=1);

namespace App\Services\Maps;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around Google's Places API (New) `searchText` endpoint.
 *
 * Used to discover the nearest physical store for a given retail chain by
 * biasing a text search to a circle around the user's origin. This replaces
 * the static `config/store_locations.php` lookup table — the search returns
 * authoritative Google Maps data and never goes stale.
 *
 * Field mask is intentionally minimal to keep this on the cheaper Essentials
 * SKU. The cost is per request, so callers should cache by (chain, rounded
 * origin).
 *
 * @phpstan-type LatLng array{lat: float, lng: float}
 * @phpstan-type Place array{name: string, address: string, city: string, lat: float, lng: float}
 */
class PlacesApiClient
{
    private const ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

    private const FIELD_MASK = 'places.displayName,'
        .'places.formattedAddress,'
        .'places.shortFormattedAddress,'
        .'places.location,'
        .'places.addressComponents';

    public function __construct(private readonly ?string $apiKey) {}

    /**
     * Find the nearest store of the given chain to the supplied origin.
     *
     * @param  LatLng  $origin
     * @return Place|null Null when the API returns zero results for that chain.
     */
    public function findNearestStore(
        string $chainQuery,
        array $origin,
        int $radiusMeters = 15000,
    ): ?array {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new RuntimeException('Google Places API key is not configured.');
        }

        $payload = [
            'textQuery' => $chainQuery,
            'maxResultCount' => 1,
            'languageCode' => 'lt',
            'regionCode' => 'lt',
            'rankPreference' => 'DISTANCE',
            'locationBias' => [
                'circle' => [
                    'center' => [
                        'latitude' => $origin['lat'],
                        'longitude' => $origin['lng'],
                    ],
                    'radius' => $radiusMeters,
                ],
            ],
        ];

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
                'Places API request failed: '.$e->getMessage(),
                previous: $e,
            );
        }

        $place = $response->json('places.0');
        if (! is_array($place)) {
            return null;
        }

        return [
            'name' => (string) ($place['displayName']['text'] ?? $chainQuery),
            'address' => (string) ($place['shortFormattedAddress'] ?? $place['formattedAddress'] ?? ''),
            'city' => self::extractCity($place),
            'lat' => (float) ($place['location']['latitude'] ?? 0.0),
            'lng' => (float) ($place['location']['longitude'] ?? 0.0),
        ];
    }

    /**
     * Pull the city/locality out of the address components, falling back to
     * an empty string when Google doesn't tag one.
     *
     * @param  array<string, mixed>  $place
     */
    private static function extractCity(array $place): string
    {
        $components = $place['addressComponents'] ?? [];
        if (! is_array($components)) {
            return '';
        }

        foreach ($components as $component) {
            $types = $component['types'] ?? [];
            if (is_array($types) && in_array('locality', $types, true)) {
                return (string) ($component['shortText'] ?? $component['longText'] ?? '');
            }
        }

        return '';
    }
}
