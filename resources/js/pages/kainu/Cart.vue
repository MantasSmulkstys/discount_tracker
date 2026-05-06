<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Download,
    Minus,
    Plus,
    Receipt,
    Share2,
    ShoppingBasket,
    TrendingDown,
    Trash2,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';
import ProductImage from '@/components/kainu/ProductImage.vue';
import RoutePlanner from '@/components/kainu/RoutePlanner.vue';
import StoreLogo from '@/components/kainu/StoreLogo.vue';
import StoreMap from '@/components/kainu/StoreMap.vue';
import { useKainuCart } from '@/composables/useKainuCart';
import { discountPct, formatPrice } from '@/lib/kainu';
import shoppingLists from '@/routes/shopping-lists';
import type { GoogleMapsConfig } from '@/types/kainu';

const props = defineProps<{
    googleMaps: GoogleMapsConfig;
}>();

type LatLng = { lat: number; lng: number };

const {
    items,
    totalCount,
    subtotal,
    originalTotal,
    savings,
    storeGroups,
    increment,
    decrement,
    remove,
    clear,
} = useKainuCart();

const itemCountLabel = computed(() => {
    const n = totalCount.value;

    if (n === 0) {
        return 'Krepšelis tuščias';
    }

    if (n === 1) {
        return '1 prekė';
    }

    return `${n} prekės (-ių)`;
});

const storesUsed = computed(() =>
    Array.from(new Set(storeGroups.value.map((g) => g.storeId))),
);

const savingsPct = computed(() => {
    if (originalTotal.value <= 0) {
        return 0;
    }

    return Math.max(
        0,
        Math.round((savings.value / originalTotal.value) * 100),
    );
});

const highlightChains = computed<string[]>(() => storesUsed.value);

type RoutePlanStop = {
    chain: string;
    name: string;
    address: string;
    city: string;
    lat: number;
    lng: number;
};

type RoutePlanResult = {
    polyline: string;
    stops: RoutePlanStop[];
};

const routePlan = ref<RoutePlanResult | null>(null);
const routeOrigin = ref<LatLng | null>(null);
const routePolyline = computed<string | null>(() => routePlan.value?.polyline ?? null);
const routeStops = computed<RoutePlanStop[]>(() => routePlan.value?.stops ?? []);
const hasMapsKey = computed<boolean>(() => Boolean(props.googleMaps?.apiKey));
const planningVisible = ref(false);
const planningSectionRef = ref<HTMLElement | null>(null);

const onRoutePlan = (plan: RoutePlanResult | null): void => {
    routePlan.value = plan;
};

const onRouteOrigin = (next: LatLng | null): void => {
    routeOrigin.value = next;
};

const page = usePage();
const isLoggedIn = (): boolean => Boolean(page.props.auth?.user);

const generateShoppingPlan = async (): Promise<void> => {
    if (items.value.length === 0) {
        return;
    }

    if (isLoggedIn()) {
        const payload = {
            items: items.value.map((i) => ({
                normalizedProductId: i.product.normalizedProductId,
                name: i.product.name,
                brand: i.product.brand,
                storeId: i.storeId,
                qty: i.qty,
                unitPrice: i.product.bestPrice,
                unitOldPrice: i.product.oldPrice,
            })),
            subtotal: subtotal.value,
            originalTotal: originalTotal.value,
            savings: savings.value,
        };

        router.post(shoppingLists.store.url(), payload, {
            preserveScroll: true,
            preserveState: true,
        });
    }

    planningVisible.value = true;
    await nextTick();
    planningSectionRef.value?.scrollIntoView({
        behavior: 'smooth',
        block: 'start',
    });
};
</script>

<template>
    <Head title="Krepšelis" />

    <div class="flex flex-col gap-6 pt-6">
        <header class="flex flex-col gap-3">
            <Link
                href="/products"
                class="inline-flex w-fit items-center gap-1.5 text-[12px] font-medium text-stone-500 transition-colors hover:text-stone-900"
            >
                <ArrowLeft :size="14" :stroke-width="2" />
                Tęsti apsipirkimą
            </Link>
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <h1
                        class="text-[28px] font-bold tracking-tight text-stone-900 sm:text-[32px]"
                    >
                        Krepšelis
                    </h1>
                    <p class="mt-1 text-[13px] text-stone-500">
                        {{ itemCountLabel }}
                        <template v-if="storesUsed.length > 0">
                            · iš {{ storesUsed.length }}
                            {{
                                storesUsed.length === 1
                                    ? 'parduotuvės'
                                    : 'parduotuvių'
                            }}
                        </template>
                    </p>
                </div>
                <div
                    v-if="items.length > 0"
                    class="flex flex-wrap items-center gap-2"
                >
                    <button
                        type="button"
                        class="inline-flex h-9 items-center gap-1.5 rounded-[10px] border border-kainu-border bg-white px-3 text-[12px] font-medium text-stone-700 transition-colors hover:bg-kainu-surface"
                    >
                        <Download :size="14" :stroke-width="2" />
                        Eksportuoti
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-9 items-center gap-1.5 rounded-[10px] border border-kainu-border bg-white px-3 text-[12px] font-medium text-stone-700 transition-colors hover:bg-kainu-surface"
                    >
                        <Share2 :size="14" :stroke-width="2" />
                        Dalintis
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-9 items-center gap-1.5 rounded-[10px] border border-transparent bg-white px-3 text-[12px] font-medium text-red-600 transition-colors hover:border-red-100 hover:bg-red-50"
                        @click="clear"
                    >
                        <Trash2 :size="14" :stroke-width="2" />
                        Išvalyti krepšelį
                    </button>
                </div>
            </div>
        </header>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
            <div class="flex flex-col gap-6">
                <div
                    v-if="items.length === 0"
                    class="flex flex-col items-center gap-4 rounded-[18px] border border-dashed border-kainu-border bg-white px-8 py-16 text-center"
                >
                    <div
                        class="flex h-16 w-16 items-center justify-center rounded-full bg-kainu-surface text-stone-400"
                    >
                        <ShoppingBasket :size="28" :stroke-width="1.5" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <h2 class="text-[18px] font-semibold text-stone-900">
                            Krepšelis tuščias
                        </h2>
                        <p class="max-w-sm text-[13px] text-stone-500">
                            Pradėkite rinkti prekes su geriausia kaina — krepšelį
                            matysite čia bet kuriuo metu.
                        </p>
                    </div>
                    <Link
                        href="/products"
                        class="inline-flex h-10 items-center gap-2 rounded-[10px] bg-brand-700 px-4 text-[13px] font-semibold text-white transition-colors hover:bg-brand-800"
                    >
                        Peržiūrėti pasiūlymus
                    </Link>
                </div>

                <template v-else>
                    <div
                        v-for="group in storeGroups"
                        :key="group.storeId"
                        class="overflow-hidden rounded-[16px] border border-kainu-border bg-white"
                    >
                        <div
                            class="flex items-center gap-3 border-b border-kainu-border bg-kainu-surface px-5 py-3"
                        >
                            <StoreLogo
                                :store-id="group.storeId"
                                :size="24"
                                :show-name="true"
                            />
                            <div class="flex-1" />
                            <div class="text-[11px] font-medium text-stone-500">
                                {{ group.items.length }}
                                {{
                                    group.items.length === 1
                                        ? 'prekė'
                                        : 'prekės'
                                }}
                            </div>
                        </div>

                        <ul>
                            <li
                                v-for="i in group.items"
                                :key="`${i.id}-${i.storeId}`"
                                class="flex flex-wrap items-center gap-3 border-b border-kainu-border px-4 py-4 last:border-0 sm:flex-nowrap sm:gap-4 sm:px-5"
                            >
                                <ProductImage
                                    :seed="i.id"
                                    :src="i.product.image"
                                    :name="i.product.name"
                                    :emoji="i.product.emoji"
                                    :size="64"
                                    class="shrink-0"
                                />
                                <div class="min-w-0 flex-1">
                                    <div class="mb-1 flex items-start gap-2">
                                        <div class="min-w-0 flex-1">
                                            <Link
                                                :href="`/products/${i.id}`"
                                                class="block truncate text-[14px] font-semibold text-stone-900 hover:underline"
                                            >
                                                {{ i.product.name }}
                                            </Link>
                                            <div
                                                class="mt-0.5 flex items-center gap-2 text-[12px] text-stone-500"
                                            >
                                                <span v-if="i.product.brand">
                                                    {{ i.product.brand }}
                                                </span>
                                                <span
                                                    v-if="i.product.brand && i.product.unit"
                                                    class="text-stone-300"
                                                >
                                                    ·
                                                </span>
                                                <span v-if="i.product.unit">
                                                    {{ i.product.unit }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <div
                                        class="flex items-baseline gap-2 text-[12px]"
                                    >
                                        <span
                                            class="text-[14px] font-bold text-stone-900"
                                        >
                                            {{ formatPrice(i.product.bestPrice) }}
                                        </span>
                                        <span
                                            v-if="
                                                i.product.oldPrice &&
                                                i.product.bestPrice &&
                                                i.product.oldPrice > i.product.bestPrice
                                            "
                                            class="text-stone-400 line-through"
                                        >
                                            {{ formatPrice(i.product.oldPrice) }}
                                        </span>
                                        <span
                                            v-if="
                                                discountPct(
                                                    i.product.oldPrice,
                                                    i.product.bestPrice,
                                                ) > 0
                                            "
                                            class="inline-flex items-center gap-1 rounded bg-brand-700/10 px-1.5 py-px font-semibold text-brand-700"
                                        >
                                            <TrendingDown
                                                :size="11"
                                                :stroke-width="2.25"
                                            />
                                            −{{
                                                discountPct(
                                                    i.product.oldPrice,
                                                    i.product.bestPrice,
                                                )
                                            }}%
                                        </span>
                                    </div>
                                </div>

                                <div
                                    class="flex w-full items-center justify-between gap-3 sm:contents"
                                >
                                    <div
                                        class="flex items-center overflow-hidden rounded-[10px] border border-kainu-border"
                                    >
                                        <button
                                            type="button"
                                            aria-label="Mažinti"
                                            class="flex h-8 w-8 items-center justify-center text-stone-700 transition-colors hover:bg-kainu-surface"
                                            @click="decrement(i.id, i.storeId)"
                                        >
                                            <Minus :size="14" />
                                        </button>
                                        <div
                                            class="min-w-[28px] text-center text-[13px] font-semibold"
                                        >
                                            {{ i.qty }}
                                        </div>
                                        <button
                                            type="button"
                                            aria-label="Daugiau"
                                            class="flex h-8 w-8 items-center justify-center text-stone-700 transition-colors hover:bg-kainu-surface"
                                            @click="increment(i.id, i.storeId)"
                                        >
                                            <Plus :size="14" />
                                        </button>
                                    </div>

                                    <div
                                        class="min-w-[80px] text-right text-[15px] font-bold text-stone-900 tabular-nums"
                                    >
                                        {{
                                            formatPrice(
                                                (i.product.bestPrice ?? 0) * i.qty,
                                            )
                                        }}
                                    </div>

                                    <button
                                        type="button"
                                        aria-label="Pašalinti"
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[8px] text-stone-400 transition-colors hover:bg-red-50 hover:text-red-600"
                                        @click="remove(i.id, i.storeId)"
                                    >
                                        <X :size="16" />
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </div>
                </template>

                <section
                    v-if="planningVisible"
                    ref="planningSectionRef"
                    class="flex flex-col gap-4 scroll-mt-20 rounded-[16px] border border-kainu-border bg-white p-5"
                >
                    <header class="flex items-start justify-between gap-3">
                        <div>
                            <h2
                                class="text-[16px] font-semibold tracking-tight text-stone-900"
                            >
                                Pirkinių sąrašas ir maršrutas
                            </h2>
                            <p class="mt-0.5 text-[12px] text-stone-500">
                                Aplankykite parduotuves žemiau pateiktame
                                eiliškume — tai trumpiausias kelias visoms
                                krepšelio prekėms surinkti.
                            </p>
                        </div>
                        <button
                            type="button"
                            aria-label="Slėpti pirkinių sąrašą"
                            class="-mr-1 inline-flex h-8 w-8 items-center justify-center rounded-md text-stone-400 transition-colors hover:bg-stone-100 hover:text-stone-700"
                            @click="planningVisible = false"
                        >
                            <X :size="16" :stroke-width="2" />
                        </button>
                    </header>

                    <div class="flex flex-col gap-4">
                        <RoutePlanner
                            :chains="highlightChains"
                            :has-maps-key="hasMapsKey"
                            :api-key="googleMaps.apiKey ?? null"
                            @plan="onRoutePlan"
                            @origin="onRouteOrigin"
                        />
                        <StoreMap
                            :config="googleMaps"
                            :origin="routeOrigin"
                            :stops="routeStops"
                            :route-polyline="routePolyline"
                        />
                    </div>
                </section>
            </div>

            <aside class="flex flex-col gap-4 lg:sticky lg:top-6 lg:self-start">
                <div
                    class="flex flex-col gap-3 rounded-[16px] border border-kainu-border bg-white p-5 shadow-[0_8px_32px_-20px_rgba(15,23,42,0.2)]"
                >
                    <div class="flex items-center gap-2">
                        <Receipt
                            :size="16"
                            :stroke-width="2"
                            class="text-brand-700"
                        />
                        <h3
                            class="text-[15px] font-semibold tracking-tight text-stone-900"
                        >
                            Užsakymo suvestinė
                        </h3>
                    </div>

                    <dl class="flex flex-col gap-2 text-[13px]">
                        <div class="flex justify-between text-stone-500">
                            <dt>Tarpinė suma</dt>
                            <dd class="tabular-nums text-stone-700">
                                {{ formatPrice(originalTotal) }}
                            </dd>
                        </div>
                        <div
                            v-if="savings > 0"
                            class="flex justify-between font-medium text-brand-700"
                        >
                            <dt>Sutaupėte ({{ savingsPct }}%)</dt>
                            <dd class="tabular-nums">
                                −{{ formatPrice(savings) }}
                            </dd>
                        </div>
                        <div
                            class="mt-1 flex justify-between border-t border-kainu-border pt-3 text-[16px] font-bold text-stone-900"
                        >
                            <dt>Iš viso</dt>
                            <dd class="tabular-nums">
                                {{ formatPrice(subtotal) }}
                            </dd>
                        </div>
                    </dl>

                    <button
                        type="button"
                        :disabled="items.length === 0"
                        class="mt-2 inline-flex h-11 w-full items-center justify-center gap-2 rounded-[10px] bg-brand-700 text-[14px] font-semibold text-white transition-colors hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-60"
                        @click="generateShoppingPlan"
                    >
                        {{ planningVisible ? 'Atnaujinti pirkinių sąrašą' : 'Generuoti pirkinių sąrašą' }}
                    </button>
                </div>
            </aside>
        </div>
    </div>
</template>
