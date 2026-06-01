<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChevronRight,
    Clock,
    LineChart,
    Minus,
    Pencil,
    Plus,
    ShoppingCart,
    Tag,
    TriangleAlert,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import {
    destroy as reportDestroy,
    store as reportStore,
} from '@/actions/App/Http/Controllers/ProductReportController';
import EditProductDialog from '@/components/admin/EditProductDialog.vue';
import type {
    AdminProduct,
    CategoryNode,
    FamilyOption,
} from '@/components/admin/EditProductDialog.vue';
import ConditionBadge from '@/components/kainu/ConditionBadge.vue';
import DiscountBadge from '@/components/kainu/DiscountBadge.vue';
import PriceHistoryChart from '@/components/kainu/PriceHistoryChart.vue';
import ProductImage from '@/components/kainu/ProductImage.vue';
import StoreLogo from '@/components/kainu/StoreLogo.vue';
import { useKainuCart } from '@/composables/useKainuCart';
import { discountPct, formatPrice, isMeaningfulCondition } from '@/lib/kainu';
import type { KainuProduct } from '@/types/kainu';

type StoreOffer = {
    id: number;
    productId: number;
    productName: string;
    imageUrl: string | null;
    store: string;
    storeName: string;
    price: number;
    oldPrice: number | null;
    condition: string | null;
    url: string | null;
    sizeLabel: string | null;
    unitPriceLabel: string | null;
    isExactMatch: boolean;
};

const props = defineProps<{
    product: KainuProduct;
    offers: StoreOffer[];
    history: number[];
    historyLabels: string[];
    historyDates: string[];
    historyIsReal: boolean;
    reportedIds: number[];
    adminEdit: AdminProduct | null;
    adminCategories: CategoryNode[] | null;
    adminFamilies: FamilyOption[] | null;
}>();

const editingProduct = ref<AdminProduct | null>(null);

function openAdminEdit() {
    if (props.adminEdit) {
        editingProduct.value = props.adminEdit;
    }
}

function closeAdminEdit() {
    editingProduct.value = null;
}

const { addToCart, openDrawer } = useKainuCart();

const page = usePage();
const isLoggedIn = () => !!page.props.auth?.user;

function handleToggleReport(product: KainuProduct) {
    if (!isLoggedIn()) {
        router.visit('/login');

        return;
    }

    const isReported = props.reportedIds.includes(product.normalizedProductId);

    if (isReported) {
        router.delete(reportDestroy(product.normalizedProductId).url, {
            preserveScroll: true,
            preserveState: true,
        });
    } else {
        router.post(
            reportStore(product.normalizedProductId).url,
            {},
            { preserveScroll: true, preserveState: true },
        );
    }
}

const isProductReported = computed(() =>
    props.reportedIds.includes(props.product.normalizedProductId),
);

const displayName = computed(
    () => props.product.displayName?.trim() || props.product.name,
);

const currentOffer = computed(
    () => props.offers.find((o) => o.id === props.product.id) ?? null,
);

// The comparison table should show alternatives, not the product you're
// already viewing, so drop the offer for the current product + store.
const comparisonOffers = computed(() =>
    props.offers.filter(
        (o) =>
            !(
                o.productId === props.product.normalizedProductId &&
                o.store === props.product.bestStore
            ),
    ),
);

function formatDate(iso: string): string {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) {
        return iso.slice(0, 10);
    }

    return d.toLocaleDateString('lt-LT', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
}

const specs = computed(() => {
    const rows: { label: string; value: string }[] = [];
    const p = props.product;

    if (p.brand) {
        rows.push({ label: 'Prekės ženklas', value: p.brand });
    }

    const size = currentOffer.value?.sizeLabel ?? p.unit;
    if (size) {
        rows.push({ label: 'Kiekis', value: size });
    }

    const unitPrice = p.unitPrice ?? currentOffer.value?.unitPriceLabel ?? null;
    if (unitPrice) {
        rows.push({ label: 'Vieneto kaina', value: unitPrice });
    }

    if (p.condition && isMeaningfulCondition(p.condition)) {
        rows.push({ label: 'Akcijos sąlyga', value: p.condition });
    }

    rows.push({
        label: 'Lojalumo kortelė',
        value: p.loyaltyCard ? 'Reikalinga' : 'Nereikalinga',
    });

    if (p.validDates) {
        rows.push({ label: 'Galioja', value: p.validDates });
    }

    if (p.scrapedAt) {
        rows.push({ label: 'Atnaujinta', value: formatDate(p.scrapedAt) });
    }

    return rows;
});

const qty = ref(1);
const selectedStore = ref<string>(props.product.bestStore);

const pct = computed(() => {
    const explicit = props.product.discount;

    if (typeof explicit === 'number' && explicit > 0 && explicit < 100) {
        return Math.round(explicit);
    }

    return discountPct(props.product.oldPrice, props.product.bestPrice);
});

const savings = computed(() => {
    if (!props.product.oldPrice || !props.product.bestPrice) {
        return 0;
    }

    return Math.max(0, props.product.oldPrice - props.product.bestPrice);
});

const totalPrice = computed(() => (props.product.bestPrice ?? 0) * qty.value);

function handleAdd() {
    if (!isLoggedIn()) {
        router.visit('/login');

        return;
    }

    addToCart(props.product, qty.value, selectedStore.value);
    openDrawer();
}

function increment() {
    qty.value = Math.min(99, qty.value + 1);
}

function decrement() {
    qty.value = Math.max(1, qty.value - 1);
}
</script>

<template>
    <Head :title="displayName" />

    <div class="pt-5 pb-10">
        <div
            class="mb-5 flex flex-wrap items-center gap-[10px] text-[13px] text-stone-500"
        >
            <Link
                href="/products"
                class="inline-flex items-center gap-1 hover:text-stone-700"
            >
                <ArrowLeft :size="14" />
                Atgal
            </Link>
            <span>/</span>
            <span v-if="product.brand">{{ product.brand }}</span>
            <template v-if="product.brand">
                <ChevronRight :size="12" />
            </template>
            <span class="font-medium text-stone-900">{{ displayName }}</span>
            <button
                v-if="adminEdit"
                type="button"
                class="ml-auto inline-flex items-center gap-1 rounded-md border border-stone-200 bg-white px-2 py-1 text-[12px] font-medium text-stone-600 hover:border-stone-300 hover:text-stone-900"
                @click="openAdminEdit"
            >
                <Pencil :size="12" />
                Redaguoti
            </button>
        </div>

        <div class="mb-10 grid grid-cols-1 gap-9 lg:grid-cols-[420px_1fr]">
            <div
                class="relative flex min-h-[420px] items-center justify-center rounded-[16px] border border-[#e7e5e0] bg-white p-10"
            >
                <div v-if="pct > 0" class="absolute top-4 left-4">
                    <DiscountBadge :pct="pct" size="lg" />
                </div>
                <button
                    type="button"
                    :aria-label="
                        isProductReported
                            ? 'Atšaukti pranešimą'
                            : 'Pranešti apie klaidą'
                    "
                    :title="
                        isProductReported
                            ? 'Atšaukti pranešimą'
                            : 'Pranešti apie klaidą'
                    "
                    class="absolute top-4 right-4 flex h-10 w-10 items-center justify-center rounded-full border transition-colors"
                    :class="
                        isProductReported
                            ? 'border-amber-300 bg-amber-50 text-amber-600'
                            : 'border-[#e7e5e0] bg-[#fafaf7] text-stone-500 hover:border-amber-300 hover:bg-amber-50 hover:text-amber-500'
                    "
                    @click="handleToggleReport(product)"
                >
                    <TriangleAlert :size="16" :stroke-width="2" />
                </button>
                <ProductImage
                    :seed="product.id"
                    :src="product.image"
                    :name="displayName"
                    :emoji="product.emoji"
                    :size="280"
                    :rounded="18"
                />
            </div>

            <div>
                <div
                    v-if="product.brand"
                    class="mb-[6px] text-[11px] font-bold tracking-[0.08em] text-[#15803d] uppercase"
                >
                    {{ product.brand }}
                </div>
                <h1
                    class="m-0 text-[30px] leading-[1.15] font-bold tracking-tight text-stone-900"
                >
                    {{ displayName }}
                </h1>
                <div
                    v-if="product.unit"
                    class="mt-[6px] mb-5 text-[14px] text-stone-500"
                >
                    {{ product.unit }}
                </div>

                <div
                    class="mb-5 rounded-[14px] border border-[#bbf7d0] bg-brand-50 px-5 py-[18px]"
                >
                    <div
                        class="mb-[6px] flex items-center gap-[6px] text-[11px] font-bold tracking-[0.06em] text-[#15803d] uppercase"
                    >
                        <Tag :size="12" />
                        {{
                            product.bestPrice !== null
                                ? 'Geriausia kaina'
                                : 'Nuolaida'
                        }}
                    </div>
                    <div class="mb-2 flex flex-wrap items-baseline gap-[10px]">
                        <span
                            v-if="product.bestPrice !== null"
                            class="text-[38px] leading-none font-bold tracking-tighter text-[#15803d]"
                        >
                            {{ formatPrice(product.bestPrice) }}
                        </span>
                        <span
                            v-else-if="pct > 0"
                            class="text-[38px] leading-none font-bold tracking-tighter text-[#15803d]"
                        >
                            −{{ pct }}%
                        </span>
                        <span
                            v-else
                            class="text-[38px] leading-none font-bold tracking-tighter text-[#15803d]"
                        >
                            —
                        </span>
                        <span
                            v-if="
                                product.oldPrice &&
                                product.bestPrice &&
                                product.oldPrice > product.bestPrice
                            "
                            class="text-[17px] text-stone-400 line-through"
                        >
                            {{ formatPrice(product.oldPrice) }}
                        </span>
                        <span
                            v-if="savings > 0"
                            class="text-[13px] font-semibold text-[#15803d]"
                        >
                            sutaupote {{ formatPrice(savings) }}
                        </span>
                        <ConditionBadge
                            v-if="isMeaningfulCondition(product.condition)"
                            :condition="product.condition"
                            size="sm"
                        />
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <StoreLogo
                            :store-id="product.bestStore"
                            :size="20"
                            :show-name="true"
                        />
                        <span class="text-[12px] text-stone-500">•</span>
                        <span
                            v-if="product.validDates"
                            class="inline-flex items-center gap-1 text-[12px] text-stone-700"
                        >
                            <Clock :size="12" />
                            {{ product.validDates }}
                        </span>
                        <span
                            v-else
                            class="inline-flex items-center gap-1 text-[12px] text-stone-700"
                        >
                            <Clock :size="12" />
                            Kaina atnaujinta
                        </span>
                    </div>
                </div>

                <div v-if="isLoggedIn()" class="flex items-stretch gap-[10px]">
                    <div
                        class="flex items-center overflow-hidden rounded-[10px] border border-[#e7e5e0] bg-white"
                    >
                        <button
                            type="button"
                            aria-label="Mažinti"
                            class="flex h-11 w-10 items-center justify-center text-stone-700 hover:bg-stone-100"
                            @click="decrement"
                        >
                            <Minus :size="16" :stroke-width="2.25" />
                        </button>
                        <div
                            class="min-w-8 text-center text-[15px] font-semibold text-stone-900"
                        >
                            {{ qty }}
                        </div>
                        <button
                            type="button"
                            aria-label="Daugiau"
                            class="flex h-11 w-10 items-center justify-center text-stone-700 hover:bg-stone-100"
                            @click="increment"
                        >
                            <Plus :size="16" :stroke-width="2.25" />
                        </button>
                    </div>
                    <button
                        type="button"
                        class="inline-flex flex-1 items-center justify-center gap-2 rounded-[10px] bg-stone-900 px-6 text-[14px] font-semibold text-white transition-colors hover:bg-[#15803d]"
                        @click="handleAdd"
                    >
                        <ShoppingCart :size="17" />
                        Į krepšelį · {{ formatPrice(totalPrice) }}
                    </button>
                </div>
                <Link
                    v-else
                    href="/login"
                    class="inline-flex items-center justify-center gap-2 rounded-[10px] bg-stone-900 px-6 py-3 text-[14px] font-semibold text-white transition-colors hover:bg-[#15803d]"
                >
                    <ShoppingCart :size="17" />
                    Prisijunkite, kad pridėtumėte į krepšelį
                </Link>

                <dl
                    v-if="specs.length > 0"
                    class="mt-5 overflow-hidden rounded-[12px] border border-[#e7e5e0]"
                >
                    <div
                        v-for="(spec, i) in specs"
                        :key="spec.label"
                        class="flex items-center justify-between gap-4 px-4 py-[10px] text-[13px]"
                        :class="i % 2 === 1 ? 'bg-[#fafaf7]' : 'bg-white'"
                    >
                        <dt class="text-stone-500">{{ spec.label }}</dt>
                        <dd class="m-0 text-right font-medium text-stone-900">
                            {{ spec.value }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        <section class="mb-9">
            <div class="rounded-[16px] border border-[#e7e5e0] bg-white p-6">
                <div class="mb-[18px] flex items-baseline justify-between">
                    <div>
                        <h2
                            class="m-0 text-[18px] font-semibold tracking-tight text-stone-900"
                        >
                            Kainų istorija
                        </h2>
                        <p
                            v-if="historyIsReal"
                            class="mt-[2px] text-[12px] text-stone-500"
                        >
                            {{ history.length }} stebėjimai ({{
                                historyLabels[0]
                            }}
                            – {{ historyLabels[historyLabels.length - 1] }})
                        </p>
                    </div>
                </div>

                <PriceHistoryChart
                    v-if="historyIsReal"
                    :values="history"
                    :labels="historyLabels"
                    :dates="historyDates"
                />
                <div
                    v-else
                    class="flex flex-col items-center justify-center gap-2 rounded-[12px] border border-dashed border-[#e7e5e0] bg-[#fafaf7] py-10 text-center"
                >
                    <LineChart
                        :size="22"
                        :stroke-width="1.5"
                        class="text-stone-400"
                    />
                    <p class="m-0 text-[13px] font-medium text-stone-700">
                        Kainų istorijos kol kas nėra
                    </p>
                    <p
                        class="m-0 max-w-[360px] text-[11px] leading-relaxed text-stone-500"
                    >
                        Istorija atsiras, kai šis produktas bus stebimas bent
                        dvi savaites — vienos savaitės akcijos kainai palyginti
                        dar nėra su kuo.
                    </p>
                </div>
            </div>
        </section>

        <section class="mb-9">
            <h2
                class="m-0 mb-[14px] text-[18px] font-semibold tracking-tight text-stone-900"
            >
                Panašūs pasiūlymai
                <span
                    v-if="comparisonOffers.length > 1"
                    class="ml-1 text-[15px] font-medium text-stone-400"
                >
                    {{ comparisonOffers.length }}
                </span>
            </h2>
            <div
                v-if="comparisonOffers.length > 0"
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <Link
                    v-for="(offer, i) in comparisonOffers"
                    :key="`${offer.store}-${offer.id}`"
                    :href="`/products/${offer.id}`"
                    class="group relative flex gap-3 rounded-xl border bg-white p-3 transition-all hover:-translate-y-0.5 hover:shadow-md"
                    :class="[
                        i === 0
                            ? 'border-emerald-300 bg-brand-50'
                            : 'border-stone-200 hover:border-stone-300',
                        offer.id === product.id ? 'ring-2 ring-stone-300' : '',
                    ]"
                >
                    <ProductImage
                        :seed="offer.productId"
                        :src="offer.imageUrl"
                        :name="offer.productName"
                        :emoji="product.emoji"
                        :size="64"
                        :rounded="8"
                    />
                    <div class="flex min-w-0 flex-1 flex-col">
                        <div class="flex items-center gap-1.5">
                            <StoreLogo :store-id="offer.store" :size="18" />
                            <span
                                class="truncate text-[12px] font-semibold text-stone-700"
                            >
                                {{ offer.storeName }}
                            </span>
                            <span
                                v-if="offer.sizeLabel"
                                class="ml-auto shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-semibold whitespace-nowrap"
                                :class="
                                    offer.isExactMatch
                                        ? 'bg-stone-100 text-stone-600'
                                        : 'bg-amber-50 text-amber-700'
                                "
                            >
                                {{ offer.sizeLabel }}
                            </span>
                        </div>
                        <div
                            class="mt-1 line-clamp-2 text-[12px] leading-snug text-stone-600"
                        >
                            {{ offer.productName }}
                        </div>
                        <div class="mt-auto flex items-baseline gap-1.5 pt-2">
                            <span
                                class="text-[18px] leading-none font-bold tracking-tight"
                                :class="
                                    i === 0
                                        ? 'text-[#15803d]'
                                        : 'text-stone-900'
                                "
                            >
                                {{ formatPrice(offer.price) }}
                            </span>
                            <span
                                v-if="
                                    offer.oldPrice &&
                                    offer.oldPrice > offer.price
                                "
                                class="text-[11px] text-stone-400 line-through"
                            >
                                {{ formatPrice(offer.oldPrice) }}
                            </span>
                        </div>
                        <div
                            v-if="offer.unitPriceLabel"
                            class="text-[10px] font-medium text-stone-500"
                        >
                            {{ offer.unitPriceLabel }}
                        </div>
                        <div
                            v-if="isMeaningfulCondition(offer.condition)"
                            class="mt-1.5"
                        >
                            <ConditionBadge
                                :condition="offer.condition"
                                size="sm"
                            />
                        </div>
                    </div>
                </Link>
            </div>
            <div
                v-else
                class="rounded-[12px] border border-dashed border-[#e7e5e0] bg-[#fafaf7] px-5 py-8 text-center text-[13px] text-stone-500"
            >
                Nėra panašių pasiūlymų.
            </div>
        </section>

        <EditProductDialog
            v-if="adminEdit && adminCategories"
            :product="editingProduct"
            :categories="adminCategories"
            :families="adminFamilies ?? []"
            @close="closeAdminEdit"
        />
    </div>
</template>
