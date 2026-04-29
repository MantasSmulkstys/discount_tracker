<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    BadgePercent,
    ChevronLeft,
    ChevronRight,
    ShoppingBasket,
    Store,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';

type SummaryItem = {
    label: string;
    value: string;
};

type StoreFilter = {
    name: string;
    slug: string;
    count: number;
    active: boolean;
};

type Product = {
    id: number;
    name: string;
    store: string;
    price: string | null;
    oldPrice: string | null;
    discount: string | null;
    unitPrice: string | null;
    image: string | null;
    url: string | null;
    validDates: string | null;
    loyaltyCard: boolean;
    scrapedAt: string;
};

type ProductLink = {
    url: string | null;
    label: string;
    active: boolean;
};

defineProps<{
    summary: SummaryItem[];
    stores: StoreFilter[];
    currentStore: string;
    products: {
        data: Product[];
        meta: {
            from: number | null;
            to: number | null;
            total: number;
            currentPage: number;
            lastPage: number;
            hasPages: boolean;
        };
        links: ProductLink[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Deals',
                href: dashboard(),
            },
        ],
    },
});

const collectionSubtitle = computed(() => {
    return 'Inspired by grocery offer boards: dense, clear, and fast to scan.';
});
</script>

<template>
    <Head title="Deals" />

    <div
        class="min-h-full bg-[linear-gradient(180deg,#f2fbf5_0%,#f8fcf8_35%,#ffffff_100%)] p-4 md:p-6"
    >
        <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
            <section
                class="overflow-hidden rounded-[2rem] border border-emerald-200/70 bg-white shadow-[0_25px_80px_-40px_rgba(6,95,70,0.45)]"
            >
                <div
                    class="grid gap-8 p-6 lg:grid-cols-[minmax(0,1.25fr)_minmax(320px,0.75fr)] lg:p-8"
                >
                    <div class="flex flex-col gap-5">
                        <Badge
                            class="w-fit rounded-full bg-emerald-600 px-3 py-1 text-[11px] tracking-[0.24em] text-white uppercase hover:bg-emerald-600"
                        >
                            Weekly shelf picks
                        </Badge>

                        <div class="space-y-3">
                            <h1
                                class="max-w-3xl text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl"
                            >
                                Discounted products, arranged like a modern
                                grocery offer wall.
                            </h1>
                            <p
                                class="max-w-2xl text-sm leading-6 text-slate-600 sm:text-base"
                            >
                                {{ collectionSubtitle }}
                                Browse live offers from your database now, then
                                grow this into cart, statistics, and profile
                                workflows later.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <Button
                                class="rounded-full bg-emerald-600 px-5 text-white hover:bg-emerald-700"
                            >
                                Shop the feed
                            </Button>
                            <Button
                                variant="outline"
                                class="rounded-full border-emerald-200 px-5 text-emerald-800 hover:bg-emerald-50"
                            >
                                Compare stores
                            </Button>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-3">
                            <div
                                v-for="item in summary"
                                :key="item.label"
                                class="rounded-[1.5rem] border border-emerald-100 bg-emerald-50/60 p-4"
                            >
                                <p
                                    class="text-xs tracking-[0.2em] text-emerald-700 uppercase"
                                >
                                    {{ item.label }}
                                </p>
                                <p
                                    class="mt-3 text-3xl font-semibold text-slate-950"
                                >
                                    {{ item.value }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <Card
                        class="gap-4 rounded-[1.75rem] border-emerald-200 bg-[linear-gradient(145deg,#0f6d52_0%,#157f5e_60%,#1c8c69_100%)] py-0 text-white shadow-none"
                    >
                        <CardHeader class="gap-2 p-6 pb-0">
                            <CardDescription class="text-emerald-100">
                                Retail rhythm
                            </CardDescription>
                            <CardTitle class="text-2xl text-white">
                                {{ currentStore }}
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-4 p-6 pt-0">
                            <div
                                class="rounded-[1.5rem] bg-white/10 p-4 backdrop-blur"
                            >
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex size-11 items-center justify-center rounded-2xl bg-white/12"
                                    >
                                        <ShoppingBasket class="size-5" />
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium">
                                            Paginated live feed
                                        </p>
                                        <p class="text-sm text-emerald-100/90">
                                            {{ products.meta.total }} offers are
                                            available in the current board.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div
                                class="rounded-[1.5rem] bg-white/10 p-4 backdrop-blur"
                            >
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex size-11 items-center justify-center rounded-2xl bg-white/12"
                                    >
                                        <BadgePercent class="size-5" />
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium">
                                            Discount-first sorting
                                        </p>
                                        <p class="text-sm text-emerald-100/90">
                                            Strongest savings surface first,
                                            then the freshest scraped products.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div
                                class="rounded-[1.5rem] bg-white/10 p-4 backdrop-blur"
                            >
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex size-11 items-center justify-center rounded-2xl bg-white/12"
                                    >
                                        <Store class="size-5" />
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium">
                                            Store chips
                                        </p>
                                        <p class="text-sm text-emerald-100/90">
                                            Switch between stores without
                                            leaving the deals view.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-[280px_minmax(0,1fr)]">
                <Card
                    class="rounded-[1.75rem] border-emerald-100 bg-white/95 py-0 shadow-sm"
                >
                    <CardHeader class="gap-2 p-6">
                        <CardDescription
                            class="text-xs tracking-[0.18em] text-emerald-700 uppercase"
                        >
                            Stores
                        </CardDescription>
                        <CardTitle class="text-xl text-slate-950">
                            Pick a board
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3 p-6 pt-0">
                        <Link
                            :href="dashboard()"
                            class="flex items-center justify-between rounded-[1.25rem] border px-4 py-3 text-sm transition-colors"
                            :class="
                                currentStore === 'All stores'
                                    ? 'border-emerald-600 bg-emerald-600 text-white'
                                    : 'border-emerald-100 bg-emerald-50/40 text-slate-700 hover:bg-emerald-50'
                            "
                        >
                            <span class="font-medium">All stores</span>
                            <span class="text-xs opacity-80">
                                {{ products.meta.total }}
                            </span>
                        </Link>

                        <Link
                            v-for="store in stores"
                            :key="store.slug"
                            :href="dashboard({ query: { store: store.slug } })"
                            class="flex items-center justify-between rounded-[1.25rem] border px-4 py-3 text-sm transition-colors"
                            :class="
                                store.active
                                    ? 'border-emerald-600 bg-emerald-600 text-white'
                                    : 'border-emerald-100 bg-emerald-50/40 text-slate-700 hover:bg-emerald-50'
                            "
                        >
                            <span class="font-medium">{{ store.name }}</span>
                            <span class="text-xs opacity-80">
                                {{ store.count }}
                            </span>
                        </Link>
                    </CardContent>
                </Card>

                <div class="flex flex-col gap-5">
                    <div
                        class="flex flex-col gap-4 rounded-[1.75rem] border border-emerald-100 bg-white/95 p-5 shadow-sm md:flex-row md:items-center md:justify-between"
                    >
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                {{ currentStore }}
                            </p>
                            <p class="mt-1 text-sm text-slate-600">
                                Showing {{ products.meta.from ?? 0 }}-{{
                                    products.meta.to ?? 0
                                }}
                                of {{ products.meta.total }} offers.
                            </p>
                        </div>
                        <div
                            class="inline-flex items-center rounded-full bg-emerald-50 px-4 py-2 text-sm text-emerald-800"
                        >
                            Page {{ products.meta.currentPage }} of
                            {{ products.meta.lastPage }}
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <article
                            v-for="product in products.data"
                            :key="product.id"
                            class="group overflow-hidden rounded-[1.75rem] border border-emerald-100 bg-white shadow-sm transition-transform duration-200 hover:-translate-y-1"
                        >
                            <div
                                class="relative flex h-56 items-center justify-center overflow-hidden bg-[radial-gradient(circle_at_top,#dcfce7_0%,#f0fdf4_55%,#ffffff_100%)] p-6"
                            >
                                <img
                                    v-if="product.image"
                                    :src="product.image"
                                    :alt="product.name"
                                    class="h-full w-full object-contain transition-transform duration-300 group-hover:scale-105"
                                />
                                <div class="absolute top-4 left-4 flex gap-2">
                                    <span
                                        v-if="product.discount"
                                        class="rounded-full bg-red-500 px-3 py-1 text-xs font-semibold text-white shadow-sm"
                                    >
                                        {{ product.discount }}
                                    </span>
                                    <span
                                        v-if="product.loyaltyCard"
                                        class="rounded-full bg-emerald-600 px-3 py-1 text-xs font-semibold text-white shadow-sm"
                                    >
                                        Loyalty
                                    </span>
                                </div>
                                <div
                                    class="absolute bottom-4 left-4 rounded-full bg-white/90 px-3 py-1 text-xs font-medium text-slate-700 backdrop-blur"
                                >
                                    {{ product.store }}
                                </div>
                            </div>

                            <div class="flex flex-col gap-4 p-5">
                                <div class="space-y-2">
                                    <h2
                                        class="line-clamp-2 min-h-12 text-lg font-semibold text-slate-950"
                                    >
                                        {{ product.name }}
                                    </h2>
                                    <p
                                        v-if="product.validDates"
                                        class="text-xs tracking-[0.16em] text-emerald-700 uppercase"
                                    >
                                        {{ product.validDates }}
                                    </p>
                                </div>

                                <div
                                    class="flex items-end justify-between gap-4"
                                >
                                    <div>
                                        <p
                                            class="text-3xl font-semibold text-slate-950"
                                        >
                                            {{ product.price ?? 'Check store' }}
                                        </p>
                                        <p
                                            v-if="product.oldPrice"
                                            class="text-sm text-slate-400 line-through"
                                        >
                                            {{ product.oldPrice }}
                                        </p>
                                    </div>
                                    <p
                                        v-if="product.unitPrice"
                                        class="max-w-28 text-right text-xs leading-5 text-slate-500"
                                    >
                                        {{ product.unitPrice }}
                                    </p>
                                </div>

                                <a
                                    v-if="product.url"
                                    :href="product.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-2 text-sm font-medium text-emerald-700 transition-colors hover:text-emerald-900"
                                >
                                    View product
                                    <ArrowRight class="size-4" />
                                </a>
                            </div>
                        </article>
                    </div>

                    <div
                        v-if="products.meta.hasPages"
                        class="flex flex-wrap items-center justify-center gap-2 rounded-[1.75rem] border border-emerald-100 bg-white/95 p-4 shadow-sm"
                    >
                        <template
                            v-for="link in products.links"
                            :key="`${link.label}-${link.url}`"
                        >
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                class="inline-flex min-w-10 items-center justify-center rounded-full border px-4 py-2 text-sm font-medium transition-colors"
                                :class="
                                    link.active
                                        ? 'border-emerald-600 bg-emerald-600 text-white'
                                        : 'border-emerald-100 bg-white text-slate-700 hover:bg-emerald-50'
                                "
                            >
                                <ChevronLeft
                                    v-if="link.label === 'Previous'"
                                    class="size-4"
                                />
                                <ChevronRight
                                    v-else-if="link.label === 'Next'"
                                    class="size-4"
                                />
                                <span v-else>{{ link.label }}</span>
                            </Link>
                            <span
                                v-else
                                class="inline-flex min-w-10 items-center justify-center rounded-full border border-slate-200 bg-slate-100 px-4 py-2 text-sm font-medium text-slate-400"
                            >
                                <ChevronLeft
                                    v-if="link.label === 'Previous'"
                                    class="size-4"
                                />
                                <ChevronRight
                                    v-else-if="link.label === 'Next'"
                                    class="size-4"
                                />
                                <span v-else>{{ link.label }}</span>
                            </span>
                        </template>
                    </div>

                    <div
                        v-else
                        class="rounded-[1.75rem] border border-dashed border-emerald-200 bg-white/80 p-8 text-center text-slate-500"
                    >
                        No more pages in this selection yet.
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
