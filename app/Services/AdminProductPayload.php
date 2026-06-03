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
        $product->loadMissing(['category', 'subcategory', 'family']);
        if ($product->reports_count === null) {
            $product->loadCount('reports');
        }

        $offers = $this->loadOffers($product->id);
        $prices = collect($offers)->pluck('price')->filter(fn ($p) => $p !== null)->values();

        $rawName = collect($offers)
            ->sortByDesc('lastSeenAt')
            ->pluck('rawName')
            ->first(fn ($name): bool => filled($name)) ?? $product->canonical_name;

        return [
            'id' => $product->id,
            'canonicalName' => $product->canonical_name,
            'rawName' => $rawName,
            'brand' => $product->brand,
            'categoryId' => $product->category_id,
            'categoryName' => $product->category?->name_lt,
            'subcategoryId' => $product->subcategory_id,
            'subcategoryName' => $product->subcategory?->name_lt,
            'familyId' => $product->family_id !== null ? (int) $product->family_id : null,
            'familyName' => $product->family?->family_name,
            'aiConfidence' => $product->ai_confidence !== null ? (float) $product->ai_confidence : null,
            'needsReview' => (bool) $product->needs_review,
            'isHidden' => (bool) $product->is_hidden,
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
            ->leftJoin('offer_snapshots', 'offer_snapshots.id', '=', 'current_offers.last_snapshot_id')
            ->leftJoin('raw_offers', 'raw_offers.id', '=', 'offer_snapshots.raw_offer_id')
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
                'raw_offers.name as raw_name',
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
                'rawName' => filled($r->raw_name ?? null) ? (string) $r->raw_name : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Full per-snapshot price history for every variant of a product.
     *
     * @return array<int, array<string, mixed>>
     */
    public function historyForProduct(NormalizedProduct $product): array
    {
        $rows = DB::table('offer_snapshots')
            ->join('product_variants', 'product_variants.id', '=', 'offer_snapshots.product_variant_id')
            ->join('normalized_products', 'normalized_products.id', '=', 'product_variants.normalized_product_id')
            ->join('stores', 'stores.id', '=', 'offer_snapshots.store_id')
            ->leftJoin('raw_offers', 'raw_offers.id', '=', 'offer_snapshots.raw_offer_id')
            ->leftJoin('current_offers', function ($join): void {
                $join->on('current_offers.store_id', '=', 'offer_snapshots.store_id')
                    ->on('current_offers.product_variant_id', '=', 'offer_snapshots.product_variant_id');
            })
            ->where('product_variants.normalized_product_id', $product->id)
            ->orderByDesc('offer_snapshots.week_starting')
            ->orderBy('stores.name')
            ->select([
                'offer_snapshots.id as snapshot_id',
                'offer_snapshots.store_id',
                'offer_snapshots.product_variant_id as variant_id',
                'offer_snapshots.week_starting',
                'offer_snapshots.price',
                'offer_snapshots.old_price',
                'offer_snapshots.discount_percent',
                'offer_snapshots.condition',
                'offer_snapshots.is_promotion',
                'offer_snapshots.loyalty_card',
                'offer_snapshots.unit_price_value',
                'offer_snapshots.unit_price_unit',
                'offer_snapshots.valid_from',
                'offer_snapshots.valid_to',
                'offer_snapshots.scraped_at',
                'stores.name as store_name',
                'stores.slug as store_slug',
                'product_variants.variant_name',
                'product_variants.size_value',
                'product_variants.size_unit',
                'raw_offers.name as raw_name',
                DB::raw('COALESCE(raw_offers.image, normalized_products.image_url) as image_url'),
                DB::raw('CASE WHEN current_offers.last_snapshot_id = offer_snapshots.id THEN 1 ELSE 0 END as is_current'),
            ])
            ->get();

        return $rows
            ->map(fn (object $r): array => [
                'snapshotId' => (int) $r->snapshot_id,
                'storeId' => (int) $r->store_id,
                'variantId' => (int) $r->variant_id,
                'storeName' => Str::title((string) $r->store_name),
                'storeSlug' => (string) $r->store_slug,
                'variantLabel' => $this->variantLabel($r),
                'weekStarting' => $r->week_starting,
                'price' => $r->price !== null ? (float) $r->price : null,
                'oldPrice' => $r->old_price !== null ? (float) $r->old_price : null,
                'discount' => $r->discount_percent !== null ? (float) $r->discount_percent : null,
                'condition' => $r->condition,
                'isPromotion' => (bool) $r->is_promotion,
                'loyaltyCard' => (bool) $r->loyalty_card,
                'unitPrice' => $r->unit_price_value !== null ? (float) $r->unit_price_value : null,
                'unitPriceUnit' => filled($r->unit_price_unit ?? null) ? (string) $r->unit_price_unit : null,
                'validFrom' => $r->valid_from,
                'validTo' => $r->valid_to,
                'scrapedAt' => $r->scraped_at,
                'imageUrl' => filled($r->image_url ?? null) ? (string) $r->image_url : null,
                'rawName' => filled($r->raw_name ?? null) ? (string) $r->raw_name : null,
                'isCurrent' => (bool) $r->is_current,
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
