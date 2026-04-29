<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Beef,
    Croissant,
    GlassWater,
    LayoutGrid,
    Leaf,
    Milk,
    Package,
    Snowflake,
    Sparkles,
} from 'lucide-vue-next';
import { computed } from 'vue';
import type { Component } from 'vue';
import { CATEGORIES } from '@/lib/kainu';
import type { CategoryId } from '@/lib/kainu';

type Props = {
    active?: CategoryId | string;
};

withDefaults(defineProps<Props>(), {
    active: 'all',
});

const iconMap: Record<string, Component> = {
    LayoutGrid,
    Leaf,
    Milk,
    Beef,
    Croissant,
    Package,
    Snowflake,
    GlassWater,
    Sparkles,
};

const items = computed(() =>
    CATEGORIES.map((c) => ({
        ...c,
        component: iconMap[c.icon] ?? LayoutGrid,
    })),
);

function hrefFor(id: string): string {
    if (id === 'all') {
        return '/products';
    }

    return `/products?category=${id}`;
}
</script>

<template>
    <div class="kainu-scrollbar-hide -mx-6 overflow-x-auto px-6">
        <div class="flex min-w-max gap-2 pb-1">
            <Link
                v-for="c in items"
                :key="c.id"
                :href="hrefFor(c.id)"
                class="inline-flex shrink-0 items-center gap-2 rounded-full border px-4 py-[10px] text-[13px] font-medium whitespace-nowrap transition-colors"
                :class="
                    active === c.id
                        ? 'border-stone-900 bg-stone-900 text-white'
                        : 'border-[#e7e5e0] bg-white text-stone-700 hover:border-stone-300 hover:bg-stone-50'
                "
            >
                <component :is="c.component" :size="16" :stroke-width="1.75" />
                {{ c.label }}
            </Link>
        </div>
    </div>
</template>
