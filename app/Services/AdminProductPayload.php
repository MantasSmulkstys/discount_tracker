<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NormalizedProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminProductPayload
{
    /**
     * @return array<string, mixed>
     */
    public function forProduct(NormalizedProduct $product): array
    {
        $product->loadMissing(['category', 'subcategory']);
        if ($product->reports_count === null) {
            $product->loadCount('reports');
        }

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
