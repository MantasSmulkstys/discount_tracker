<script setup lang="ts">
import { computed } from 'vue';

type Props = {
    values: number[];
    width?: number;
    height?: number;
    color?: string;
};

const props = withDefaults(defineProps<Props>(), {
    width: 60,
    height: 20,
    color: '#15803d',
});

const points = computed(() => {
    if (!props.values.length) {
        return '';
    }

    const min = Math.min(...props.values);
    const max = Math.max(...props.values);
    const range = max - min || 1;

    return props.values
        .map((v, i) => {
            const x = (i / (props.values.length - 1 || 1)) * props.width;
            const y =
                props.height - ((v - min) / range) * (props.height - 2) - 1;

            return `${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');
});
</script>

<template>
    <svg v-if="values.length" :width="width" :height="height" class="block">
        <polyline
            :points="points"
            fill="none"
            :stroke="color"
            stroke-width="1.5"
            stroke-linejoin="round"
            stroke-linecap="round"
        />
    </svg>
</template>
