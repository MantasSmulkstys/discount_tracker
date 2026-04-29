<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A retail chain whose offers we track (e.g. Maxima, Lidl, Rimi).
 *
 * `hasMany(OfferSnapshot)` is the relation used by the public product listing;
 * `hasMany(RawOffer)` is retained for scraper/admin tooling only.
 */
class Store extends Model
{
    protected $table = 'stores';

    protected $fillable = ['name'];

    /**
     * @return HasMany<OfferSnapshot, self>
     */
    public function offerSnapshots(): HasMany
    {
        return $this->hasMany(OfferSnapshot::class, 'store_id');
    }

    /**
     * Raw scraped rows for this store. Used for debug/admin views only — the
     * public listing should consume `offerSnapshots` instead.
     *
     * @return HasMany<RawOffer, self>
     */
    public function rawOffers(): HasMany
    {
        return $this->hasMany(RawOffer::class, 'store_id');
    }
}
