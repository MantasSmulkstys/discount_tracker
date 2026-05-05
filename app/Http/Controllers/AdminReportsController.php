<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NormalizedProduct;
use App\Models\OfferSnapshot;
use App\Models\ProductVariant;
use App\Services\KainuCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportsController extends Controller
{
    public function __construct(private readonly KainuCatalog $catalog) {}

    public function index(): Response
    {
        $userReported = NormalizedProduct::withCount('reports')
            ->has('reports', '>=', 5)
            ->with(['category', 'subcategory'])
            ->orderByDesc('reports_count')
            ->get()
            ->map(fn (NormalizedProduct $p): array => $this->toAdminRow($p));

        $driver = DB::getDriverName();
        $aiFlagged = NormalizedProduct::flaggedForReview()
            ->withCount('reports')
            ->with(['category', 'subcategory'])
            ->orderByRaw($driver === 'sqlite'
                ? 'CASE WHEN ai_confidence IS NULL THEN 1 ELSE 0 END, ai_confidence ASC'
                : 'ai_confidence ASC NULLS LAST'
            )
            ->get()
            ->map(fn (NormalizedProduct $p): array => $this->toAdminRow($p));

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

    /**
     * @return array<string, mixed>
     */
    private function toAdminRow(NormalizedProduct $product): array
    {
        $offers = $this->loadOffers($product->id);
        $prices = collect($offers)->pluck('price')->filter(fn ($p) => $p !== null)->values();

        return [
            'id' => $product->id,
            'canonicalName' => $product->canonical_name,
            'brand' => $product->brand,
            'categoryId' => $product->category_id,
            'categoryName' => $product->category?->name_lt,
            'subcategoryId' => $product->subcategory_id,
            'subcategoryName' => $product->subcategory?->name_lt,
            'aiConfidence' => $product->ai_confidence !== null ? (float) $product->ai_confidence : null,
            'needsReview' => (bool) $product->needs_review,
            'reportsCount' => (int) ($product->reports_count ?? 0),
            'imageUrl' => $product->image_url,
            'offers' => $offers,
            'bestPrice' => $prices->isNotEmpty() ? (float) $prices->min() : null,
            'storeCount' => count($offers),
            'lastSeenAt' => collect($offers)->pluck('lastSeenAt')->filter()->max(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadOffers(int $normalizedProductId): array
    {
        $rows = DB::table('current_offers')
            ->join('stores', 'stores.id', '=', 'current_offers.store_id')
            ->join('product_variants', 'product_variants.id', '=', 'current_offers.product_variant_id')
            ->where('product_variants.normalized_product_id', $normalizedProductId)
            ->select([
                'current_offers.last_snapshot_id as snapshot_id',
                'current_offers.store_id',
                'current_offers.product_variant_id as variant_id',
                'current_offers.price',
                'current_offers.old_price',
                'current_offers.discount_percent',
                'current_offers.condition',
                'current_offers.last_seen_at',
                'stores.name as store_name',
                'stores.slug as store_slug',
                'product_variants.variant_name',
                'product_variants.size_value',
                'product_variants.size_unit',
            ])
            ->orderBy('stores.name')
            ->get();

        return $rows
            ->filter(fn (object $r): bool => $r->snapshot_id !== null)
            ->map(fn (object $r): array => [
                'snapshotId' => (int) $r->snapshot_id,
                'storeId' => (int) $r->store_id,
                'variantId' => (int) $r->variant_id,
                'storeName' => Str::title((string) $r->store_name),
                'storeSlug' => (string) $r->store_slug,
                'variantLabel' => $this->variantLabel($r),
                'price' => $r->price !== null ? (float) $r->price : null,
                'oldPrice' => $r->old_price !== null ? (float) $r->old_price : null,
                'discount' => $r->discount_percent !== null ? (float) $r->discount_percent : null,
                'condition' => $r->condition,
                'lastSeenAt' => $r->last_seen_at,
            ])
            ->values()
            ->all();
    }

    private function variantLabel(object $row): string
    {
        $name = trim((string) ($row->variant_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        if ($row->size_value !== null && $row->size_unit) {
            return rtrim(rtrim((string) $row->size_value, '0'), '.').' '.$row->size_unit;
        }

        return '–';
    }
}
