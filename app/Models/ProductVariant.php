<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A specific sellable variant of a normalized product (e.g. "Milk 1L").
 *
 * Variants are the actual entity referenced by `offer_snapshots.product_variant_id`;
 * we use them as the join hop between `normalized_products` and
 * `offer_snapshots` because two variants of the same product can have
 * different prices at the same store.
 */
class ProductVariant extends Model
{
    protected $table = 'product_variants';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'size_value' => 'decimal:3',
        'net_quantity_value' => 'decimal:3',
        'attributes' => 'array',
    ];

    /**
     * @return BelongsTo<NormalizedProduct, self>
     */
    public function normalizedProduct(): BelongsTo
    {
        return $this->belongsTo(NormalizedProduct::class, 'normalized_product_id');
    }

    /**
     * @return HasMany<OfferSnapshot, self>
     */
    public function offerSnapshots(): HasMany
    {
        return $this->hasMany(OfferSnapshot::class, 'product_variant_id');
    }
}
