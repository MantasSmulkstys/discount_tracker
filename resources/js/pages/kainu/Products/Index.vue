<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { store as reportStore, destroy as reportDestroy } from '@/actions/App/Http/Controllers/ProductReportController';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    LayoutGrid,
    Search,
    X,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import CategoryPills from '@/components/kainu/CategoryPills.vue';
import ProductCard from '@/components/kainu/ProductCard.vue';
import StoreLogo from '@/components/kainu/StoreLogo.vue';
import { useKainuCart } from '@/composables/useKainuCart';
import { resolveStore } from '@/lib/kainu';
import type { CategoryTreeNode } from '@/lib/kainu';
import type {
    KainuPageLink,
    KainuPagination,
    KainuProduct,
    KainuStoreCount,
} from '@/types/kainu';

type Filters = {
    category: string;
    subcategory: string;
    q: string;
    stores: string[];
    discount: boolean;
    maxPrice: number | null;
    sort: string;
};

const props = defineProps<{
    products: {
        data: KainuProduct[];
        meta: KainuPagination;
        links: KainuPageLink[];
    };
    allStores: KainuStoreCount[];
    categoryTree: CategoryTreeNode[];
    filters: Filters;
    reportedIds: number[];
}>();

const { addToCart, openDrawer } = useKainuCart();

const activeCategoryNode = computed(() =>
    props.categoryTree.find((n) => n.slug === props.filters.category) ?? null,
);

const activeSubcategoryNode = computed(() => {
    if (!activeCategoryNode.value || props.filters.subcategory === 'all') {
        return null;
    }
    return (
        activeCategoryNode.value.subcategories.find(
            (s) => s.slug === props.filters.subcategory,
        ) ?? null
    );
});

const categoryLabel = computed(() => {
    if (activeSubcategoryNode.value) {
        return activeSubcategoryNode.value.name;
    }
    if (activeCategoryNode.value) {
        return activeCategoryNode.value.name;
    }
    return 'Visi pasiūlymai';
});

const priceMax = ref<number>(props.filters.maxPrice ?? 20);
const discountOnly = ref<boolean>(props.filters.discount);
const searchInput = ref<string>(props.filters.q ?? '');

watch(
    () => props.filters.maxPrice,
    (next) => {
        priceMax.value = next ?? 20;
    },
);

watch(
    () => props.filters.discount,
    (next) => {
        discountOnly.value = next;
    },
);

watch(
    () => props.filters.q,
    (next) => {
        searchInput.value = next ?? '';
    },
);

let searchDebounce: ReturnType<typeof setTimeout> | null = null;
function onSearchInput() {
    if (searchDebounce) {
        clearTimeout(searchDebounce);
    }
    searchDebounce = setTimeout(() => {
        if (searchInput.value !== (props.filters.q ?? '')) {
            updateFilters({ q: searchInput.value.trim() });
        }
    }, 300);
}

function clearSearch() {
    if (searchDebounce) {
        clearTimeout(searchDebounce);
    }
    searchInput.value = '';
    if (props.filters.q) {
        updateFilters({ q: '' });
    }
}

function updateFilters(
    patch: Partial<Filters>,
    extra: Record<string, string | number> = {},
) {
    const current = { ...props.filters, ...patch };

    const query: Record<string, string | number> = {
        ...extra,
    };

    if (current.category && current.category !== 'all') {
        query.category = current.category;

        if (current.subcategory && current.subcategory !== 'all') {
            query.subcategory = current.subcategory;
        }
    }

    if (current.q) {
        query.q = current.q;
    }

    if (current.stores.length) {
        query.stores = current.stores.join(',');
    }

    if (current.discount) {
        query.discount = 1;
    }

    if (current.maxPrice && current.maxPrice > 0) {
        query.max_price = current.maxPrice;
    }

    if (current.sort && current.sort !== 'discount') {
        query.sort = current.sort;
    }

    router.get('/products', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function toggleStore(storeId: string) {
    const next = props.filters.stores.includes(storeId)
        ? props.filters.stores.filter((s) => s !== storeId)
        : [...props.filters.stores, storeId];
    updateFilters({ stores: next });
}

function setCategory(categorySlug: string) {
    // Switching category clears any subcategory selection — different
    // categories don't share subcategories.
    updateFilters({ category: categorySlug, subcategory: 'all' });
}

function setSubcategory(subcategorySlug: string) {
    updateFilters({ subcategory: subcategorySlug });
}

function setSort(event: Event) {
    const target = event.target as HTMLSelectElement;
    updateFilters({ sort: target.value });
}

let priceDebounce: ReturnType<typeof setTimeout> | null = null;
function onPriceInput(event: Event) {
    const target = event.target as HTMLInputElement;
    priceMax.value = parseFloat(target.value);

    if (priceDebounce) {
        clearTimeout(priceDebounce);
    }

    priceDebounce = setTimeout(() => {
        updateFilters({ maxPrice: priceMax.value });
    }, 250);
}

function toggleDiscountOnly() {
    discountOnly.value = !discountOnly.value;
    updateFilters({ discount: discountOnly.value });
}

function clearAll() {
    router.get('/products', {}, { preserveScroll: true, replace: true });
}

function handleAdd(product: KainuProduct) {
    addToCart(product);
    openDrawer();
}

const page = usePage();
const isLoggedIn = () => !!page.props.auth?.user;

function handleToggleReport(product: KainuProduct) {
    if (!isLoggedIn()) {
        router.visit('/login');
        return;
    }
    const isReported = props.reportedIds.includes(product.normalizedProductId);
    if (isReported) {
        router.delete(reportDestroy(product.normalizedProductId).url, {
            preserveScroll: true,
            preserveState: true,
        });
    } else {
        router.post(reportStore(product.normalizedProductId).url, {}, {
            preserveScroll: true,
            preserveState: true,
        });
    }
}

function storeMeta(storeId: string) {
    return resolveStore(storeId);
}
</script>

<template>
    <Head :title="categoryLabel" />

    <div class="pt-5 pb-10">
        <div
            class="mb-1 flex items-baseline gap-[10px] text-[13px] text-stone-500"
        >
            <Link href="/" class="hover:text-stone-700">Pradžia</Link>
            <template v-if="activeCategoryNode">
                <ChevronRight :size="12" />
                <Link
                    :href="`/products?category=${activeCategoryNode.slug}`"
                    class="hover:text-stone-700"
                    :class="{
                        'font-medium text-stone-900':
                            !activeSubcategoryNode,
                    }"
                >
                    {{ activeCategoryNode.name }}
                </Link>
            </template>
            <template v-if="activeSubcategoryNode">
                <ChevronRight :size="12" />
                <span class="font-medium text-stone-900">
                    {{ activeSubcategoryNode.name }}
                </span>
            </template>
        </div>
        <h1
            class="m-0 mt-1 mb-5 text-[30px] font-bold tracking-tighter text-stone-900"
        >
            {{ categoryLabel }}
            <span class="ml-3 text-[16px] font-medium text-stone-500">
                {{ products.meta.total.toLocaleString('lt-LT') }} prekės
            </span>
        </h1>

        <div
            class="mb-5 flex items-center gap-2 rounded-[12px] border border-[#e7e5e0] bg-white px-3 py-2 focus-within:border-stone-300"
        >
            <Search :size="16" :stroke-width="1.75" class="text-stone-400" />
            <input
                v-model="searchInput"
                type="search"
                inputmode="search"
                autocomplete="off"
                placeholder="Ieškoti prekės, prekės ženklo arba aprašymo…"
                class="flex-1 bg-transparent text-[14px] text-stone-900 placeholder:text-stone-400 focus:outline-none"
                @input="onSearchInput"
                @keydown.enter.prevent="onSearchInput"
            />
            <button
                v-if="searchInput"
                type="button"
                aria-label="Išvalyti paiešką"
                class="flex h-6 w-6 items-center justify-center rounded-full text-stone-500 hover:bg-stone-100"
                @click="clearSearch"
            >
                <X :size="14" :stroke-width="2" />
            </button>
        </div>

        <div class="grid gap-7 lg:grid-cols-[240px_1fr]">
            <aside class="hidden lg:block">
                <div class="space-y-6">
                    <div>
                        <h3
                            class="mb-[10px] text-[12px] font-bold tracking-[0.06em] text-stone-500 uppercase"
                        >
                            Kategorijos
                        </h3>
                        <div class="flex flex-col gap-[1px]">
                            <button
                                type="button"
                                class="flex items-center gap-[10px] rounded-[8px] px-2 py-[7px] text-left text-[13px] transition-colors"
                                :class="
                                    filters.category === 'all'
                                        ? 'bg-brand-50 font-semibold text-[#15803d]'
                                        : 'text-stone-700 hover:bg-stone-100'
                                "
                                @click="setCategory('all')"
                            >
                                <LayoutGrid :size="16" :stroke-width="1.75" />
                                <span class="flex-1">Visos kategorijos</span>
                            </button>

                            <template v-for="c in categoryTree" :key="c.slug">
                                <button
                                    type="button"
                                    class="flex items-center gap-[10px] rounded-[8px] px-2 py-[7px] text-left text-[13px] transition-colors"
                                    :class="
                                        c.slug === filters.category
                                            ? 'bg-brand-50 font-semibold text-[#15803d]'
                                            : 'text-stone-700 hover:bg-stone-100'
                                    "
                                    @click="setCategory(c.slug)"
                                >
                                    <span class="flex-1">{{ c.name }}</span>
                                </button>

                                <!-- Subcategories indent under the active category. -->
                                <div
                                    v-if="
                                        c.slug === filters.category &&
                                        c.subcategories.length > 0
                                    "
                                    class="ml-3 flex flex-col gap-[1px] border-l-2 border-[#e7e5e0] pl-3"
                                >
                                    <button
                                        type="button"
                                        class="rounded-[6px] px-2 py-[5px] text-left text-[12px] transition-colors"
                                        :class="
                                            filters.subcategory === 'all'
                                                ? 'font-semibold text-[#15803d]'
                                                : 'text-stone-600 hover:bg-stone-100'
                                        "
                                        @click="setSubcategory('all')"
                                    >
                                        Visos
                                    </button>
                                    <button
                                        v-for="s in c.subcategories"
                                        :key="s.slug"
                                        type="button"
                                        class="rounded-[6px] px-2 py-[5px] text-left text-[12px] transition-colors"
                                        :class="
                                            s.slug === filters.subcategory
                                                ? 'font-semibold text-[#15803d]'
                                                : 'text-stone-600 hover:bg-stone-100'
                                        "
                                        @click="setSubcategory(s.slug)"
                                    >
                                        {{ s.name }}
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div>
                        <h3
                            class="mb-[10px] text-[12px] font-bold tracking-[0.06em] text-stone-500 uppercase"
                        >
                            Parduotuvė
                        </h3>
                        <div class="flex flex-col gap-[2px]">
                            <button
                                v-for="s in allStores"
                                :key="s.id"
                                type="button"
                                class="flex items-center gap-[10px] rounded-[8px] px-2 py-[7px] text-left text-[13px] transition-colors"
                                :class="
                                    filters.stores.includes(s.id)
                                        ? 'bg-brand-50 text-stone-900'
                                        : 'text-stone-700 hover:bg-stone-100'
                                "
                                @click="toggleStore(s.id)"
                            >
                                <span
                                    class="flex h-4 w-4 shrink-0 items-center justify-center rounded-[4px] border-[1.5px]"
                                    :class="
                                        filters.stores.includes(s.id)
                                            ? 'border-[#15803d] bg-[#15803d]'
                                            : 'border-stone-300 bg-white'
                                    "
                                >
                                    <Check
                                        v-if="filters.stores.includes(s.id)"
                                        :size="10"
                                        :stroke-width="3"
                                        class="text-white"
                                    />
                                </span>
                                <StoreLogo :store-id="s.id" :size="18" />
                                <span class="flex-1">{{ s.name }}</span>
                                <span class="text-[11px] text-stone-400">
                                    {{ s.count }}
                                </span>
                            </button>
                        </div>
                    </div>

                    <div>
                        <h3
                            class="mb-[10px] text-[12px] font-bold tracking-[0.06em] text-stone-500 uppercase"
                        >
                            Kaina
                        </h3>
                        <div class="px-2">
                            <div class="mb-2 text-[13px] text-stone-700">
                                0 € –
                                <strong>
                                    {{ priceMax.toFixed(2).replace('.', ',') }}
                                    €
                                </strong>
                            </div>
                            <input
                                type="range"
                                min="1"
                                max="50"
                                step="0.5"
                                :value="priceMax"
                                class="w-full accent-[#15803d]"
                                @input="onPriceInput"
                            />
                        </div>
                    </div>

                    <div>
                        <button
                            type="button"
                            class="flex w-full items-center gap-[10px] rounded-[8px] px-2 py-[7px] text-[13px] text-stone-900"
                            @click="toggleDiscountOnly"
                        >
                            <span
                                class="relative h-[18px] w-8 shrink-0 rounded-full transition-colors"
                                :class="
                                    discountOnly
                                        ? 'bg-[#15803d]'
                                        : 'bg-stone-300'
                                "
                            >
                                <span
                                    class="absolute top-[2px] h-[14px] w-[14px] rounded-full bg-white shadow transition-all"
                                    :style="{
                                        left: discountOnly ? '16px' : '2px',
                                    }"
                                />
                            </span>
                            <span class="font-medium">Tik su nuolaida</span>
                        </button>
                    </div>

                    <button
                        v-if="
                            filters.category !== 'all' ||
                            filters.stores.length > 0 ||
                            filters.discount ||
                            filters.q ||
                            filters.maxPrice
                        "
                        type="button"
                        class="w-full rounded-[8px] border border-[#e7e5e0] bg-white px-3 py-2 text-[12px] font-medium text-stone-700 transition-colors hover:bg-stone-50"
                        @click="clearAll"
                    >
                        Išvalyti filtrus
                    </button>
                </div>
            </aside>

            <div>
                <div
                    class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-[12px] border border-[#e7e5e0] bg-white px-[14px] py-[10px]"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            v-if="filters.stores.length === 0"
                            class="text-[13px] text-stone-500"
                        >
                            Visos parduotuvės
                        </span>
                        <span
                            v-for="s in filters.stores"
                            :key="s"
                            class="inline-flex items-center gap-[6px] rounded-full bg-brand-50 px-2 py-1 text-[12px] font-medium text-[#15803d]"
                        >
                            {{ storeMeta(s).name }}
                            <button
                                type="button"
                                aria-label="Pašalinti filtrą"
                                class="flex text-[#15803d]"
                                @click="toggleStore(s)"
                            >
                                <X :size="12" :stroke-width="2.5" />
                            </button>
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="text-[12px] text-stone-500"
                            >Rikiuoti:</span
                        >
                        <select
                            :value="filters.sort"
                            class="cursor-pointer rounded-[8px] border border-[#e7e5e0] bg-[#fafaf7] px-[10px] py-[6px] text-[13px] font-medium text-stone-900 outline-none"
                            @change="setSort"
                        >
                            <option value="discount">
                                Didžiausia nuolaida
                            </option>
                            <option value="price_asc">
                                Kaina: nuo mažiausios
                            </option>
                            <option value="price_desc">
                                Kaina: nuo didžiausios
                            </option>
                            <option value="newest">Naujausi</option>
                        </select>
                    </div>
                </div>

                <div
                    v-if="products.data.length === 0"
                    class="rounded-[12px] border border-[#e7e5e0] bg-white p-16 text-center text-stone-500"
                >
                    <div class="mb-2 text-[32px]">🫤</div>
                    <div class="mb-1 font-medium text-stone-900">
                        Nieko neradome
                    </div>
                    <div class="text-[13px]">
                        Pabandykite keisti filtrus arba kategoriją
                    </div>
                    <button
                        type="button"
                        class="mt-4 inline-flex items-center justify-center rounded-[8px] bg-stone-900 px-4 py-2 text-[13px] font-semibold text-white hover:bg-[#15803d]"
                        @click="clearAll"
                    >
                        Išvalyti filtrus
                    </button>
                </div>

                <div
                    v-else
                    class="grid grid-cols-2 gap-[14px] sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4"
                >
                    <ProductCard
                        v-for="p in products.data"
                        :key="p.id"
                        :product="p"
                        :reported="reportedIds.includes(p.normalizedProductId)"
                        @add-to-cart="handleAdd"
                        @toggle-report="handleToggleReport"
                    />
                </div>

                <div
                    v-if="products.meta.hasPages"
                    class="mt-6 flex flex-wrap items-center justify-center gap-2"
                >
                    <template
                        v-for="(link, idx) in products.links"
                        :key="`${idx}-${link.label}`"
                    >
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="inline-flex min-w-10 items-center justify-center rounded-full border px-4 py-2 text-[13px] font-medium transition-colors"
                            :class="
                                link.active
                                    ? 'border-[#15803d] bg-[#15803d] text-white'
                                    : 'border-[#e7e5e0] bg-white text-stone-700 hover:bg-stone-50'
                            "
                        >
                            <ChevronLeft
                                v-if="link.label === 'Previous'"
                                :size="14"
                            />
                            <ChevronRight
                                v-else-if="link.label === 'Next'"
                                :size="14"
                            />
                            <span v-else>{{ link.label }}</span>
                        </Link>
                        <span
                            v-else
                            class="inline-flex min-w-10 items-center justify-center rounded-full border border-stone-200 bg-stone-100 px-4 py-2 text-[13px] text-stone-400"
                        >
                            <ChevronLeft
                                v-if="link.label === 'Previous'"
                                :size="14"
                            />
                            <ChevronRight
                                v-else-if="link.label === 'Next'"
                                :size="14"
                            />
                            <span v-else>{{ link.label }}</span>
                        </span>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
