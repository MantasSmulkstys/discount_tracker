export type StoreId =
    | 'maxima'
    | 'lidl'
    | 'iki'
    | 'rimi'
    | 'norfa'
    | 'aibe'
    | 'other';

export type StoreMeta = {
    id: StoreId;
    name: string;
    color: string;
    short: string;
    logoPath?: string | null;
};

export const STORE_META: Record<StoreId, StoreMeta> = {
    maxima: { id: 'maxima', name: 'Maxima', color: '#0066B3', short: 'M' },
    lidl: { id: 'lidl', name: 'Lidl', color: '#0050AA', short: 'L' },
    iki: { id: 'iki', name: 'Iki', color: '#E30613', short: 'I' },
    rimi: { id: 'rimi', name: 'Rimi', color: '#B91C1C', short: 'R' },
    norfa: { id: 'norfa', name: 'Norfa', color: '#059669', short: 'N' },
    aibe: { id: 'aibe', name: 'Aibė', color: '#F59E0B', short: 'A' },
    other: { id: 'other', name: 'Kita', color: '#57534e', short: '?' },
};

const STORE_LOGO_PATHS: Partial<Record<StoreId, string>> = {
    aibe: '/store-logos/aibe.png',
    iki: '/store-logos/iki.jpg',
    lidl: '/store-logos/lidl.png',
    maxima: '/store-logos/maxima.png',
    norfa: '/store-logos/norfa.png',
    rimi: '/store-logos/rimi.png',
};

export function resolveStore(value: string | null | undefined): StoreMeta {
    if (!value) {
        return STORE_META.other;
    }

    const normalized = value
        .toLowerCase()
        .trim()
        .replace(/[ėę]/g, 'e')
        .replace(/[ųū]/g, 'u')
        .replace(/[įy]/g, 'i')
        .replace(/[š]/g, 's')
        .replace(/[č]/g, 'c')
        .replace(/[ž]/g, 'z');

    if (normalized in STORE_META) {
        const store = STORE_META[normalized as StoreId];

        return {
            ...store,
            logoPath: STORE_LOGO_PATHS[store.id] ?? null,
        };
    }

    return {
        id: 'other',
        name: value,
        color: '#57534e',
        short: value.charAt(0).toUpperCase() || '?',
        logoPath: null,
    };
}

export type CategoryId = string;

export type CategoryNode = {
    id: number;
    slug: string;
    name: string;
};

export type CategoryTreeNode = CategoryNode & {
    subcategories: CategoryNode[];
};

export function emojiForProduct(nameOrId: string): string {
    const lower = nameOrId.toLowerCase();
    const map: Array<[string, string]> = [
        ['obuol', '🍎'],
        ['banan', '🍌'],
        ['pomidor', '🍅'],
        ['agurk', '🥒'],
        ['mork', '🥕'],
        ['bulv', '🥔'],
        ['salot', '🥬'],
        ['citrin', '🍋'],
        ['apelsin', '🍊'],
        ['avokad', '🥑'],
        ['brašk', '🍓'],
        ['braski', '🍓'],
        ['vynuog', '🍇'],
        ['kopūst', '🥬'],
        ['svogūn', '🧅'],
        ['česnak', '🧄'],
        ['paprik', '🫑'],
        ['brokol', '🥦'],
        ['uogos', '🫐'],
        ['mėlyn', '🫐'],
        ['pien', '🥛'],
        ['jogurt', '🥛'],
        ['sūris', '🧀'],
        ['suris', '🧀'],
        ['sviestas', '🧈'],
        ['varšk', '🥛'],
        ['varsk', '🥛'],
        ['grietin', '🥛'],
        ['kiaušin', '🥚'],
        ['kiausin', '🥚'],
        ['vištie', '🍗'],
        ['vistie', '🍗'],
        ['kiaulie', '🥩'],
        ['jautien', '🥩'],
        ['dešr', '🌭'],
        ['desr', '🌭'],
        ['kumpis', '🍖'],
        ['žuvis', '🐟'],
        ['zuvis', '🐟'],
        ['lašiš', '🐟'],
        ['lasis', '🐟'],
        ['duona', '🍞'],
        ['bandel', '🥐'],
        ['pyrag', '🥧'],
        ['sausain', '🍪'],
        ['makaronai', '🍝'],
        ['ryžiai', '🍚'],
        ['ryziai', '🍚'],
        ['miltai', '🌾'],
        ['cukrus', '🍬'],
        ['aliejus', '🫗'],
        ['kava', '☕'],
        ['arbata', '🍵'],
        ['vanduo', '💧'],
        ['sultys', '🧃'],
        ['alus', '🍺'],
        ['vynas', '🍷'],
        ['pica', '🍕'],
        ['ledai', '🍦'],
        ['koldūn', '🥟'],
        ['koldun', '🥟'],
        ['skalbimo', '🧺'],
        ['muilas', '🧼'],
        ['šampū', '🧴'],
        ['sampu', '🧴'],
    ];

    for (const [kw, emoji] of map) {
        if (lower.includes(kw)) {
            return emoji;
        }
    }

    const fallbacks = ['🛒', '🥫', '🍱', '🧺', '🥡'];
    let hash = 0;

    for (let i = 0; i < nameOrId.length; i++) {
        hash = (hash * 31 + nameOrId.charCodeAt(i)) >>> 0;
    }

    return fallbacks[hash % fallbacks.length];
}

export function pastelTintFor(seed: string | number): string {
    const tints = [
        '#fef9e7',
        '#f0f9f4',
        '#f0f4fb',
        '#fdf2f8',
        '#fff7ed',
        '#f5f3ff',
        '#ecfeff',
        '#fef2f2',
    ];
    const s = String(seed);
    let hash = 0;

    for (let i = 0; i < s.length; i++) {
        hash = (hash * 31 + s.charCodeAt(i)) >>> 0;
    }

    return tints[hash % tints.length];
}

export function formatPrice(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const n = typeof value === 'number' ? value : parseFloat(value);

    if (!Number.isFinite(n)) {
        return '—';
    }

    return n.toFixed(2).replace('.', ',') + ' €';
}

export function discountPct(
    oldPrice: number | null | undefined,
    newPrice: number | null | undefined,
): number {
    if (!oldPrice || !newPrice || oldPrice <= newPrice) {
        return 0;
    }

    return Math.round((1 - newPrice / oldPrice) * 100);
}

export function isAmbiguousDiscountCondition(
    condition: string | null | undefined,
): boolean {
    return /^\d+\s*\+\s*\d+$/.test((condition ?? '').trim());
}

function normalizeCondition(condition: string): string {
    return condition
        .trim()
        .replace(/\s+/g, ' ')
        .toLowerCase()
        .replace(/[ąā]/g, 'a')
        .replace(/[ėęē]/g, 'e')
        .replace(/[įīy]/g, 'i')
        .replace(/[ųūu]/g, 'u')
        .replace(/[š]/g, 's')
        .replace(/[č]/g, 'c')
        .replace(/[ž]/g, 'z');
}

/**
 * Pure marketing labels that carry no purchase information. Stored
 * diacritic-free and lowercased to match normalizeCondition() output.
 */
const MEANINGLESS_CONDITIONS = new Set([
    'tik',
    'super kaina',
    'superkaina',
    'super',
    'akcija',
    'akcijos kaina',
    'kaina',
    'nauja',
    'naujiena',
    'hit',
    'top',
    'pigiau',
    'pasiulymas',
    'pasiulymai',
]);

/**
 * Whether a scraped condition is worth showing. Filters out store marketing
 * noise (e.g. IKI's "TIK", "SUPER KAINA") while keeping real conditions like
 * "1+1" or "Perkant 2 vnt".
 */
export function isMeaningfulCondition(
    condition: string | null | undefined,
): boolean {
    const normalized = normalizeCondition(condition ?? '');

    if (normalized === '') {
        return false;
    }

    return !MEANINGLESS_CONDITIONS.has(normalized);
}
