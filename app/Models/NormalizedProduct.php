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

/**
 * A stable product identity shared across stores and scrape batches.
 *
 * One `NormalizedProduct` may have multiple {@see ProductVariant}s (e.g. 500 g
 * vs. 1 kg pack) and each variant has many {@see OfferSnapshot}s, one per
 * store + week.
 */
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
        return $query->where(function (Builder $q): void {
            $q->where('ai_confidence', '<', 0.7)
                ->orWhere('needs_review', true);
        });
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
