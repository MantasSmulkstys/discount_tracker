<script setup lang="ts">
import { computed } from 'vue';

type Props = {
    pct: number;
    size?: 'sm' | 'md' | 'lg';
};

const props = withDefaults(defineProps<Props>(), {
    size: 'md',
});

const classes = computed(() => {
    switch (props.size) {
        case 'sm':
            return 'text-[10px] py-[3px] px-[6px] rounded-[4px]';
        case 'lg':
            return 'text-[13px] py-[5px] px-[10px] rounded-[6px]';
        default:
            return 'text-[11px] py-[4px] px-[8px] rounded-[5px]';
    }
});
</script>

<template>
    <span
        v-if="pct > 0"
        class="inline-block bg-[#15803d] font-bold tracking-tight whitespace-nowrap text-white shadow-[0_2px_4px_rgba(21,128,61,0.25)]"
        :class="classes"
    >
        −{{ pct }}%
    </span>
</template>
