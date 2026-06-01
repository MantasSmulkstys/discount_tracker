<script setup lang="ts">
import { ArrowRight, ChevronDown, ChevronUp, Lock } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import StoreLogo from '@/components/kainu/StoreLogo.vue';
import { formatPrice } from '@/lib/kainu';

export type AlternativeItem = {
    familyId: number | null;
    normalizedProductId: number | null;
    variantId: number | null;
    storeSlug: string;
    priceCents: number;
    quantity: number;
    name: string;
    fixed: boolean;
};

export type Alternative = {
    label: string;
    stores: string[];
    totalPriceCents: number;
    totalDurationSec: number;
    priceDeltaCents: number;
    durationDeltaSec: number;
    items: AlternativeItem[];
};

const props = defineProps<{
    alternative: Alternative;
}>();

defineEmits<{
    (e: 'apply', alt: Alternative): void;
}>();

const expanded = ref(false);

const totalPrice = computed(() => props.alternative.totalPriceCents / 100);

const priceDelta = computed(() => props.alternative.priceDeltaCents / 100);

const durationDeltaMin = computed(() =>
    Math.round(props.alternative.durationDeltaSec / 60),
);

const priceDeltaLabel = computed(() => {
    const v = priceDelta.value;
    const sign = v < 0 ? '−' : v > 0 ? '+' : '';
    const abs = Math.abs(v);

    return `${sign}${formatPrice(abs)}`;
});

const durationDeltaLabel = computed(() => {
    const v = durationDeltaMin.value;
    const sign = v < 0 ? '−' : v > 0 ? '+' : '';
    const abs = Math.abs(v);

    return `${sign}${abs} min`;
});

const isCheaper = computed(() => priceDelta.value < 0);
const isFaster = computed(() => durationDeltaMin.value < 0);

const visibleSwaps = computed<AlternativeItem[]>(() =>
    props.alternative.items.filter((i) => !i.fixed).slice(0, 5),
);
</script>

<template>
    <article
        class="flex h-full min-w-[220px] flex-col gap-3 rounded-[14px] border border-kainu-border bg-white p-4 shadow-[0_4px_16px_-12px_rgba(15,23,42,0.16)]"
    >
        <header class="flex items-start justify-between gap-2">
            <h4
                class="m-0 text-[14px] font-semibold tracking-tight text-stone-900"
            >
                {{ alternative.label }}
            </h4>
            <span class="text-[11px] text-stone-500">
                {{ alternative.stores.length }}
                {{ alternative.stores.length === 1 ? 'parduotuvė' : 'parduotuvių' }}
            </span>
        </header>

        <div class="flex flex-wrap items-center gap-3">
            <span
                class="text-[15px] font-bold tracking-tight tabular-nums"
                :class="isCheaper ? 'text-[#15803d]' : 'text-stone-900'"
            >
                {{ priceDeltaLabel }}
            </span>
            <span
                v-if="durationDeltaMin !== 0"
                class="text-[12px] font-semibold tabular-nums"
                :class="isFaster ? 'text-[#15803d]' : 'text-stone-500'"
            >
                {{ durationDeltaLabel }}
            </span>
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
            <StoreLogo
                v-for="(slug, idx) in alternative.stores"
                :key="slug + idx"
                :store-id="slug"
                :size="18"
                :width="56"
            />
        </div>

        <div class="text-[12px] text-stone-500">
            Iš viso:
            <span class="font-semibold text-stone-700 tabular-nums">
                {{ formatPrice(totalPrice) }}
            </span>
        </div>

        <button
            type="button"
            class="inline-flex h-9 items-center justify-center gap-1.5 rounded-[10px] bg-brand-700 px-3 text-[12px] font-semibold text-white transition-colors hover:bg-brand-800"
            @click="$emit('apply', alternative)"
        >
            Pakeisti krepšelį
            <ArrowRight :size="13" :stroke-width="2" />
        </button>

        <button
            type="button"
            class="inline-flex items-center gap-1 self-start text-[11px] font-medium text-stone-500 hover:text-stone-700"
            @click="expanded = !expanded"
        >
            <ChevronUp v-if="expanded" :size="12" :stroke-width="2" />
            <ChevronDown v-else :size="12" :stroke-width="2" />
            Kodėl?
        </button>

        <ul
            v-if="expanded"
            class="m-0 flex list-none flex-col gap-1 rounded-[10px] bg-kainu-surface p-3 text-[11px] text-stone-700"
        >
            <li
                v-for="item in visibleSwaps"
                :key="item.normalizedProductId ?? item.name"
                class="flex items-center gap-2"
            >
                <span class="min-w-0 flex-1 truncate">{{ item.name }}</span>
                <StoreLogo :store-id="item.storeSlug" :size="14" :width="40" />
                <span class="tabular-nums">
                    {{ formatPrice(item.priceCents / 100) }}
                </span>
            </li>
            <li
                v-for="item in alternative.items.filter((i) => i.fixed)"
                :key="`fixed-${item.normalizedProductId ?? item.name}`"
                class="flex items-center gap-2 text-stone-400"
            >
                <Lock :size="11" :stroke-width="2" />
                <span class="min-w-0 flex-1 truncate">{{ item.name }}</span>
                <span class="tabular-nums">
                    {{ formatPrice(item.priceCents / 100) }}
                </span>
            </li>
        </ul>
    </article>
</template>
