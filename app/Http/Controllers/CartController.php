<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    public function index(): Response
    {
        $locations = collect(config('store_locations', []))
            ->filter(fn (array $l): bool => isset($l['chain'], $l['lat'], $l['lng']))
            ->values()
            ->map(fn (array $l, int $idx): array => [
                'id' => 'loc-'.($idx + 1),
                'chain' => Str::slug((string) $l['chain']),
                'name' => (string) $l['name'],
                'address' => (string) ($l['address'] ?? ''),
                'city' => (string) ($l['city'] ?? ''),
                'lat' => (float) $l['lat'],
                'lng' => (float) $l['lng'],
            ])
            ->all();

        return Inertia::render('kainu/Cart', [
            'storeLocations' => $locations,
            'googleMaps' => [
                'apiKey' => config('services.google_maps.key') ?: null,
                'mapId' => config('services.google_maps.map_id') ?: null,
            ],
        ]);
    }
}
