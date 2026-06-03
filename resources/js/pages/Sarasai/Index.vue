<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Check,
    ListChecks,
    Loader2,
    Pencil,
    Plus,
    RefreshCw,
    ShoppingCart,
    Trash2,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import StoreLogo from '@/components/kainu/StoreLogo.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useKainuCart } from '@/composables/useKainuCart';
import KainuLayout from '@/layouts/KainuLayout.vue';
import { formatPrice } from '@/lib/kainu';
import savedCarts from '@/routes/saved-carts';
import type { KainuProduct } from '@/types/kainu';

defineOptions({ layout: KainuLayout });

type Offer = {
    snapshotId: number;
    normalizedProductId: number;
    variantId: number;
    name: string;
    storeSlug: string;
    storeName: string;
    price: number;
    oldPrice: number | null;
    unit: string | null;
    image: string | null;
};

type SavedCartItem = {
    id: number;
    familyId: number | null;
    normalizedProductId: number | null;
    variantId: number | null;
    quantity: number;
    snapshotName: string;
    snapshotStoreSlug: string | null;
    snapshotPrice: number | null;
    available: boolean;
    current: Offer | null;
    suggestions: Offer[];
};

type SavedCartCard = {
    id: number;
    name: string;
    itemCount: number;
    createdAt: string | null;
    updatedAt: string | null;
    estimatedTotal: number;
    items: SavedCartItem[];
};

defineProps<{
    carts: SavedCartCard[];
}>();

const { addToCart, openDrawer, clear: clearCart, totalCount } = useKainuCart();

const restoringId = ref<number | null>(null);
const editingId = ref<number | null>(null);
const editingName = ref<string>('');
const deletingId = ref<number | null>(null);

// Items whose discount has expired since the list was saved: they are left out
// of the restored cart (no live price) and surfaced here with same-family
// substitutes the user can accept or decline, instead of silently shopping a
// deal that no longer exists.
const skippedItems = ref<SavedCartItem[]>([]);
const skippedDialogOpen = ref(false);

// Suggestions the user dismissed (keyed `${itemId}:${snapshotId}`) and items
// already resolved by accepting a substitute — both hidden from the dialog.
const declinedSuggestions = ref<Set<string>>(new Set());
const resolvedItemIds = ref<Set<number>>(new Set());

const suggestionKey = (itemId: number, offer: Offer): string =>
    `${itemId}:${offer.snapshotId}`;

const visibleSuggestions = (item: SavedCartItem): Offer[] =>
    item.suggestions.filter(
        (s) => !declinedSuggestions.value.has(suggestionKey(item.id, s)),
    );

const pendingSkipped = computed(() =>
    skippedItems.value.filter((i) => !resolvedItemIds.value.has(i.id)),
);

const toCartProduct = (offer: Offer): KainuProduct => ({
    id: offer.snapshotId,
    normalizedProductId: offer.normalizedProductId,
    name: offer.name,
    normalizedName: null,
    brand: null,
    category: 'all',
    unit: offer.unit,
    emoji: '',
    bestPrice: offer.price,
    oldPrice: offer.oldPrice,
    discount: null,
    condition: null,
    bestStore: offer.storeSlug,
    bestStoreName: offer.storeName,
    image: offer.image,
    url: null,
    validDates: null,
    validFrom: null,
    validTo: null,
    loyaltyCard: false,
    scrapedAt: null,
});

const acceptSuggestion = (item: SavedCartItem, offer: Offer): void => {
    addToCart(toCartProduct(offer), item.quantity, offer.storeSlug);
    resolvedItemIds.value = new Set(resolvedItemIds.value).add(item.id);
};

const declineSuggestion = (item: SavedCartItem, offer: Offer): void => {
    const next = new Set(declinedSuggestions.value);
    next.add(suggestionKey(item.id, offer));
    declinedSuggestions.value = next;
};

const formatDate = (iso: string | null): string => {
    if (!iso) {
        return '';
    }

    const d = new Date(iso);

    return d.toLocaleDateString('lt-LT', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};

const handleRestore = async (cart: SavedCartCard) => {
    restoringId.value = cart.id;

    try {
        const response = await fetch(
            savedCarts.restore({ savedCart: cart.id }).url,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content ?? '',
                },
                credentials: 'same-origin',
            },
        );

        if (!response.ok) {
            throw new Error('Nepavyko atkurti sąrašo');
        }

        const payload = (await response.json()) as {
            items: SavedCartItem[];
        };

        clearCart();
        declinedSuggestions.value = new Set();
        resolvedItemIds.value = new Set();

        // Only items with a live offer are added — a stale snapshot price must
        // never masquerade as a current one. Expired items are collected so we
        // can tell the user and offer them substitutes.
        const skipped: SavedCartItem[] = [];

        for (const item of payload.items) {
            if (!item.available || !item.current) {
                skipped.push(item);

                continue;
            }

            addToCart(
                toCartProduct(item.current),
                item.quantity,
                item.current.storeSlug,
            );
        }

        const addedCount = payload.items.length - skipped.length;
        skippedItems.value = skipped;

        if (skipped.length > 0) {
            // Let the user deal with the expired items first; they can open the
            // cart from the dialog once they've had a look.
            skippedDialogOpen.value = true;
        } else if (addedCount > 0) {
            openDrawer();
        }
    } finally {
        restoringId.value = null;
    }
};

const viewRestoredCart = (): void => {
    skippedDialogOpen.value = false;
    openDrawer();
};

const startEdit = (cart: SavedCartCard) => {
    editingId.value = cart.id;
    editingName.value = cart.name;
};

const cancelEdit = () => {
    editingId.value = null;
    editingName.value = '';
};

const submitEdit = (cart: SavedCartCard) => {
    const name = editingName.value.trim();

    if (name === '') {
        return;
    }

    router.patch(
        savedCarts.update({ savedCart: cart.id }).url,
        { name },
        {
            preserveScroll: true,
            onSuccess: () => {
                editingId.value = null;
                editingName.value = '';
            },
        },
    );
};

const confirmDelete = (cart: SavedCartCard) => {
    deletingId.value = cart.id;
};

const cancelDelete = () => {
    deletingId.value = null;
};

const doDelete = (cart: SavedCartCard) => {
    router.delete(savedCarts.destroy({ savedCart: cart.id }).url, {
        preserveScroll: true,
        onFinish: () => {
            deletingId.value = null;
        },
    });
};
</script>

<template>
    <Head title="Mano sąrašai · Kainų seklys" />

    <section class="pt-8 pb-2">
        <h1 class="m-0 text-[28px] font-bold tracking-tight text-stone-900">
            Mano sąrašai
        </h1>
        <p class="mt-2 mb-0 max-w-[640px] text-[14px] text-stone-600">
            Išsaugoti pirkinių sąrašai. Spauskite „Atkurti“ — sąrašas bus
            įkeltas į jūsų krepšelį.
        </p>
    </section>

    <section v-if="carts.length === 0" class="mt-6">
        <div
            class="rounded-[12px] border border-dashed border-kainu-border bg-white p-10 text-center"
        >
            <ListChecks
                :size="32"
                :stroke-width="1.5"
                class="mx-auto mb-3 text-stone-400"
            />
            <p class="m-0 text-[14px] font-medium text-stone-700">
                Dar neturite jokių išsaugotų sąrašų
            </p>
            <p
                class="mx-auto mt-1 mb-0 max-w-[420px] text-[12px] text-stone-500"
            >
                Pridėkite prekių į krepšelį ir spauskite „Išsaugoti sąrašą“ —
                galėsite jį atkurti vėliau, o kainos bus perskaičiuotos pagal
                naujausius pasiūlymus.
            </p>
        </div>
    </section>

    <section v-else class="mt-6 flex flex-col gap-4">
        <article
            v-for="cart in carts"
            :key="cart.id"
            class="overflow-hidden rounded-[16px] border border-kainu-border bg-white"
        >
            <header
                class="flex flex-wrap items-center gap-3 border-b border-kainu-border bg-kainu-surface px-5 py-4"
            >
                <div class="min-w-0 flex-1">
                    <div v-if="editingId === cart.id" class="flex gap-2">
                        <input
                            v-model="editingName"
                            type="text"
                            maxlength="120"
                            class="min-w-0 flex-1 rounded-[8px] border border-kainu-border bg-white px-3 py-1.5 text-[14px] text-stone-900 focus:border-stone-300 focus:outline-none"
                            @keyup.enter="submitEdit(cart)"
                            @keyup.escape="cancelEdit"
                        />
                        <button
                            type="button"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-[8px] bg-brand-700 text-white hover:bg-brand-800"
                            aria-label="Išsaugoti"
                            @click="submitEdit(cart)"
                        >
                            <Check :size="14" :stroke-width="2" />
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-[8px] border border-kainu-border bg-white text-stone-600 hover:bg-stone-50"
                            aria-label="Atšaukti"
                            @click="cancelEdit"
                        >
                            <X :size="14" :stroke-width="2" />
                        </button>
                    </div>
                    <div v-else>
                        <h2
                            class="m-0 text-[16px] font-semibold tracking-tight text-stone-900"
                        >
                            {{ cart.name }}
                        </h2>
                        <p class="mt-0.5 text-[12px] text-stone-500">
                            {{ cart.itemCount }} prekės (-ių) ·
                            {{ formatDate(cart.createdAt) }}
                        </p>
                    </div>
                </div>

                <div class="text-right">
                    <div class="text-[11px] text-stone-500">
                        Pagal dabartines kainas
                    </div>
                    <div
                        class="text-[18px] font-bold tracking-tight text-stone-900 tabular-nums"
                    >
                        {{ formatPrice(cart.estimatedTotal) }}
                    </div>
                </div>

                <div class="flex w-full items-center gap-2 sm:w-auto">
                    <button
                        type="button"
                        :disabled="restoringId === cart.id"
                        class="inline-flex h-9 items-center gap-1.5 rounded-[10px] bg-brand-700 px-3 text-[12px] font-semibold text-white transition-colors hover:bg-brand-800 disabled:cursor-wait disabled:opacity-70"
                        @click="handleRestore(cart)"
                    >
                        <Loader2
                            v-if="restoringId === cart.id"
                            :size="14"
                            :stroke-width="2"
                            class="animate-spin"
                        />
                        <RefreshCw v-else :size="14" :stroke-width="2" />
                        Atkurti
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-[10px] border border-kainu-border bg-white text-stone-600 transition-colors hover:bg-kainu-surface"
                        aria-label="Pervadinti"
                        title="Pervadinti"
                        @click="startEdit(cart)"
                    >
                        <Pencil :size="14" :stroke-width="2" />
                    </button>
                    <button
                        v-if="deletingId !== cart.id"
                        type="button"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-[10px] border border-transparent bg-white text-red-600 transition-colors hover:border-red-100 hover:bg-red-50"
                        aria-label="Ištrinti"
                        title="Ištrinti"
                        @click="confirmDelete(cart)"
                    >
                        <Trash2 :size="14" :stroke-width="2" />
                    </button>
                    <template v-else>
                        <button
                            type="button"
                            class="inline-flex h-9 items-center gap-1 rounded-[10px] bg-red-600 px-3 text-[12px] font-semibold text-white hover:bg-red-700"
                            @click="doDelete(cart)"
                        >
                            <Check :size="14" :stroke-width="2" />
                            Ištrinti
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-9 items-center gap-1 rounded-[10px] border border-kainu-border bg-white px-3 text-[12px] font-medium text-stone-700 hover:bg-stone-50"
                            @click="cancelDelete"
                        >
                            <X :size="14" :stroke-width="2" />
                            Atšaukti
                        </button>
                    </template>
                </div>
            </header>

            <ul>
                <li
                    v-for="item in cart.items"
                    :key="item.id"
                    class="flex flex-wrap items-center gap-3 border-b border-kainu-border px-5 py-3 last:border-0"
                >
                    <div class="min-w-0 flex-1">
                        <div class="text-[14px] font-medium text-stone-900">
                            <span>{{
                                item.current?.name ?? item.snapshotName
                            }}</span>
                            <span
                                v-if="!item.available"
                                class="ml-2 inline-flex items-center rounded-[6px] bg-stone-100 px-2 py-0.5 text-[10px] font-semibold tracking-[0.04em] text-stone-500 uppercase"
                            >
                                Prekė nebepasiekiama
                            </span>
                        </div>
                        <div
                            v-if="item.current?.unit"
                            class="text-[12px] text-stone-500"
                        >
                            {{ item.current.unit }}
                        </div>
                    </div>

                    <div
                        v-if="item.available && item.current"
                        class="flex items-center gap-2"
                    >
                        <StoreLogo
                            :store-id="item.current.storeSlug"
                            :size="20"
                            :width="72"
                        />
                    </div>

                    <div
                        class="min-w-[60px] text-right text-[13px] font-medium text-stone-700"
                    >
                        × {{ item.quantity }}
                    </div>

                    <div
                        class="min-w-[80px] text-right text-[14px] font-bold text-stone-900 tabular-nums"
                    >
                        <template v-if="item.current">
                            {{
                                formatPrice(item.current.price * item.quantity)
                            }}
                        </template>
                        <template v-else-if="item.snapshotPrice !== null">
                            <span class="text-stone-400">
                                {{
                                    formatPrice(
                                        item.snapshotPrice * item.quantity,
                                    )
                                }}
                            </span>
                        </template>
                        <template v-else>
                            <span class="text-stone-400">—</span>
                        </template>
                    </div>
                </li>
            </ul>
        </article>
    </section>

    <div
        v-if="restoringId !== null"
        class="fixed bottom-6 left-1/2 z-[60] -translate-x-1/2 rounded-full bg-stone-900 px-4 py-2 text-[12px] font-medium text-white shadow-lg"
    >
        <span class="flex items-center gap-2">
            <ShoppingCart :size="14" :stroke-width="2" />
            Įkeliamas krepšelis…
        </span>
    </div>

    <Dialog
        :open="skippedDialogOpen"
        @update:open="(v) => (skippedDialogOpen = v)"
    >
        <DialogContent class="max-w-lg">
            <DialogHeader>
                <DialogTitle>Kai kurios prekės nebegalioja</DialogTitle>
                <p class="text-sm text-slate-500">
                    Šių prekių akcijos pasibaigė, todėl jos nebuvo įdėtos į
                    krepšelį. Pasirinkite pakaitalą arba jį atmeskite.
                </p>
            </DialogHeader>

            <ul
                v-if="pendingSkipped.length > 0"
                class="max-h-80 space-y-3 overflow-y-auto py-1"
            >
                <li
                    v-for="item in pendingSkipped"
                    :key="item.id"
                    class="rounded-xl border border-stone-100 p-3"
                >
                    <div
                        class="mb-2 min-w-0 truncate text-sm font-medium text-stone-800"
                    >
                        {{ item.snapshotName }}
                        <span class="text-stone-400"
                            >× {{ item.quantity }}</span
                        >
                    </div>

                    <div
                        v-if="visibleSuggestions(item).length > 0"
                        class="space-y-1.5"
                    >
                        <div
                            v-for="offer in visibleSuggestions(item)"
                            :key="offer.snapshotId"
                            class="flex items-center gap-2.5 rounded-lg bg-stone-50 px-2.5 py-1.5"
                        >
                            <img
                                v-if="offer.image"
                                :src="offer.image"
                                :alt="offer.name"
                                class="h-9 w-9 shrink-0 rounded-md object-contain"
                            />
                            <div class="min-w-0 flex-1">
                                <div
                                    class="truncate text-xs font-medium text-stone-800"
                                >
                                    {{ offer.name }}
                                </div>
                                <div
                                    class="mt-0.5 flex items-center gap-1.5 text-[11px] text-stone-500"
                                >
                                    <StoreLogo
                                        :store-id="offer.storeSlug"
                                        :size="14"
                                        :width="44"
                                    />
                                    <span v-if="offer.unit"
                                        >· {{ offer.unit }}</span
                                    >
                                </div>
                            </div>
                            <span
                                class="shrink-0 text-xs font-bold text-stone-900 tabular-nums"
                            >
                                {{ formatPrice(offer.price) }}
                            </span>
                            <button
                                type="button"
                                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-brand-700 text-white transition-colors hover:bg-brand-800"
                                aria-label="Pridėti į krepšelį"
                                title="Pridėti į krepšelį"
                                @click="acceptSuggestion(item, offer)"
                            >
                                <Plus :size="14" :stroke-width="2" />
                            </button>
                            <button
                                type="button"
                                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-stone-200 bg-white text-stone-500 transition-colors hover:bg-stone-100"
                                aria-label="Atmesti"
                                title="Atmesti"
                                @click="declineSuggestion(item, offer)"
                            >
                                <X :size="14" :stroke-width="2" />
                            </button>
                        </div>
                    </div>
                    <p v-else class="text-xs text-stone-400">
                        Pakaitalų nerasta
                    </p>
                </li>
            </ul>
            <p v-else class="py-4 text-center text-sm text-stone-500">
                Visos nebegaliojančios prekės sutvarkytos.
            </p>

            <DialogFooter>
                <Button variant="outline" @click="skippedDialogOpen = false">
                    Uždaryti
                </Button>
                <Button v-if="totalCount > 0" @click="viewRestoredCart">
                    <ShoppingCart :size="15" :stroke-width="2" />
                    Peržiūrėti krepšelį
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
