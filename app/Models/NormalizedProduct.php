<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NormalizedProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class NormalizedProduct extends Model
{
    /** @use HasFactory<NormalizedProductFactory> */
    use HasFactory;

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
     * @return HasMany<ProductReport, self>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(ProductReport::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFlaggedForReview(Builder $query): Builder
    {
        return $query->where('ai_confidence', '<', 0.7);
    }

    /**
     * @return HasMany<ProductVariant, self>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'normalized_product_id');
    }

    /**
     * @return BelongsTo<Category, self>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * @return BelongsTo<Subcategory, self>
     */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class, 'subcategory_id');
    }

    /**
     * @return BelongsTo<ProductFamily, self>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'family_id');
    }

    /**
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

    /**
     * @return HasManyThrough<CurrentOffer, ProductVariant, self>
     */
    public function currentOffers(): HasManyThrough
    {
        return $this->hasManyThrough(
            CurrentOffer::class,
            ProductVariant::class,
            'normalized_product_id',
            'product_variant_id',
            'id',
            'id',
        );
    }
}
