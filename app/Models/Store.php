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
     * Extensions checked, in priority order, when resolving a store logo on disk.
     *
     * @var array<int, string>
     */
    private const LOGO_EXTENSIONS = ['png', 'svg', 'webp', 'jpg', 'jpeg'];

    /**
     * Resolve the public URL of the logo that actually exists for a slug.
     *
     * The stored `logo_path` column has historically drifted (NULL, a
     * `/storage/` prefix, or a hardcoded `.png` extension that doesn't match the
     * real file — e.g. `iki.jpg`). Resolving against the files in
     * `public/store-logos/` is self-healing and immune to that drift.
     */
    public static function resolveLogoPath(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        foreach (self::LOGO_EXTENSIONS as $extension) {
            if (is_file(public_path("store-logos/{$slug}.{$extension}"))) {
                return "/store-logos/{$slug}.{$extension}";
            }
        }

        return null;
    }

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
