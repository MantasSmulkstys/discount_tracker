<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OfferSnapshot;
use App\Services\KainuCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class HomeController extends Controller
{
    public function __construct(private readonly KainuCatalog $catalog) {}

    public function __invoke(): Response
    {
        if (! $this->catalogTablesReady()) {
            return Inertia::render('kainu/Home', [
                'canRegister' => Features::enabled(Features::registration()),
                'topDeals' => [],
                'trending' => [],
                'stores' => [],
                'stats' => [
                    'offersCount' => 0,
                    'storeCount' => 0,
                    'averageDiscount' => 0,
                ],
            ]);
        }

        $topDeals = $this->catalog
            ->offersQuery()
            ->whereNotNull('offer_snapshots.price')
            ->whereNotNull('offer_snapshots.old_price')
            ->orderByRaw('COALESCE(offer_snapshots.discount_percent, ((offer_snapshots.old_price - offer_snapshots.price) / NULLIF(offer_snapshots.old_price, 0)) * 100) DESC NULLS LAST')
            ->orderByDesc('offer_snapshots.scraped_at')
            ->limit(8)
            ->get()
            ->map(fn (object $row) => $this->catalog->hydrate($row))
            ->values();

        $trending = $this->catalog
            ->offersQuery()
            ->whereNotNull('offer_snapshots.price')
            ->orderByDesc('offer_snapshots.scraped_at')
            ->orderByRaw('COALESCE(offer_snapshots.discount_percent, 0) DESC')
            ->limit(4)
            ->get()
            ->map(fn (object $row) => $this->catalog->hydrate($row))
            ->values();

        $latestIds = OfferSnapshot::latestIdsPerProductStoreSubquery();
        $today = now()->toDateString();
        $cutoff = now()->subDays(KainuCatalog::FRESHNESS_WINDOW_DAYS)->toDateString();

        $stores = DB::table('stores')
            ->leftJoin('offer_snapshots', function ($join) use ($latestIds, $today, $cutoff): void {
                $join->on('offer_snapshots.store_id', '=', 'stores.id')
                    ->whereIn('offer_snapshots.id', $latestIds)
                    ->where(function ($q) use ($today): void {
                        $q->whereNull('offer_snapshots.valid_to')
                            ->orWhere('offer_snapshots.valid_to', '>=', $today);
                    })
                    ->where('offer_snapshots.scraped_at', '>=', $cutoff);
            })
            ->selectRaw('stores.id, stores.name, COUNT(offer_snapshots.id) as offers_count')
            ->groupBy('stores.id', 'stores.name')
            ->orderByDesc('offers_count')
            ->get()
            ->map(fn (object $s): array => [
                'id' => Str::slug((string) $s->name),
                'name' => Str::title((string) $s->name),
                'count' => (int) $s->offers_count,
            ])
            ->values();

        $statsQuery = fn () => DB::table('offer_snapshots')
            ->whereIn('id', OfferSnapshot::latestIdsPerProductStoreSubquery())
            ->where(function ($q) use ($today): void {
                $q->whereNull('offer_snapshots.valid_to')
                    ->orWhere('offer_snapshots.valid_to', '>=', $today);
            })
            ->where('offer_snapshots.scraped_at', '>=', $cutoff);

        $totalOffers = (int) $statsQuery()->count();

        $storeCount = $stores->count();

        $averageDiscount = (float) $statsQuery()
            ->whereNotNull('discount_percent')
            ->avg('discount_percent');

        return Inertia::render('kainu/Home', [
            'canRegister' => Features::enabled(Features::registration()),
            'topDeals' => $topDeals,
            'trending' => $trending,
            'stores' => $stores,
            'stats' => [
                'offersCount' => $totalOffers,
                'storeCount' => $storeCount,
                'averageDiscount' => $averageDiscount > 0
                    ? (int) round($averageDiscount)
                    : 0,
            ],
        ]);
    }

    private function catalogTablesReady(): bool
    {
        return Schema::hasTable('offer_snapshots')
            && Schema::hasTable('product_variants')
            && Schema::hasTable('normalized_products')
            && Schema::hasTable('stores');
    }
}
