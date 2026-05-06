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
import type {AdminProduct, CategoryNode} from '@/components/admin/EditProductDialog.vue';
import ConditionBadge from '@/components/kainu/ConditionBadge.vue';
import DiscountBadge from '@/components/kainu/DiscountBadge.vue';
import PriceHistoryChart from '@/components/kainu/PriceHistoryChart.vue';
import ProductCard from '@/components/kainu/ProductCard.vue';
import ProductImage from '@/components/kainu/ProductImage.vue';
import StoreLogo from '@/components/kainu/StoreLogo.vue';
import { useKainuCart } from '@/composables/useKainuCart';
import { discountPct, formatPrice } from '@/lib/kainu';
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
    alternatives: KainuProduct[];
    history: number[];
    historyLabels: string[];
    historyIsReal: boolean;
    reportedIds: number[];
    adminEdit: AdminProduct | null;
    adminCategories: CategoryNode[] | null;
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

const qty = ref(1);
const selectedStore = ref<string>(props.product.bestStore);

const pct = computed(() =>
    discountPct(props.product.oldPrice, props.product.bestPrice),
);

const savings = computed(() => {
    if (!props.product.oldPrice || !props.product.bestPrice) {
        return 0;
    }

    return Math.max(0, props.product.oldPrice - props.product.bestPrice);
});

const totalPrice = computed(() => (props.product.bestPrice ?? 0) * qty.value);

function handleAdd() {
    addToCart(props.product, qty.value, selectedStore.value);
    openDrawer();
}

function increment() {
    qty.value = Math.min(99, qty.value + 1);
}

function decrement() {
    qty.value = Math.max(1, qty.value - 1);
}

function handleAltAdd(alt: KainuProduct) {
    addToCart(alt);
    openDrawer();
}
</script>

<template>
    <Head :title="product.name" />

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
            <span class="font-medium text-stone-900">{{ product.name }}</span>
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
                    :name="product.name"
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
                    {{ product.name }}
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
                        Geriausia kaina
                    </div>
                    <div class="mb-2 flex flex-wrap items-baseline gap-[10px]">
                        <span
                            class="text-[38px] leading-none font-bold tracking-tighter text-[#15803d]"
                        >
                            {{ formatPrice(product.bestPrice) }}
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
                            v-if="product.condition"
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

                <div class="flex items-stretch gap-[10px]">
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

                <a
                    v-if="product.url"
                    :href="product.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-3 inline-flex items-center gap-1 text-[13px] font-medium text-[#15803d] hover:underline"
                >
                    Peržiūrėti parduotuvės puslapyje →
                </a>
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
                            {{ history.length }} stebėjimai
                            ({{ historyLabels[0] }} –
                            {{ historyLabels[historyLabels.length - 1] }})
                        </p>
                    </div>
                </div>

                <PriceHistoryChart
                    v-if="historyIsReal"
                    :values="history"
                    :labels="historyLabels"
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
                        dvi savaites — vienos savaitės akcijos kainai
                        palyginti dar nėra su kuo.
                    </p>
                </div>
            </div>
        </section>

        <section v-if="offers.length > 0" class="mb-9">
            <h2
                class="m-0 mb-[14px] text-[18px] font-semibold tracking-tight text-stone-900"
            >
                Palyginimas
                <template v-if="offers.length > 1">
                    {{ offers.length }} parduotuvėse
                </template>
            </h2>
            <div
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <Link
                    v-for="(offer, i) in offers"
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
                        <div v-if="offer.condition" class="mt-1.5">
                            <ConditionBadge
                                :condition="offer.condition"
                                size="sm"
                            />
                        </div>
                    </div>
                </Link>
            </div>
        </section>

        <EditProductDialog
            v-if="adminEdit && adminCategories"
            :product="editingProduct"
            :categories="adminCategories"
            @close="closeAdminEdit"
        />

        <section v-if="alternatives.length > 0">
            <h2
                class="m-0 mb-[14px] text-[18px] font-semibold tracking-tight text-stone-900"
            >
                Panašios prekės
            </h2>
            <div
                class="grid grid-cols-2 gap-[14px] sm:grid-cols-3 lg:grid-cols-4"
            >
                <ProductCard
                    v-for="alt in alternatives"
                    :key="alt.id"
                    :product="alt"
                    :reported="reportedIds.includes(alt.normalizedProductId)"
                    compact
                    @add-to-cart="handleAltAdd"
                    @toggle-report="handleToggleReport"
                />
            </div>
        </section>
    </div>
</template>
