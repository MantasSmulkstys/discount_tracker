<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        v-bind="$attrs"
    >
        <path
            fill="#EA4335"
            d="M12.24 10.285v3.821h5.445c-.24 1.237-.96 2.285-2.04 2.989l3.299 2.56c1.92-1.772 3.024-4.381 3.024-7.488c0-.698-.062-1.371-.178-2.022z"
        />
        <path
            fill="#34A853"
            d="M12 22c2.7 0 4.964-.894 6.619-2.424l-3.299-2.56c-.916.615-2.085.979-3.32.979c-2.55 0-4.709-1.722-5.48-4.037H3.11v2.64A9.997 9.997 0 0 0 12 22"
        />
        <path
            fill="#4A90E2"
            d="M6.52 13.958A5.996 5.996 0 0 1 6.214 12c0-.68.117-1.338.306-1.958V7.402H3.11A9.997 9.997 0 0 0 2 12c0 1.611.386 3.136 1.11 4.598z"
        />
        <path
            fill="#FBBC05"
            d="M12 6.005c1.467 0 2.784.505 3.821 1.496l2.865-2.865C16.959 3.026 14.695 2 12 2A9.997 9.997 0 0 0 3.11 7.402l3.41 2.64c.77-2.315 2.93-4.037 5.48-4.037"
        />
    </svg>
</template>
