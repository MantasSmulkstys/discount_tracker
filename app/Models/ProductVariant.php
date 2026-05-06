<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $table = 'product_variants';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'size_value' => 'decimal:3',
        'package_count' => 'decimal:3',
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

    /**
     * @return HasMany<CurrentOffer, self>
     */
    public function currentOffers(): HasMany
    {
        return $this->hasMany(CurrentOffer::class, 'product_variant_id');
    }

    /**
     * @return HasMany<PriceChange, self>
     */
    public function priceChanges(): HasMany
    {
        return $this->hasMany(PriceChange::class, 'product_variant_id');
    }
}
