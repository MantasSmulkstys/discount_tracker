<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single row scraped from a store's promo feed.
 *
 * This table is the scraper's source-of-truth: it may contain duplicates across
 * scrape batches and should only be consumed by the scraper, the normalizer,
 * and the admin dashboard. The **public product listing** consumes
 * {@see OfferSnapshot} instead — always prefer `OfferSnapshot` for user-facing
 * queries.
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
        'discount' => 'decimal:2',
        'loyalty_card' => 'boolean',
        'scraped_at' => 'datetime',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'unit_price_value' => 'decimal:4',
        'normalized_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Store, self>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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
