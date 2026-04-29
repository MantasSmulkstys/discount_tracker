<script setup lang="ts">
import { computed } from 'vue';
import { emojiForProduct, pastelTintFor } from '@/lib/kainu';

type Props = {
    src?: string | null;
    seed: string | number;
    name?: string | null;
    emoji?: string | null;
    size?: number;
    rounded?: number;
};

const props = withDefaults(defineProps<Props>(), {
    src: null,
    name: '',
    emoji: null,
    size: 120,
    rounded: 0,
});

const tint = computed(() => pastelTintFor(props.seed));
const radius = computed(() =>
    props.rounded > 0 ? props.rounded : Math.max(8, props.size * 0.08),
);
const displayEmoji = computed(
    () => props.emoji || emojiForProduct(props.name || String(props.seed)),
);

const boxStyle = computed(() => ({
    width: `${props.size}px`,
    height: `${props.size}px`,
    background: tint.value,
    borderRadius: `${radius.value}px`,
}));

const emojiStyle = computed(() => ({
    fontSize: `${Math.round(props.size * 0.5)}px`,
}));
</script>

<template>
    <div
        class="relative flex shrink-0 items-center justify-center overflow-hidden"
        :style="boxStyle"
    >
        <img
            v-if="src"
            :src="src"
            :alt="name ?? ''"
            class="h-full w-full object-contain"
        />
        <span
            v-else
            class="leading-none drop-shadow-[0_2px_4px_rgba(0,0,0,0.08)]"
            :style="emojiStyle"
        >
            {{ displayEmoji }}
        </span>
    </div>
</template>
