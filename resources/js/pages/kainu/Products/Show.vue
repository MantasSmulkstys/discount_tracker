<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Bell,
    ChevronRight,
    Clock,
    Heart,
    MapPin,
    Minus,
    Plus,
    ShoppingCart,
    Sparkles,
    Tag,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import DiscountBadge from '@/components/kainu/DiscountBadge.vue';
import PriceHistoryChart from '@/components/kainu/PriceHistoryChart.vue';
import ProductCard from '@/components/kainu/ProductCard.vue';
import ProductImage from '@/components/kainu/ProductImage.vue';
import StoreLogo from '@/components/kainu/StoreLogo.vue';
import { useKainuCart } from '@/composables/useKainuCart';
import { useKainuFavorites } from '@/composables/useKainuFavorites';
import { discountPct, formatPrice } from '@/lib/kainu';
import type { KainuProduct } from '@/types/kainu';

type StoreOffer = {
    id: number;
    store: string;
    storeName: string;
    price: number;
    oldPrice: number | null;
    url: string | null;
};

type Prediction = {
    willDrop: boolean;
    predictedPrice: number;
    confidence: number;
    window: string;
    basedOn: string;
};

const props = defineProps<{
    product: KainuProduct;
    offers: StoreOffer[];
    alternatives: KainuProduct[];
    history: number[];
    historyLabels: string[];
    historyIsReal: boolean;
    prediction: Prediction;
}>();

const { addToCart, openDrawer } = useKainuCart();
const { toggle, isFavorited } = useKainuFavorites();

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

function pickStore(offer: StoreOffer) {
    selectedStore.value = offer.store;
    addToCart(
        {
            ...props.product,
            bestPrice: offer.price,
            oldPrice: offer.oldPrice,
            bestStore: offer.store,
            bestStoreName: offer.storeName,
        },
        1,
        offer.store,
    );
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
                        isFavorited(product.id)
                            ? 'Pašalinti iš mėgstamų'
                            : 'Pridėti į mėgstamus'
                    "
                    class="absolute top-4 right-4 flex h-10 w-10 items-center justify-center rounded-full border transition-colors"
                    :class="
                        isFavorited(product.id)
                            ? 'border-red-200 bg-red-50 text-red-600'
                            : 'border-[#e7e5e0] bg-[#fafaf7] text-stone-700 hover:border-stone-300'
                    "
                    @click="toggle(product.id)"
                >
                    <Heart
                        :size="18"
                        :stroke-width="2"
                        :fill="
                            isFavorited(product.id) ? 'currentColor' : 'none'
                        "
                    />
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

                <div
                    class="mb-5 flex items-center gap-3 rounded-[12px] border border-[#e7e5e0] bg-[#fafaf7] px-4 py-3"
                >
                    <div
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-[#e7e5e0] bg-white text-[#15803d]"
                    >
                        <Sparkles :size="16" />
                    </div>
                    <div class="flex-1">
                        <div
                            class="mb-[2px] text-[13px] font-semibold text-stone-900"
                        >
                            <template v-if="prediction.willDrop">
                                Prognozė: atpigs iki
                                {{ formatPrice(prediction.predictedPrice) }}
                            </template>
                            <template v-else>
                                Prognozė: kaina stabili
                            </template>
                        </div>
                        <div class="text-[11px] text-stone-500">
                            {{ prediction.basedOn }} · Patikimumas:
                            {{ prediction.confidence }}%
                        </div>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-[8px] border border-[#e7e5e0] bg-white px-[10px] py-[6px] text-[12px] font-semibold text-stone-700 transition-colors hover:border-stone-300"
                    >
                        <Bell :size="12" />
                        Įspėti
                    </button>
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
                        <p class="mt-[2px] text-[12px] text-stone-500">
                            <template v-if="historyIsReal">
                                {{ history.length }} stebėjimai
                                ({{ historyLabels[0] }} –
                                {{ historyLabels[historyLabels.length - 1] }})
                            </template>
                            <template v-else>
                                Modeliuojama – kol kas nepakanka realių
                                stebėjimų
                            </template>
                        </p>
                    </div>
                </div>
                <PriceHistoryChart
                    :values="history"
                    :labels="historyLabels"
                />
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
                class="overflow-hidden rounded-[12px] border border-[#e7e5e0] bg-white"
            >
                <div
                    v-for="(offer, i) in offers"
                    :key="`${offer.store}-${offer.id}`"
                    class="grid grid-cols-[1fr_auto_auto_auto] items-center gap-4 px-5 py-[14px]"
                    :class="[
                        i < offers.length - 1
                            ? 'border-b border-[#f5f5f0]'
                            : '',
                        i === 0 ? 'bg-brand-50' : 'bg-white',
                    ]"
                >
                    <div class="flex items-center gap-3">
                        <StoreLogo :store-id="offer.store" :size="32" />
                        <div>
                            <div
                                class="text-[14px] font-semibold text-stone-900"
                            >
                                {{ offer.storeName }}
                            </div>
                            <div
                                class="inline-flex items-center gap-1 text-[11px] text-stone-500"
                            >
                                <MapPin :size="11" />
                                artimiausia parduotuvė
                            </div>
                        </div>
                    </div>
                    <DiscountBadge
                        :pct="discountPct(offer.oldPrice, offer.price)"
                        size="sm"
                    />
                    <div class="text-right">
                        <div
                            v-if="
                                offer.oldPrice && offer.oldPrice > offer.price
                            "
                            class="text-[11px] leading-none text-stone-400 line-through"
                        >
                            {{ formatPrice(offer.oldPrice) }}
                        </div>
                        <div
                            class="mt-[2px] text-[17px] font-bold tracking-tight"
                            :class="
                                i === 0 ? 'text-[#15803d]' : 'text-stone-900'
                            "
                        >
                            {{ formatPrice(offer.price) }}
                        </div>
                    </div>
                    <button
                        type="button"
                        class="rounded-[8px] border px-[14px] py-2 text-[13px] font-semibold whitespace-nowrap transition-colors"
                        :class="
                            i === 0
                                ? 'border-stone-900 bg-stone-900 text-white hover:bg-[#15803d]'
                                : 'border-[#e7e5e0] bg-[#fafaf7] text-stone-900 hover:border-stone-300'
                        "
                        @click="pickStore(offer)"
                    >
                        {{ i === 0 ? 'Pridėti' : 'Rinktis' }}
                    </button>
                </div>
            </div>
        </section>

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
                    :favorited="isFavorited(alt.id)"
                    compact
                    @add-to-cart="handleAltAdd"
                    @toggle-favorite="toggle(alt.id)"
                />
            </div>
        </section>
    </div>
</template>
