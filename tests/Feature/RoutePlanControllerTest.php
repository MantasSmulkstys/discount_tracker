<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.google_maps.key', 'test-key');
    config()->set('services.google_maps.server_key', null);

    DB::table('stores')->insert([
        ['name' => 'maxima', 'slug' => 'maxima', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'lidl', 'slug' => 'lidl', 'created_at' => now(), 'updated_at' => now()],
    ]);

    Cache::flush();
});

/**
 * Stubs Places searchText so each requested chain gets its own response.
 * Pass an array of arrays keyed `places.0.*` so the controller's parser is happy.
 */
function fakePlacesAndRoutes(array $placeByChain, array $routePayload): void
{
    $sequence = Http::sequence();
    foreach ($placeByChain as $place) {
        $sequence->push([
            'places' => [[
                'displayName' => ['text' => $place['name']],
                'shortFormattedAddress' => $place['address'],
                'formattedAddress' => $place['address'],
                'addressComponents' => [
                    ['types' => ['locality'], 'shortText' => $place['city'], 'longText' => $place['city']],
                ],
                'location' => [
                    'latitude' => $place['lat'],
                    'longitude' => $place['lng'],
                ],
            ]],
        ]);
    }

    Http::fake([
        'places.googleapis.com/*' => $sequence,
        'routes.googleapis.com/*' => Http::response($routePayload),
    ]);
}

test('returns optimised route built from Places lookups + Routes API', function (): void {
    fakePlacesAndRoutes(
        placeByChain: [
            ['name' => 'Maxima Pilaitė', 'address' => 'Vydūno g. 30', 'city' => 'Vilnius', 'lat' => 54.69, 'lng' => 25.20],
            ['name' => 'Lidl Centras', 'address' => 'Žirmūnų g. 1', 'city' => 'Vilnius', 'lat' => 54.71, 'lng' => 25.30],
        ],
        routePayload: [
            'routes' => [[
                'optimizedIntermediateWaypointIndex' => [1, 0],
                'duration' => '900s',
                'distanceMeters' => 12000,
                'polyline' => ['encodedPolyline' => 'abc123'],
                'legs' => [
                    ['duration' => '300s', 'distanceMeters' => 4000],
                    ['duration' => '350s', 'distanceMeters' => 5000],
                    ['duration' => '250s', 'distanceMeters' => 3000],
                ],
            ]],
        ],
    );

    $response = $this->postJson('/cart/route-plan', [
        'origin' => ['lat' => 54.69, 'lng' => 25.28],
        'chains' => ['maxima', 'lidl'],
        'mode' => 'driving',
    ]);

    $response->assertOk()
        ->assertJsonPath('mode', 'driving')
        ->assertJsonPath('polyline', 'abc123')
        ->assertJsonPath('totalDurationSec', 900)
        ->assertJsonPath('totalDistanceMeters', 12000)
        ->assertJsonPath('returnLeg.durationSec', 250)
        ->assertJsonCount(2, 'stops')
        // Google said visit index 1 first (lidl), then index 0 (maxima).
        ->assertJsonPath('stops.0.chain', 'lidl')
        ->assertJsonPath('stops.1.chain', 'maxima')
        ->assertJsonPath('stops.0.name', 'Lidl Centras')
        ->assertJsonPath('stops.1.name', 'Maxima Pilaitė');

    Http::assertSent(function ($request): bool {
        return str_starts_with($request->url(), 'https://places.googleapis.com/v1/places:searchText')
            && $request->header('X-Goog-Api-Key') === ['test-key']
            && in_array($request['textQuery'], ['Maxima', 'Lidl'], true);
    });
});

test('biases each Places search to a circle around the user origin', function (): void {
    fakePlacesAndRoutes(
        placeByChain: [
            ['name' => 'Maxima A', 'address' => 'A 1', 'city' => 'Vilnius', 'lat' => 54.69, 'lng' => 25.28],
        ],
        routePayload: [
            'routes' => [[
                'optimizedIntermediateWaypointIndex' => [0],
                'duration' => '60s',
                'distanceMeters' => 500,
                'polyline' => ['encodedPolyline' => 'p'],
                'legs' => [
                    ['duration' => '30s', 'distanceMeters' => 250],
                    ['duration' => '30s', 'distanceMeters' => 250],
                ],
            ]],
        ],
    );

    $this->postJson('/cart/route-plan', [
        'origin' => ['lat' => 54.69, 'lng' => 25.28],
        'chains' => ['maxima'],
        'mode' => 'walking',
    ])->assertOk();

    Http::assertSent(function ($request): bool {
        if (! str_starts_with($request->url(), 'https://places.googleapis.com/v1/places:searchText')) {
            return false;
        }

        $bias = $request['locationBias']['circle'] ?? null;

        return $bias !== null
            && $bias['center']['latitude'] === 54.69
            && $bias['center']['longitude'] === 25.28
            && $bias['radius'] === 15000;
    });
});

test('caches Places + Routes lookups, skipping APIs on the second call', function (): void {
    fakePlacesAndRoutes(
        placeByChain: [
            ['name' => 'Maxima A', 'address' => 'A 1', 'city' => 'Vilnius', 'lat' => 54.69, 'lng' => 25.28],
        ],
        routePayload: [
            'routes' => [[
                'optimizedIntermediateWaypointIndex' => [0],
                'duration' => '60s',
                'distanceMeters' => 500,
                'polyline' => ['encodedPolyline' => 'cached'],
                'legs' => [['duration' => '30s', 'distanceMeters' => 250]],
            ]],
        ],
    );

    $payload = [
        'origin' => ['lat' => 54.69, 'lng' => 25.28],
        'chains' => ['maxima'],
        'mode' => 'driving',
    ];

    $this->postJson('/cart/route-plan', $payload)->assertOk();
    $this->postJson('/cart/route-plan', $payload)->assertOk()
        ->assertJsonPath('polyline', 'cached');

    // Once each — both places and routes results came from cache the second time.
    Http::assertSentCount(2);
});

test('returns 422 when Places returns no results for any chain', function (): void {
    Http::fake([
        'places.googleapis.com/*' => Http::response(['places' => []]),
    ]);

    $this->postJson('/cart/route-plan', [
        'origin' => ['lat' => 54.69, 'lng' => 25.28],
        'chains' => ['unknown-chain'],
        'mode' => 'walking',
    ])->assertStatus(422);

    Http::assertNotSent(fn ($r): bool => str_starts_with(
        $r->url(),
        'https://routes.googleapis.com/'
    ));
});

test('returns 422 when validation fails', function (): void {
    $this->postJson('/cart/route-plan', [
        'origin' => ['lat' => 999, 'lng' => 25.28],
        'chains' => [],
        'mode' => 'flying',
    ])->assertStatus(422);
});

test('returns 502 when Google Routes API errors after a successful Places lookup', function (): void {
    fakePlacesAndRoutes(
        placeByChain: [
            ['name' => 'Maxima A', 'address' => 'A 1', 'city' => 'Vilnius', 'lat' => 54.69, 'lng' => 25.28],
        ],
        routePayload: [],
    );
    Http::fake([
        'places.googleapis.com/*' => Http::response([
            'places' => [[
                'displayName' => ['text' => 'Maxima A'],
                'shortFormattedAddress' => 'A 1',
                'formattedAddress' => 'A 1',
                'addressComponents' => [['types' => ['locality'], 'shortText' => 'Vilnius', 'longText' => 'Vilnius']],
                'location' => ['latitude' => 54.69, 'longitude' => 25.28],
            ]],
        ]),
        'routes.googleapis.com/*' => Http::response(['error' => 'boom'], 500),
    ]);

    $this->postJson('/cart/route-plan', [
        'origin' => ['lat' => 54.69, 'lng' => 25.28],
        'chains' => ['maxima'],
        'mode' => 'walking',
    ])->assertStatus(502)
        ->assertJsonPath('message', 'Nepavyko apskaičiuoti maršruto. Patikrinkite Google Maps konfigūraciją.');
});

test('returns 502 with a clear detail when Places API rejects the call', function (): void {
    Http::fake([
        'places.googleapis.com/*' => Http::response(['error' => [
            'message' => 'Requests from referer <empty> are blocked.',
        ]], 403),
    ]);

    $this->postJson('/cart/route-plan', [
        'origin' => ['lat' => 54.69, 'lng' => 25.28],
        'chains' => ['maxima'],
        'mode' => 'driving',
    ])->assertStatus(502)
        ->assertJsonPath('message', 'Nepavyko surasti parduotuvių. Patikrinkite Google Maps konfigūraciją.')
        ->assertJsonPath(
            'detail',
            fn (string $detail): bool => str_starts_with(
                $detail,
                'Places API request failed: HTTP request returned status code 403',
            ),
        );
});
