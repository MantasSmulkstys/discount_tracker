<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row exists in this table only when the price for a (store, variant) pair
 * actually changed week-over-week — not on every weekly re-confirmation. That
 * keeps the table tiny and makes "what dropped this week?" queries trivial.
 */
class PriceChange extends Model
{
    protected $table = 'price_changes';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'previous_price' => 'decimal:2',
        'new_price' => 'decimal:2',
        'change_pct' => 'decimal:2',
        'week_starting' => 'date',
        'changed_at' => 'datetime',
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
     * @return BelongsTo<OfferSnapshot, self>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(OfferSnapshot::class, 'snapshot_id');
    }
}
