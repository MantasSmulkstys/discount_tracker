<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * A stable product identity shared across stores and scrape batches.
 *
 * One `NormalizedProduct` may have multiple {@see ProductVariant}s (e.g. 500 g
 * vs. 1 kg pack) and each variant has many {@see OfferSnapshot}s, one per
 * store + scrape.
 */
class NormalizedProduct extends Model
{
    protected $table = 'normalized_products';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'needs_review' => 'boolean',
        'ai_confidence' => 'decimal:4',
    ];

    /**
     * @return HasMany<ProductVariant, self>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'normalized_product_id');
    }

    /**
     * All offer snapshots for any of this product's variants. Use this for
     * cross-store / cross-variant price comparisons.
     *
     * @return HasManyThrough<OfferSnapshot, ProductVariant, self>
     */
    public function offerSnapshots(): HasManyThrough
    {
        return $this->hasManyThrough(
            OfferSnapshot::class,
            ProductVariant::class,
            'normalized_product_id',
            'product_variant_id',
            'id',
            'id',
        );
    }
}
