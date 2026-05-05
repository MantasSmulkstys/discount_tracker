<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * An immutable price observation for one (variant, store, week) tuple.
 *
 * This is the price-history append-only table — one row per weekly scrape per
 * store per variant. For "what's the price right now?" use {@see CurrentOffer}
 * instead; for "did the price change?" use {@see PriceChange}.
 */
class OfferSnapshot extends Model
{
    protected $table = 'offer_snapshots';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'unit_price_value' => 'decimal:3',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'week_starting' => 'date',
        'scraped_at' => 'datetime',
        'loyalty_card' => 'boolean',
        'is_promotion' => 'boolean',
        'created_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<ProductVariant, self>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<Store, self>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return BelongsTo<RawOffer, self>
     */
    public function rawOffer(): BelongsTo
    {
        return $this->belongsTo(RawOffer::class, 'raw_offer_id');
    }

    /**
     * @return BelongsTo<ScrapeBatch, self>
     */
    public function scrapeBatch(): BelongsTo
    {
        return $this->belongsTo(ScrapeBatch::class, 'scrape_batch_id');
    }

    /**
     * @return HasOneThrough<NormalizedProduct, ProductVariant, self>
     */
    public function normalizedProduct(): HasOneThrough
    {
        return $this->hasOneThrough(
            NormalizedProduct::class,
            ProductVariant::class,
            'id',
            'id',
            'product_variant_id',
            'normalized_product_id',
        );
    }

    /**
     * Subquery returning the MAX(offer_snapshots.id) per
     * (normalized_product_id, store_id). Kept for legacy controllers — new
     * code should read directly from `current_offers` instead, which is
     * O(rows-of-result) instead of O(history).
     */
    public static function latestIdsPerProductStoreSubquery(): Builder
    {
        // Priceless promos (discount-only / "1+1" cards) are valid offers and
        // must appear in the listing, so we don't filter on `price IS NOT NULL`.
        // The presence of a row already implies the snapshot is meaningful —
        // see `_insert_offer_snapshot` in the Python normalizer.
        return DB::table('offer_snapshots as os_latest')
            ->join('product_variants as pv_latest', 'pv_latest.id', '=', 'os_latest.product_variant_id')
            ->groupBy('pv_latest.normalized_product_id', 'os_latest.store_id')
            ->selectRaw('MAX(os_latest.id)');
    }
}
