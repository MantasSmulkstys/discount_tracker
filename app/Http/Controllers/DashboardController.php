<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $selectedStore = Str::lower($request->string('store')->toString());

        $stores = DB::table('stores')
            ->leftJoin('raw_offers', 'raw_offers.store_id', '=', 'stores.id')
            ->selectRaw('stores.name, COUNT(raw_offers.id) as offers_count')
            ->groupBy('stores.id', 'stores.name')
            ->orderByDesc('offers_count')
            ->get()
            ->map(function (object $store) use ($selectedStore): array {
                $name = Str::title($store->name);
                $slug = Str::lower($store->name);

                return [
                    'name' => $name,
                    'slug' => $slug,
                    'count' => (int) $store->offers_count,
                    'active' => $selectedStore === $slug,
                ];
            });

        $offersQuery = DB::table('raw_offers')
            ->join('stores', 'stores.id', '=', 'raw_offers.store_id')
            ->select([
                'raw_offers.id',
                'raw_offers.name',
                'raw_offers.price',
                'raw_offers.old_price',
                'raw_offers.unit_price',
                'raw_offers.image',
                'raw_offers.url',
                'raw_offers.valid_dates',
                'raw_offers.loyalty_card',
                'raw_offers.scraped_at',
                'stores.name as store_name',
            ])
            ->selectRaw('
                COALESCE(
                    raw_offers.discount,
                    CASE
                        WHEN raw_offers.old_price IS NOT NULL
                            AND raw_offers.old_price > 0
                            AND raw_offers.price IS NOT NULL
                        THEN ROUND(((raw_offers.old_price - raw_offers.price) / raw_offers.old_price) * 100, 2)
                    END
                ) as effective_discount
            ')
            ->when($selectedStore !== '', function ($query) use ($selectedStore) {
                $query->whereRaw('LOWER(stores.name) = ?', [$selectedStore]);
            })
            ->orderByRaw('CASE WHEN COALESCE(raw_offers.discount, raw_offers.old_price) IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('effective_discount')
            ->orderByDesc('raw_offers.scraped_at')
            ->orderBy('raw_offers.id');

        $paginatedOffers = $offersQuery
            ->paginate(12)
            ->withQueryString();

        $products = [
            'data' => collect($paginatedOffers->items())->map(function (object $offer): array {
                $price = $offer->price !== null
                    ? Number::currency((float) $offer->price, 'EUR', locale: 'lt_LT')
                    : null;

                $oldPrice = $offer->old_price !== null
                    ? Number::currency((float) $offer->old_price, 'EUR', locale: 'lt_LT')
                    : null;

                $discount = $offer->effective_discount !== null
                    ? '-'.(int) round((float) $offer->effective_discount).'%'
                    : null;

                return [
                    'id' => $offer->id,
                    'name' => $offer->name,
                    'store' => Str::title($offer->store_name),
                    'price' => $price,
                    'oldPrice' => $oldPrice,
                    'discount' => $discount,
                    'unitPrice' => $offer->unit_price,
                    'image' => $offer->image,
                    'url' => $offer->url,
                    'validDates' => $offer->valid_dates,
                    'loyaltyCard' => (bool) $offer->loyalty_card,
                    'scrapedAt' => $offer->scraped_at,
                ];
            })->values()->all(),
            'meta' => [
                'from' => $paginatedOffers->firstItem(),
                'to' => $paginatedOffers->lastItem(),
                'total' => $paginatedOffers->total(),
                'currentPage' => $paginatedOffers->currentPage(),
                'lastPage' => $paginatedOffers->lastPage(),
                'hasPages' => $paginatedOffers->hasPages(),
            ],
            'links' => $paginatedOffers->linkCollection()->map(function (array $link): array {
                $label = strip_tags(html_entity_decode($link['label'], ENT_QUOTES | ENT_HTML5));

                return [
                    'url' => $link['url'],
                    'label' => $label,
                    'active' => $link['active'],
                ];
            })->all(),
        ];

        $summary = [
            [
                'label' => 'Offers live',
                'value' => (string) DB::table('raw_offers')->count(),
            ],
            [
                'label' => 'Stores tracked',
                'value' => (string) DB::table('stores')->count(),
            ],
            [
                'label' => 'With discount data',
                'value' => (string) DB::table('raw_offers')->whereNotNull('discount')->count(),
            ],
        ];

        return Inertia::render('Dashboard', [
            'summary' => $summary,
            'stores' => $stores,
            'currentStore' => $selectedStore !== '' ? Str::title($selectedStore) : 'All stores',
            'products' => $products,
        ]);
    }
}
