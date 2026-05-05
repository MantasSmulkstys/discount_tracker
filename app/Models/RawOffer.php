<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single row scraped from a store's promo feed.
 *
 * This is a landing/audit table — it captures what the scraper saw, untouched.
 * Public reads should never go through here; they go through `current_offers`
 * (latest price per store/variant) or `offer_snapshots` (price history).
 */
class RawOffer extends Model
{
    protected $table = 'raw_offers';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
        'loyalty_card' => 'boolean',
        'scraped_at' => 'datetime',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'unit_price_value' => 'decimal:4',
        'normalized_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    /**
     * @return BelongsTo<Store, self>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<ScrapeBatch, self>
     */
    public function scrapeBatch(): BelongsTo
    {
        return $this->belongsTo(ScrapeBatch::class);
    }

    /**
     * Snapshots derived from this raw row (usually exactly one).
     *
     * @return HasMany<OfferSnapshot, self>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(OfferSnapshot::class, 'raw_offer_id');
    }
}
