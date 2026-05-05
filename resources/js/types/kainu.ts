import type { CategoryId } from '@/lib/kainu';

export type KainuProduct = {
    /** `offer_snapshots.id` — the id used in `/products/{id}` URLs. */
    id: number;
    /** `normalized_products.id` — used for reporting. */
    normalizedProductId: number;
    /** `normalized_products.canonical_name` (the human-friendly display name). */
    name: string;
    /** `normalized_products.normalized_name` (lower-cased, dedupe key). */
    normalizedName?: string | null;
    brand: string | null;
    category: CategoryId | string;
    unit: string | null;
    emoji: string;
    bestPrice: number | null;
    oldPrice: number | null;
    /** Effective discount percent (from `discount_percent` or computed from prices). */
    discount?: number | null;
    /** Promo condition text from the store ("1+1", "2+1", coupon notes…). */
    condition?: string | null;
    bestStore: string;
    bestStoreName: string;
    image: string | null;
    url: string | null;
    validDates: string | null;
    /** ISO date (YYYY-MM-DD) of `offer_snapshots.valid_from`. */
    validFrom?: string | null;
    /** ISO date (YYYY-MM-DD) of `offer_snapshots.valid_to`. */
    validTo?: string | null;
    loyaltyCard: boolean;
    scrapedAt: string | null;
    /** Price recorded for this (variant, store) in the previous scrape, if any. */
    previousPrice?: number | null;
    /** `bestPrice - previousPrice` in EUR (negative = price dropped). */
    priceChange?: number | null;
    /** Same delta expressed as a percentage of the previous price. */
    priceChangePct?: number | null;
};

export type KainuStoreOffer = {
    store: string;
    storeName: string;
    price: number | null;
    oldPrice: number | null;
    url: string | null;
};

export type KainuStoreCount = {
    id: string;
    name: string;
    count: number;
};

export type KainuPagination = {
    from: number | null;
    to: number | null;
    total: number;
    currentPage: number;
    lastPage: number;
    hasPages: boolean;
};

export type KainuPageLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type KainuStoreLocation = {
    id: string;
    chain: string;
    name: string;
    address: string;
    city: string;
    lat: number;
    lng: number;
    /** Distance from the user's current position in kilometers. Only set after geolocation resolves. */
    distanceKm?: number | null;
};

export type GoogleMapsConfig = {
    apiKey: string | null;
    mapId: string | null;
};
