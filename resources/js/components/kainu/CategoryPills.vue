<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { LayoutGrid } from 'lucide-vue-next';
import { computed } from 'vue';
import type { CategoryTreeNode } from '@/lib/kainu';

type Props = {
    tree: CategoryTreeNode[];
    activeCategory?: string;
    activeSubcategory?: string;
};

const props = withDefaults(defineProps<Props>(), {
    activeCategory: 'all',
    activeSubcategory: 'all',
});

function hrefFor(categorySlug: string, subcategorySlug?: string): string {
    if (categorySlug === 'all') {
        return '/products';
    }

    const params = new URLSearchParams({ category: categorySlug });

    if (subcategorySlug && subcategorySlug !== 'all') {
        params.set('subcategory', subcategorySlug);
    }

    return `/products?${params.toString()}`;
}

const activeNode = computed(
    () => props.tree.find((n) => n.slug === props.activeCategory) ?? null,
);
</script>

<template>
    <div>
        <div class="kainu-scrollbar-hide -mx-6 overflow-x-auto px-6">
            <div class="flex min-w-max gap-2 pb-1">
                <Link
                    :href="hrefFor('all')"
                    class="inline-flex shrink-0 items-center gap-2 rounded-full border px-4 py-[10px] text-[13px] font-medium whitespace-nowrap transition-colors"
                    :class="
                        activeCategory === 'all'
                            ? 'border-stone-900 bg-stone-900 text-white'
                            : 'border-[#e7e5e0] bg-white text-stone-700 hover:border-stone-300 hover:bg-stone-50'
                    "
                >
                    <LayoutGrid :size="16" :stroke-width="1.75" />
                    Visos kategorijos
                </Link>

                <Link
                    v-for="c in tree"
                    :key="c.slug"
                    :href="hrefFor(c.slug)"
                    class="inline-flex shrink-0 items-center gap-2 rounded-full border px-4 py-[10px] text-[13px] font-medium whitespace-nowrap transition-colors"
                    :class="
                        activeCategory === c.slug
                            ? 'border-stone-900 bg-stone-900 text-white'
                            : 'border-[#e7e5e0] bg-white text-stone-700 hover:border-stone-300 hover:bg-stone-50'
                    "
                >
                    {{ c.name }}
                </Link>
            </div>
        </div>

        <div
            v-if="activeNode && activeNode.subcategories.length > 0"
            class="kainu-scrollbar-hide -mx-6 mt-2 overflow-x-auto px-6"
        >
            <div class="flex min-w-max gap-2 pb-1">
                <Link
                    :href="hrefFor(activeNode.slug, 'all')"
                    class="inline-flex shrink-0 items-center rounded-full border px-3 py-[6px] text-[12px] font-medium whitespace-nowrap transition-colors"
                    :class="
                        activeSubcategory === 'all'
                            ? 'border-[#15803d] bg-brand-50 text-[#15803d]'
                            : 'border-[#e7e5e0] bg-white text-stone-600 hover:bg-stone-50'
                    "
                >
                    Visos
                </Link>

                <Link
                    v-for="s in activeNode.subcategories"
                    :key="s.slug"
                    :href="hrefFor(activeNode.slug, s.slug)"
                    class="inline-flex shrink-0 items-center rounded-full border px-3 py-[6px] text-[12px] font-medium whitespace-nowrap transition-colors"
                    :class="
                        activeSubcategory === s.slug
                            ? 'border-[#15803d] bg-brand-50 text-[#15803d]'
                            : 'border-[#e7e5e0] bg-white text-stone-600 hover:bg-stone-50'
                    "
                >
                    {{ s.name }}
                </Link>
            </div>
        </div>
    </div>
</template>
