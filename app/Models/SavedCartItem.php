<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedCartItem extends Model
{
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
        'snapshot_price_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<SavedCart, self>
     */
    public function savedCart(): BelongsTo
    {
        return $this->belongsTo(SavedCart::class);
    }

    /**
     * @return BelongsTo<ProductFamily, self>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }

    /**
     * @return BelongsTo<NormalizedProduct, self>
     */
    public function normalizedProduct(): BelongsTo
    {
        return $this->belongsTo(NormalizedProduct::class);
    }

    /**
     * @return BelongsTo<ProductVariant, self>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
