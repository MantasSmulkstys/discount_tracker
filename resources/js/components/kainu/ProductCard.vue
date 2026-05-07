<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowDownRight, ArrowUpRight, Plus, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';
import ConditionBadge from '@/components/kainu/ConditionBadge.vue';
import DiscountBadge from '@/components/kainu/DiscountBadge.vue';
import ProductImage from '@/components/kainu/ProductImage.vue';
import StoreLogo from '@/components/kainu/StoreLogo.vue';
import {
    discountPct,
    formatPrice,
    isAmbiguousDiscountCondition,
} from '@/lib/kainu';
import type { KainuProduct } from '@/types/kainu';

type Props = {
    product: KainuProduct;
    compact?: boolean;
    reported?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    compact: false,
    reported: false,
});

const page = usePage<{ auth?: { user?: { id: number } | null } }>();
const isAuthenticated = computed(() => !!page.props.auth?.user);

const emit = defineEmits<{
    (e: 'add-to-cart', product: KainuProduct): void;
    (e: 'toggle-report', product: KainuProduct): void;
}>();

const pct = computed(() => {
    const raw = (() => {
        const explicit = props.product.discount;

        if (typeof explicit === 'number' && explicit > 0) {
            return Math.round(explicit);
        }

        return discountPct(props.product.oldPrice, props.product.bestPrice);
    })();

    return raw > 0 && raw < 100 ? raw : 0;
});

const condition = computed(() => props.product.condition?.trim() ?? '');
const conditionIsAmbiguous = computed(() =>
    isAmbiguousDiscountCondition(condition.value),
);

const showDiscountBadge = computed(() => {
    if (pct.value <= 0) {
        return false;
    }

    if (condition.value === '') {
        return props.product.bestPrice === null;
    }

    return !conditionIsAmbiguous.value;
});
const showConditionBadge = computed(() => condition.value !== '');

const priceChange = computed(() => {
    const change = props.product.priceChange;

    if (typeof change !== 'number' || Math.abs(change) < 0.01) {
        return null;
    }

    return {
        amount: change,
        isDrop: change < 0,
    };
});

const priceChangeLabel = computed(() => {
    if (priceChange.value === null) {
        return '';
    }

    const abs = Math.abs(priceChange.value.amount);
    const formatted = abs.toFixed(2).replace('.', ',');

    return `${priceChange.value.isDrop ? '−' : '+'}${formatted} €`;
});

const imageSize = computed(() => (props.compact ? 90 : 110));
const padding = computed(() => (props.compact ? 'p-3' : 'p-[14px]'));

const handleAdd = (event: MouseEvent) => {
    event.preventDefault();
    event.stopPropagation();
    emit('add-to-cart', props.product);
};

const handleReport = (event: MouseEvent) => {
    event.preventDefault();
    event.stopPropagation();
    emit('toggle-report', props.product);
};
</script>

<template>
    <Link
        :href="`/products/${product.id}`"
        class="group relative flex flex-col rounded-[12px] border border-[#e7e5e0] bg-white transition-[border-color,box-shadow,transform] duration-150 hover:border-[#15803d] hover:shadow-[0_2px_8px_rgba(21,128,61,0.08)]"
        :class="padding"
    >
        <div
            v-if="showDiscountBadge || showConditionBadge"
            class="absolute top-[10px] left-[10px] z-[1] flex flex-col items-start gap-[6px]"
        >
            <DiscountBadge v-if="showDiscountBadge" :pct="pct" size="md" />
            <ConditionBadge
                v-if="showConditionBadge"
                :condition="condition"
                size="md"
            />
        </div>

        <button
            type="button"
            :aria-label="
                reported ? 'Atšaukti pranešimą' : 'Pranešti apie klaidą'
            "
            :title="reported ? 'Atšaukti pranešimą' : 'Pranešti apie klaidą'"
            class="absolute top-2 right-2 z-[1] flex h-7 w-7 items-center justify-center rounded-full border transition-colors"
            :class="
                reported
                    ? 'border-amber-300 bg-amber-50 text-amber-600'
                    : 'border-[#e7e5e0] bg-white/90 text-stone-400 hover:border-amber-300 hover:bg-amber-50 hover:text-amber-500'
            "
            @click="handleReport"
        >
            <TriangleAlert :size="13" :stroke-width="2" />
        </button>

        <div class="mt-1 mb-[10px] flex justify-center">
            <ProductImage
                :seed="product.id"
                :src="product.image"
                :name="product.name"
                :emoji="product.emoji"
                :size="imageSize"
            />
        </div>

        <div class="mb-[10px] min-h-10 flex-1">
            <div
                v-if="product.brand"
                class="mb-[2px] truncate text-[10px] font-semibold tracking-[0.04em] text-stone-400 uppercase"
            >
                {{ product.brand }}
            </div>
            <h3
                class="m-0 line-clamp-2 text-[13px] leading-[1.3] font-medium text-stone-900"
            >
                {{ product.name }}
            </h3>
            <div
                v-if="product.unit"
                class="mt-[2px] truncate text-[11px] text-stone-500"
            >
                {{ product.unit }}
            </div>
        </div>

        <div class="mb-2 flex min-h-[18px] items-baseline gap-[6px]">
            <span
                v-if="product.bestPrice !== null"
                class="text-[18px] leading-none font-bold tracking-tight text-[#15803d]"
            >
                {{ formatPrice(product.bestPrice) }}
            </span>
            <span
                v-if="
                    product.oldPrice &&
                    product.bestPrice &&
                    product.oldPrice > product.bestPrice
                "
                class="text-[12px] text-stone-400 line-through"
            >
                {{ formatPrice(product.oldPrice) }}
            </span>
            <span
                v-if="priceChange"
                class="ml-auto inline-flex items-center gap-0.5 rounded-[5px] px-1.5 py-0.5 text-[10px] font-bold leading-none"
                :class="
                    priceChange.isDrop
                        ? 'bg-[#15803d]/10 text-[#15803d]'
                        : 'bg-red-50 text-red-600'
                "
                :title="
                    priceChange.isDrop
                        ? 'Atpigo nuo praėjusios savaitės'
                        : 'Pabrango nuo praėjusios savaitės'
                "
            >
                <ArrowDownRight
                    v-if="priceChange.isDrop"
                    :size="10"
                    :stroke-width="2.5"
                />
                <ArrowUpRight v-else :size="10" :stroke-width="2.5" />
                {{ priceChangeLabel }}
            </span>
        </div>

        <div class="flex items-center justify-between gap-2">
            <div class="flex min-w-0 items-center">
                <StoreLogo
                    :store-id="product.bestStore"
                    :size="28"
                    :width="84"
                />
            </div>
            <button
                v-if="isAuthenticated"
                type="button"
                aria-label="Pridėti į krepšelį"
                class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-stone-900 text-white transition-colors hover:bg-[#15803d]"
                @click="handleAdd"
            >
                <Plus :size="16" :stroke-width="2.5" />
            </button>
        </div>
    </Link>
</template>
