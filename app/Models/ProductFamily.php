<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductFamily extends Model
{
    protected $table = 'product_families';

    protected $guarded = [];

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
     * @return HasMany<NormalizedProduct, self>
     */
    public function normalizedProducts(): HasMany
    {
        return $this->hasMany(NormalizedProduct::class, 'family_id');
    }
}
