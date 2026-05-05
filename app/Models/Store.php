<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A retail chain whose offers we track (e.g. Maxima, Lidl, Rimi).
 *
 * Public reads should join through `current_offers` (latest known price per
 * variant) for fast cross-store comparison; `offer_snapshots` is the price
 * history.
 */
class Store extends Model
{
    protected $table = 'stores';

    protected $fillable = ['name', 'slug', 'website_url', 'is_active', 'scrape_cron', 'logo_path'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * @return HasMany<ScrapeBatch, self>
     */
    public function scrapeBatches(): HasMany
    {
        return $this->hasMany(ScrapeBatch::class, 'store_id');
    }

    /**
     * @return HasMany<OfferSnapshot, self>
     */
    public function offerSnapshots(): HasMany
    {
        return $this->hasMany(OfferSnapshot::class, 'store_id');
    }

    /**
     * @return HasMany<CurrentOffer, self>
     */
    public function currentOffers(): HasMany
    {
        return $this->hasMany(CurrentOffer::class, 'store_id');
    }

    /**
     * Raw scraped rows for this store. Audit/debug use only — the public
     * listing should consume `currentOffers` or `offerSnapshots` instead.
     *
     * @return HasMany<RawOffer, self>
     */
    public function rawOffers(): HasMany
    {
        return $this->hasMany(RawOffer::class, 'store_id');
    }
}
