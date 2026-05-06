<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory;

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
     * @return HasMany<RawOffer, self>
     */
    public function rawOffers(): HasMany
    {
        return $this->hasMany(RawOffer::class, 'store_id');
    }
}
