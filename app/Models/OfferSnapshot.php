<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Facades\DB;

/**
 * A store-specific price observation for a product variant at a point in time.
 *
 * The public product listing reads from this table (joined with
 * `product_variants`, `normalized_products`, and `stores`) rather than from
 * `raw_offers`, so the user sees one row per (normalized product, store) and
 * never sees duplicate scrape batches.
 *
 * Note: the user-facing shorthand is "`OfferSnapshot` belongs to
 * `NormalizedProduct`"; under the hood the link goes through `product_variants`.
 * The {@see normalizedProduct()} relation hides that hop.
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
        'unit_price_value' => 'decimal:4',
        'valid_from' => 'date',
        'valid_to' => 'date',
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
     * Convenience "through" accessor — snapshot → variant → normalized product.
     * Presented as belongsTo in the domain model even though schema uses an
     * intermediary `product_variants` table.
     *
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
     * Limit a query to only the latest snapshot per (normalized product, store)
     * pair. This is what the public listing uses so the same product never
     * shows up twice for the same store across scrape batches.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLatestPerProductStore(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        $query->whereIn("$table.id", self::latestIdsPerProductStoreSubquery());

        return $query;
    }

    /**
     * A portable (PostgreSQL + SQLite) **subquery builder** that selects the
     * maximum `offer_snapshots.id` for every (normalized_product_id, store_id)
     * pair. Because IDs are monotonically assigned, MAX(id) ≈ latest snapshot.
     *
     * Use with `whereIn('offer_snapshots.id', OfferSnapshot::latestIdsPerProductStoreSubquery())`.
     */
    public static function latestIdsPerProductStoreSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('offer_snapshots as os_latest')
            ->join('product_variants as pv_latest', 'pv_latest.id', '=', 'os_latest.product_variant_id')
            ->whereNotNull('os_latest.price')
            ->groupBy('pv_latest.normalized_product_id', 'os_latest.store_id')
            ->selectRaw('MAX(os_latest.id)');
    }
}
