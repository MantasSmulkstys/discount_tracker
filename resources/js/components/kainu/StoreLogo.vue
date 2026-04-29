<script setup lang="ts">
import { computed } from 'vue';
import { resolveStore } from '@/lib/kainu';

type Props = {
    storeId: string | null | undefined;
    size?: number;
    width?: number;
    showName?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    size: 24,
    width: undefined,
    showName: false,
});

const store = computed(() => resolveStore(props.storeId));
const hasLogo = computed(() => Boolean(store.value.logoPath));

const badgeStyle = computed(() => ({
    width: `${props.width ?? props.size}px`,
    height: `${props.size}px`,
    fontSize: `${Math.round(props.size * 0.45)}px`,
}));

const fallbackStyle = computed(() => ({
    ...badgeStyle.value,
    background: store.value.color,
}));
</script>

<template>
    <div class="inline-flex items-center gap-2">
        <div
            :class="
                hasLogo
                    ? 'flex shrink-0 items-center justify-center overflow-hidden rounded-[8px] border border-[#e7e5e0] bg-white p-[2px]'
                    : 'flex shrink-0 items-center justify-center rounded-full font-bold text-white shadow-[inset_0_0_0_1px_rgba(255,255,255,0.15)]'
            "
            :style="hasLogo ? badgeStyle : fallbackStyle"
        >
            <img
                v-if="hasLogo"
                :src="store.logoPath ?? undefined"
                :alt="`${store.name} logo`"
                class="h-full w-full object-contain"
            />
            <template v-else>
                {{ store.short }}
            </template>
        </div>
        <span v-if="showName" class="text-[13px] font-medium text-stone-800">
            {{ store.name }}
        </span>
    </div>
</template>
