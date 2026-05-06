<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrentOffer extends Model
{
    protected $table = 'current_offers';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'unit_price_value' => 'decimal:3',
        'is_promotion' => 'boolean',
        'loyalty_card' => 'boolean',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'week_starting' => 'date',
        'last_seen_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Store, self>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return BelongsTo<ProductVariant, self>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<OfferSnapshot, self>
     */
    public function lastSnapshot(): BelongsTo
    {
        return $this->belongsTo(OfferSnapshot::class, 'last_snapshot_id');
    }
}
