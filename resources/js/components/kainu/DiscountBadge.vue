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
            return 'text-[10px] py-[2px] px-[5px] rounded-[4px]';
        case 'lg':
            return 'text-[13px] py-[4px] px-[8px] rounded-[6px]';
        default:
            return 'text-[11px] py-[3px] px-[6px] rounded-[5px]';
    }
});
</script>

<template>
    <span
        v-if="pct > 0"
        class="inline-block leading-none font-bold tracking-tight text-white"
        style="background: #15803d"
        :class="classes"
    >
        −{{ pct }}%
    </span>
</template>
