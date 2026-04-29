<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Heart, Plus } from 'lucide-vue-next';
import { computed } from 'vue';
import DiscountBadge from '@/components/kainu/DiscountBadge.vue';
import ProductImage from '@/components/kainu/ProductImage.vue';
import StoreLogo from '@/components/kainu/StoreLogo.vue';
import { discountPct, formatPrice } from '@/lib/kainu';
import type { KainuProduct } from '@/types/kainu';

type Props = {
    product: KainuProduct;
    compact?: boolean;
    favorited?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    compact: false,
    favorited: false,
});

const emit = defineEmits<{
    (e: 'add-to-cart', product: KainuProduct): void;
    (e: 'toggle-favorite', product: KainuProduct): void;
}>();

const pct = computed(() =>
    discountPct(props.product.oldPrice, props.product.bestPrice),
);

const imageSize = computed(() => (props.compact ? 90 : 110));
const padding = computed(() => (props.compact ? 'p-3' : 'p-[14px]'));

const handleAdd = (event: MouseEvent) => {
    event.preventDefault();
    event.stopPropagation();
    emit('add-to-cart', props.product);
};

const handleFavorite = (event: MouseEvent) => {
    event.preventDefault();
    event.stopPropagation();
    emit('toggle-favorite', props.product);
};
</script>

<template>
    <Link
        :href="`/products/${product.id}`"
        class="group relative flex flex-col rounded-[12px] border border-[#e7e5e0] bg-white transition-[border-color,box-shadow,transform] duration-150 hover:border-[#15803d] hover:shadow-[0_2px_8px_rgba(21,128,61,0.08)]"
        :class="padding"
    >
        <div v-if="pct > 0" class="absolute top-[10px] left-[10px] z-[1]">
            <DiscountBadge :pct="pct" size="md" />
        </div>

        <button
            type="button"
            :aria-label="
                favorited ? 'Pašalinti iš mėgstamų' : 'Pridėti į mėgstamus'
            "
            class="absolute top-2 right-2 z-[1] flex h-7 w-7 items-center justify-center rounded-full border transition-colors"
            :class="
                favorited
                    ? 'border-red-200 bg-red-50 text-red-600'
                    : 'border-[#e7e5e0] bg-white/90 text-stone-500 hover:border-stone-300'
            "
            @click="handleFavorite"
        >
            <Heart
                :size="14"
                :stroke-width="2"
                :fill="favorited ? 'currentColor' : 'none'"
            />
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

        <div class="mb-2 flex items-baseline gap-[6px]">
            <span
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
