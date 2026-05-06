import { ref, computed, watch } from 'vue';
import type { KainuProduct } from '@/types/kainu';

export type CartItem = {
    id: number;
    qty: number;
    storeId: string;
    product: KainuProduct;
};

const STORAGE_KEY = 'ks_cart_v1';

function loadInitial(): CartItem[] {
    if (typeof window === 'undefined') {
        return [];
    }

    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);

        if (!raw) {
            return [];
        }

        const parsed = JSON.parse(raw);

        if (Array.isArray(parsed)) {
            return parsed as CartItem[];
        }

        return [];
    } catch {
        return [];
    }
}

const items = ref<CartItem[]>(loadInitial());
const isOpen = ref(false);

if (typeof window !== 'undefined') {
    watch(
        items,
        (next) => {
            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
            } catch {}
        },
        { deep: true },
    );
}

const totalCount = computed(() =>
    items.value.reduce((sum, i) => sum + i.qty, 0),
);

const subtotal = computed(() =>
    items.value.reduce((sum, i) => sum + (i.product.bestPrice ?? 0) * i.qty, 0),
);

const originalTotal = computed(() =>
    items.value.reduce((sum, i) => {
        const base = i.product.oldPrice ?? i.product.bestPrice ?? 0;

        return sum + base * i.qty;
    }, 0),
);

const savings = computed(() =>
    Math.max(0, originalTotal.value - subtotal.value),
);

const storeGroups = computed(() => {
    const groups: Record<string, { storeId: string; items: CartItem[] }> = {};

    for (const item of items.value) {
        const sid = item.storeId || 'other';

        if (!groups[sid]) {
            groups[sid] = { storeId: sid, items: [] };
        }

        groups[sid].items.push(item);
    }

    return Object.values(groups);
});

function addToCart(product: KainuProduct, qty = 1, storeId?: string) {
    const sid = storeId ?? product.bestStore ?? 'other';
    const existing = items.value.find(
        (i) => i.id === product.id && i.storeId === sid,
    );

    if (existing) {
        existing.qty += qty;
    } else {
        items.value.push({
            id: product.id,
            qty,
            storeId: sid,
            product,
        });
    }
}

function setQty(id: number, storeId: string, qty: number) {
    const item = items.value.find((i) => i.id === id && i.storeId === storeId);

    if (!item) {
        return;
    }

    if (qty <= 0) {
        items.value = items.value.filter(
            (i) => !(i.id === id && i.storeId === storeId),
        );
    } else {
        item.qty = qty;
    }
}

function increment(id: number, storeId: string) {
    const item = items.value.find((i) => i.id === id && i.storeId === storeId);

    if (item) {
        item.qty += 1;
    }
}

function decrement(id: number, storeId: string) {
    const item = items.value.find((i) => i.id === id && i.storeId === storeId);

    if (!item) {
        return;
    }

    if (item.qty <= 1) {
        items.value = items.value.filter(
            (i) => !(i.id === id && i.storeId === storeId),
        );
    } else {
        item.qty -= 1;
    }
}

function remove(id: number, storeId: string) {
    items.value = items.value.filter(
        (i) => !(i.id === id && i.storeId === storeId),
    );
}

function clear() {
    items.value = [];
}

function openDrawer() {
    isOpen.value = true;
}

function closeDrawer() {
    isOpen.value = false;
}

export function useKainuCart() {
    return {
        items,
        isOpen,
        totalCount,
        subtotal,
        originalTotal,
        savings,
        storeGroups,
        addToCart,
        setQty,
        increment,
        decrement,
        remove,
        clear,
        openDrawer,
        closeDrawer,
    };
}
