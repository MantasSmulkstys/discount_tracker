import type { CategoryId } from '@/lib/kainu';

export type KainuProduct = {
    id: number;
    normalizedProductId: number;
    familyId?: number | null;
    name: string;
    displayName?: string | null;
    canonicalName?: string | null;
    familyName?: string | null;
    normalizedName?: string | null;
    brand: string | null;
    category: CategoryId | string;
    unit: string | null;
    unitPrice?: string | null;
    emoji: string;
    bestPrice: number | null;
    oldPrice: number | null;
    discount?: number | null;
    condition?: string | null;
    bestStore: string;
    bestStoreName: string;
    image: string | null;
    url: string | null;
    validDates: string | null;
    validFrom?: string | null;
    validTo?: string | null;
    loyaltyCard: boolean;
    scrapedAt: string | null;
    previousPrice?: number | null;
    priceChange?: number | null;
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

export type GoogleMapsConfig = {
    apiKey: string | null;
    mapId: string | null;
};
