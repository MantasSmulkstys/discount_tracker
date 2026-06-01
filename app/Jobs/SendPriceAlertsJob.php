<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\PriceAlertDigest;
use App\Models\NotificationLog;
use App\Models\OfferSnapshot;
use App\Models\User;
use App\Services\KainuCatalog;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendPriceAlertsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    /** @var array<string, ?string> */
    private array $classifyCache = [];

    public function handle(KainuCatalog $catalog): void
    {
        $usersWithSubs = User::query()
            ->whereHas('familySubscriptions')
            ->with(['familySubscriptions.family'])
            ->get();

        $sent = 0;

        foreach ($usersWithSubs as $user) {
            $triggers = $this->triggersForUser($user, $catalog);

            if ($triggers === []) {
                continue;
            }

            Mail::to($user->email)->queue(new PriceAlertDigest(
                userName: $user->name,
                triggers: $triggers,
            ));

            $sent++;
        }

        Log::info('SendPriceAlertsJob completed', [
            'users_emailed' => $sent,
        ]);
    }

    /**
     * @return list<array{
     *     familyName: string,
     *     productName: string,
     *     storeName: string,
     *     price: float,
     *     oldPrice: ?float,
     *     triggerType: string,
     *     productUrl: ?string
     * }>
     */
    private function triggersForUser(User $user, KainuCatalog $catalog): array
    {
        $triggers = [];

        foreach ($user->familySubscriptions as $sub) {
            if ($sub->family_id === null) {
                continue;
            }

            $offers = $this->freshOffersForFamily($sub->family_id);

            foreach ($offers as $offer) {
                $alreadySent = NotificationLog::query()
                    ->where('user_id', $user->id)
                    ->where('family_id', $sub->family_id)
                    ->where('store_id', $offer->store_id)
                    ->where('current_offer_snapshot_id', $offer->snapshot_id)
                    ->exists();

                if ($alreadySent) {
                    continue;
                }

                $triggerType = $this->classify($offer);

                if ($triggerType === null) {
                    continue;
                }

                NotificationLog::create([
                    'user_id' => $user->id,
                    'family_id' => $sub->family_id,
                    'store_id' => $offer->store_id,
                    'current_offer_snapshot_id' => $offer->snapshot_id,
                    'trigger_type' => $triggerType,
                    'sent_at' => now(),
                ]);

                $triggers[] = [
                    'familyName' => (string) ($sub->family?->family_name
                        ?? $sub->family?->normalized_family_name
                        ?? 'Prekė'),
                    'productName' => (string) $offer->product_name,
                    'storeName' => Str::title((string) $offer->store_name),
                    'price' => (float) $offer->price,
                    'oldPrice' => $offer->old_price !== null
                        ? (float) $offer->old_price
                        : null,
                    'triggerType' => $triggerType,
                    'productUrl' => null,
                ];
            }
        }

        return $triggers;
    }

    /**
     * @return Collection<int, object>
     */
    private function freshOffersForFamily(int $familyId): Collection
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
                'offer_snapshots.id as snapshot_id',
                'offer_snapshots.store_id',
                'offer_snapshots.price',
                'offer_snapshots.old_price',
                'offer_snapshots.scraped_at',
                'offer_snapshots.product_variant_id',
                'normalized_products.canonical_name as product_name',
                'stores.name as store_name',
            ])
            ->get();
    }

    /**
     * @return 'new'|'price_drop'|'reappearance'|null
     */
    private function classify(object $offer): ?string
    {
        $cacheKey = sprintf(
            '%d:%d:%d',
            (int) $offer->store_id,
            (int) $offer->product_variant_id,
            (int) $offer->snapshot_id,
        );

        if (array_key_exists($cacheKey, $this->classifyCache)) {
            /** @var 'new'|'price_drop'|'reappearance'|null $cached */
            $cached = $this->classifyCache[$cacheKey];

            return $cached;
        }

        $result = $this->classifyUncached($offer);
        $this->classifyCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * @return 'new'|'price_drop'|'reappearance'|null
     */
    private function classifyUncached(object $offer): ?string
    {
        $previous = DB::table('offer_snapshots')
            ->where('product_variant_id', $offer->product_variant_id)
            ->where('store_id', $offer->store_id)
            ->where('id', '<', $offer->snapshot_id)
            ->orderByDesc('id')
            ->first(['price', 'scraped_at']);

        if ($previous === null) {
            return 'new';
        }

        $previousDate = Carbon::parse((string) $previous->scraped_at);
        $offerDate = Carbon::parse((string) $offer->scraped_at);

        if ($previousDate->diffInDays($offerDate) >= 7) {
            return 'reappearance';
        }

        if ($previous->price !== null && (float) $previous->price > (float) $offer->price) {
            return 'price_drop';
        }

        return null;
    }
}
