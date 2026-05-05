<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();

const logoSrc = '/app-logo.png';
</script>

<template>
    <img
        :src="logoSrc"
        alt=""
        :class="['object-contain', className]"
        v-bind="$attrs"
    />
</template>
