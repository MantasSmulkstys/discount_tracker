<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('kainu/Cart', [
            'googleMaps' => [
                'apiKey' => config('services.google_maps.key') ?: null,
                'mapId' => config('services.google_maps.map_id') ?: null,
            ],
        ]);
    }
}
