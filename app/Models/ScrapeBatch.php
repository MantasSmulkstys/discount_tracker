<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One scraper run for one store in one promo week.
 *
 * `(store_id, week_starting)` is unique — re-running the scraper for the same
 * Monday reuses the existing batch instead of creating a duplicate.
 */
class ScrapeBatch extends Model
{
    protected $table = 'scrape_batches';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'week_starting' => 'date',
        'offers_found' => 'integer',
        'offers_inserted' => 'integer',
        'offers_updated' => 'integer',
        'offers_failed' => 'integer',
    ];

    /**
     * @return BelongsTo<Store, self>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return HasMany<RawOffer, self>
     */
    public function rawOffers(): HasMany
    {
        return $this->hasMany(RawOffer::class, 'scrape_batch_id');
    }

    /**
     * @return HasMany<OfferSnapshot, self>
     */
    public function offerSnapshots(): HasMany
    {
        return $this->hasMany(OfferSnapshot::class, 'scrape_batch_id');
    }
}
