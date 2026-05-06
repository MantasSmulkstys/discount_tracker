<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NormalizedProduct;
use App\Models\OfferSnapshot;
use App\Models\ProductVariant;
use App\Services\AdminProductPayload;
use App\Services\KainuCatalog;
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
        $userReported = NormalizedProduct::withCount('reports')
            ->has('reports', '>=', 5)
            ->with(['category', 'subcategory'])
            ->orderByDesc('reports_count')
            ->get()
            ->map(fn (NormalizedProduct $p): array => $this->payload->forProduct($p));

        $driver = DB::getDriverName();
        $aiFlagged = NormalizedProduct::flaggedForReview()
            ->withCount('reports')
            ->with(['category', 'subcategory'])
            ->orderByRaw($driver === 'sqlite'
                ? 'CASE WHEN ai_confidence IS NULL THEN 1 ELSE 0 END, ai_confidence ASC'
                : 'ai_confidence ASC NULLS LAST'
            )
            ->get()
            ->map(fn (NormalizedProduct $p): array => $this->payload->forProduct($p));

        return Inertia::render('admin/Reports', [
            'userReported' => $userReported,
            'aiFlagged' => $aiFlagged,
            'categories' => $this->catalog->loadCategoryTree(),
        ]);
    }

    public function update(Request $request, NormalizedProduct $normalizedProduct): RedirectResponse
    {
        $validated = $request->validate([
            'canonical_name' => ['required', 'string', 'max:500'],
            'brand' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'subcategory_id' => ['nullable', 'integer', Rule::exists('subcategories', 'id')],
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
            }

            $normalizedProduct->reports()->delete();
        });

        return back()->with('success', 'Produktas atnaujintas.');
    }

    private function computeDiscount(int|float|null $price, int|float|null $oldPrice): ?float
    {
        if ($price === null || $oldPrice === null || $oldPrice <= 0 || $oldPrice <= $price) {
            return null;
        }

        return round((((float) $oldPrice - (float) $price) / (float) $oldPrice) * 100, 2);
    }
}
