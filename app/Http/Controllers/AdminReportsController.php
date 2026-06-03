<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NormalizedProduct;
use App\Models\OfferSnapshot;
use App\Models\ProductVariant;
use App\Services\AdminProductPayload;
use App\Services\KainuCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportsController extends Controller
{
    public function __construct(
        private readonly KainuCatalog $catalog,
        private readonly AdminProductPayload $payload,
    ) {}

    public function index(): Response
    {
        $floor = $this->catalog->visibilityFloor();

        $userReported = NormalizedProduct::visible()
            ->withCount('reports')
            ->has('reports', '>=', 5)
            ->with(['category', 'subcategory'])
            ->when($floor !== null, fn ($q) => $q->whereHas(
                'offerSnapshots',
                fn ($s) => $s->where('scraped_at', '>=', $floor),
            ))
            ->orderByDesc('reports_count')
            ->get()
            ->map(fn (NormalizedProduct $p): array => $this->payload->forProduct($p));

        $driver = DB::getDriverName();
        $currentBatchWeek = $this->catalog->currentBatchWeek();

        $aiFlagged = $currentBatchWeek === null
            ? collect()
            : NormalizedProduct::visible()
                ->flaggedForReview()
                ->withCount('reports')
                ->with(['category', 'subcategory'])
                ->whereHas(
                    'offerSnapshots',
                    fn ($s) => $s->where('week_starting', $currentBatchWeek),
                )
                ->orderByRaw($driver === 'sqlite'
                    ? 'CASE WHEN ai_confidence IS NULL THEN 1 ELSE 0 END, ai_confidence ASC'
                    : 'ai_confidence ASC NULLS LAST'
                )
                ->get()
                ->map(fn (NormalizedProduct $p): array => $this->payload->forProduct($p));

        // Hidden cards, but only the ones still on a current listing — a hidden
        // product whose promotion has ended drops off here automatically.
        $hiddenIds = $this->catalog->currentlyListedHiddenProductIds();
        $hidden = NormalizedProduct::withCount('reports')
            ->with(['category', 'subcategory'])
            ->whereIn('id', $hiddenIds)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (NormalizedProduct $p): array => $this->payload->forProduct($p));

        return Inertia::render('admin/Reports', [
            'userReported' => $userReported,
            'aiFlagged' => $aiFlagged,
            'hidden' => $hidden,
            'categories' => $this->catalog->loadCategoryTree(),
            'families' => $this->catalog->loadFamilies(),
        ]);
    }

    public function hide(NormalizedProduct $normalizedProduct): RedirectResponse
    {
        $normalizedProduct->update(['is_hidden' => true]);

        return back()->with('success', 'Produktas paslėptas.');
    }

    public function unhide(NormalizedProduct $normalizedProduct): RedirectResponse
    {
        $normalizedProduct->update(['is_hidden' => false]);

        return back()->with('success', 'Produktas vėl rodomas.');
    }

    public function update(Request $request, NormalizedProduct $normalizedProduct): RedirectResponse
    {
        $validated = $request->validate([
            'canonical_name' => ['required', 'string', 'max:500'],
            'brand' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'subcategory_id' => ['nullable', 'integer', Rule::exists('subcategories', 'id')],
            'family_id' => ['nullable', 'integer', Rule::exists('product_families', 'id')],
            'offers' => ['nullable', 'array'],
            'offers.*.snapshotId' => ['required', 'integer', Rule::exists('offer_snapshots', 'id')],
            'offers.*.storeId' => ['required', 'integer'],
            'offers.*.variantId' => ['required', 'integer'],
            'offers.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'offers.*.oldPrice' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'offers.*.discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'offers.*.condition' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($normalizedProduct, $validated): void {
            $normalizedProduct->update([
                'canonical_name' => $validated['canonical_name'],
                'brand' => $validated['brand'] ?? null,
                'category_id' => $validated['category_id'] ?? null,
                'subcategory_id' => $validated['subcategory_id'] ?? null,
                'family_id' => $validated['family_id'] ?? null,
                'needs_review' => false,
                'ai_confidence' => 1.0,
            ]);

            foreach ($validated['offers'] ?? [] as $offer) {
                $variant = ProductVariant::find($offer['variantId']);
                if (! $variant || (int) $variant->normalized_product_id !== $normalizedProduct->id) {
                    continue;
                }

                $snapshot = OfferSnapshot::find($offer['snapshotId']);
                if (! $snapshot || (int) $snapshot->product_variant_id !== (int) $variant->id) {
                    continue;
                }

                $price = $offer['price'] ?? null;
                $oldPrice = $offer['oldPrice'] ?? null;
                $update = [
                    'price' => $price,
                    'old_price' => $oldPrice,
                    'condition' => $offer['condition'] ?? null,
                    'discount_percent' => array_key_exists('discount', $offer) && $offer['discount'] !== null
                        ? round((float) $offer['discount'], 2)
                        : $this->computeDiscount($price, $oldPrice),
                ];

                OfferSnapshot::where('id', $snapshot->id)->update($update);

                DB::table('current_offers')
                    ->where('store_id', $offer['storeId'])
                    ->where('product_variant_id', $variant->id)
                    ->update($update);

                $this->syncPriceChanges((int) $snapshot->id, $price);
            }

            $normalizedProduct->reports()->delete();
        });

        return back()->with('success', 'Produktas atnaujintas.');
    }

    public function history(NormalizedProduct $normalizedProduct): JsonResponse
    {
        return response()->json([
            'history' => $this->payload->historyForProduct($normalizedProduct),
        ]);
    }

    public function historyUpdate(Request $request, NormalizedProduct $normalizedProduct): RedirectResponse
    {
        $validated = $request->validate([
            'history' => ['nullable', 'array'],
            'history.*.snapshotId' => ['required', 'integer', Rule::exists('offer_snapshots', 'id')],
            'history.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'history.*.oldPrice' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'history.*.discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'history.*.condition' => ['nullable', 'string', 'max:255'],
            'deleted_snapshot_ids' => ['nullable', 'array'],
            'deleted_snapshot_ids.*' => ['integer', Rule::exists('offer_snapshots', 'id')],
        ]);

        DB::transaction(function () use ($normalizedProduct, $validated): void {
            $this->applyHistoryEdits($normalizedProduct, $validated['history'] ?? []);
            $this->deleteHistorySnapshots($normalizedProduct, $validated['deleted_snapshot_ids'] ?? []);
        });

        return back()->with('success', 'Kainų istorija atnaujinta.');
    }

    /**
     * Edit historical snapshots. current_offers is only touched when the edited
     * snapshot is still the active one for its (store, variant).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function applyHistoryEdits(NormalizedProduct $normalizedProduct, array $rows): void
    {
        foreach ($rows as $row) {
            $snapshot = OfferSnapshot::find($row['snapshotId']);
            if (! $this->snapshotBelongsToProduct($snapshot, $normalizedProduct)) {
                continue;
            }

            $price = $row['price'] ?? null;
            $oldPrice = $row['oldPrice'] ?? null;
            $update = [
                'price' => $price,
                'old_price' => $oldPrice,
                'condition' => $row['condition'] ?? null,
                'discount_percent' => array_key_exists('discount', $row) && $row['discount'] !== null
                    ? round((float) $row['discount'], 2)
                    : $this->computeDiscount($price, $oldPrice),
            ];

            OfferSnapshot::where('id', $snapshot->id)->update($update);

            DB::table('current_offers')
                ->where('store_id', $snapshot->store_id)
                ->where('product_variant_id', $snapshot->product_variant_id)
                ->where('last_snapshot_id', $snapshot->id)
                ->update($update);

            $this->syncPriceChanges((int) $snapshot->id, $price);
        }
    }

    /**
     * Delete bad historical snapshots and their linked price_changes. The
     * snapshot a current_offer points at is protected from deletion.
     *
     * @param  array<int, int>  $snapshotIds
     */
    private function deleteHistorySnapshots(NormalizedProduct $normalizedProduct, array $snapshotIds): void
    {
        foreach ($snapshotIds as $snapshotId) {
            $snapshot = OfferSnapshot::find($snapshotId);
            if (! $this->snapshotBelongsToProduct($snapshot, $normalizedProduct)) {
                continue;
            }

            $isCurrent = DB::table('current_offers')
                ->where('store_id', $snapshot->store_id)
                ->where('product_variant_id', $snapshot->product_variant_id)
                ->where('last_snapshot_id', $snapshot->id)
                ->exists();

            if ($isCurrent) {
                continue;
            }

            DB::table('price_changes')->where('snapshot_id', $snapshot->id)->delete();
            OfferSnapshot::where('id', $snapshot->id)->delete();
        }
    }

    private function snapshotBelongsToProduct(?OfferSnapshot $snapshot, NormalizedProduct $normalizedProduct): bool
    {
        if ($snapshot === null) {
            return false;
        }

        $variant = ProductVariant::find($snapshot->product_variant_id);

        return $variant !== null && (int) $variant->normalized_product_id === $normalizedProduct->id;
    }

    /**
     * Keep the statistics feed in sync after an admin price correction.
     *
     * `price_changes` rows are written by the normalizer and power the
     * "biggest changes" view; editing an offer's price must also update the
     * row that references that snapshot, otherwise statistics keep showing the
     * stale pre-edit price.
     */
    private function syncPriceChanges(int $snapshotId, int|float|null $newPrice): void
    {
        $rows = DB::table('price_changes')
            ->where('snapshot_id', $snapshotId)
            ->get(['id', 'previous_price']);

        foreach ($rows as $row) {
            if ($newPrice === null) {
                DB::table('price_changes')->where('id', $row->id)->delete();

                continue;
            }

            $previous = $row->previous_price !== null ? (float) $row->previous_price : null;
            $changePct = $previous !== null && $previous != 0.0
                ? round(((float) $newPrice - $previous) / $previous * 100, 2)
                : null;

            DB::table('price_changes')->where('id', $row->id)->update([
                'new_price' => $newPrice,
                'change_pct' => $changePct,
            ]);
        }
    }

    private function computeDiscount(int|float|null $price, int|float|null $oldPrice): ?float
    {
        if ($price === null || $oldPrice === null || $oldPrice <= 0 || $oldPrice <= $price) {
            return null;
        }

        return round((((float) $oldPrice - (float) $price) / (float) $oldPrice) * 100, 2);
    }
}
