<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowDownRight,
    ArrowUpRight,
    Package2,
    Store,
    TrendingDown,
    TrendingUp,
} from 'lucide-vue-next';
import { computed } from 'vue';
import StoreLogo from '@/components/kainu/StoreLogo.vue';
import KainuLayout from '@/Layouts/KainuLayout.vue';

defineOptions({ layout: KainuLayout });

type BiggestChange = {
    id: number;
    snapshotId: number | null;
    productName: string;
    brand: string | null;
    variant: string | null;
    image: string | null;
    previousPrice: number | null;
    newPrice: number;
    changePct: number;
    weekStarting: string;
    storeName: string;
    storeSlug: string;
};

type Summary = {
    totalProducts: number;
    totalStores: number;
    totalSnapshots: number;
    totalChanges: number;
    totalDrops: number;
    totalRaises: number;
};

const props = defineProps<{
    biggestChanges: BiggestChange[];
    summary: Summary;
}>();

const formatPrice = (value: number | null) =>
    value === null ? '—' : `${value.toFixed(2).replace('.', ',')} €`;

const formatPct = (value: number) => {
    const rounded = Math.round(value);
    return `${rounded > 0 ? '+' : ''}${rounded}%`;
};

const hasChanges = computed(() => props.biggestChanges.length > 0);
</script>

<template>
    <Head title="Statistika · Kainų seklys" />

    <section class="pt-8 pb-2">
        <h1 class="m-0 text-[28px] font-bold tracking-tight text-stone-900">
            Statistika
        </h1>
        <p class="mt-2 mb-0 max-w-[640px] text-[14px] text-stone-600">
            Kaip kainos kinta savaitę po savaitės. Šis puslapis surenka kainų
            pokyčius tarp skirtingų savaičių, tad didžiausi pokyčiai matomi
            tik tada, kai surinkta bent dviejų savaičių duomenų.
        </p>
    </section>

    <section class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-[12px] border border-[#e7e5e0] bg-white p-4">
            <div class="flex items-center gap-2 text-[11px] text-stone-500">
                <Package2 :size="14" :stroke-width="1.75" />
                <span class="uppercase tracking-[0.04em]">Produktai</span>
            </div>
            <div class="mt-1 text-[22px] font-bold tracking-tight text-stone-900">
                {{ summary.totalProducts.toLocaleString('lt-LT') }}
            </div>
        </div>

        <div class="rounded-[12px] border border-[#e7e5e0] bg-white p-4">
            <div class="flex items-center gap-2 text-[11px] text-stone-500">
                <Store :size="14" :stroke-width="1.75" />
                <span class="uppercase tracking-[0.04em]">Parduotuvės</span>
            </div>
            <div class="mt-1 text-[22px] font-bold tracking-tight text-stone-900">
                {{ summary.totalStores }}
            </div>
        </div>

        <div class="rounded-[12px] border border-[#e7e5e0] bg-white p-4">
            <div class="flex items-center gap-2 text-[11px] text-stone-500">
                <TrendingDown :size="14" :stroke-width="1.75" />
                <span class="uppercase tracking-[0.04em]">Atpigimai</span>
            </div>
            <div class="mt-1 text-[22px] font-bold tracking-tight text-[#15803d]">
                {{ summary.totalDrops.toLocaleString('lt-LT') }}
            </div>
        </div>

        <div class="rounded-[12px] border border-[#e7e5e0] bg-white p-4">
            <div class="flex items-center gap-2 text-[11px] text-stone-500">
                <TrendingUp :size="14" :stroke-width="1.75" />
                <span class="uppercase tracking-[0.04em]">Pabrangimai</span>
            </div>
            <div class="mt-1 text-[22px] font-bold tracking-tight text-red-600">
                {{ summary.totalRaises.toLocaleString('lt-LT') }}
            </div>
        </div>
    </section>

    <section class="mt-10">
        <div class="flex items-baseline justify-between gap-4">
            <h2 class="m-0 text-[20px] font-bold tracking-tight text-stone-900">
                Didžiausi kainų pokyčiai
            </h2>
            <span class="text-[12px] text-stone-500">
                Palyginimas su praėjusia savaite
            </span>
        </div>

        <div
            v-if="!hasChanges"
            class="mt-4 rounded-[12px] border border-dashed border-[#e7e5e0] bg-white p-8 text-center"
        >
            <TrendingDown
                :size="28"
                :stroke-width="1.5"
                class="mx-auto mb-3 text-stone-400"
            />
            <p class="m-0 text-[14px] font-medium text-stone-700">
                Dar nėra kainų pokyčių duomenų
            </p>
            <p class="mx-auto mt-1 mb-0 max-w-[420px] text-[12px] text-stone-500">
                Kainų pokyčiai bus rodomi po antrojo skenavimo (kitą pirmadienį),
                kai bus su kuo palyginti šios savaitės kainas.
            </p>
        </div>

        <div
            v-else
            class="mt-4 overflow-hidden rounded-[12px] border border-[#e7e5e0] bg-white"
        >
            <table class="w-full text-left text-[14px]">
                <thead class="border-b border-[#e7e5e0] bg-stone-50 text-[11px] uppercase tracking-[0.04em] text-stone-500">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Produktas</th>
                        <th class="hidden px-4 py-3 font-semibold sm:table-cell">
                            Parduotuvė
                        </th>
                        <th class="px-4 py-3 text-right font-semibold">
                            Buvusi
                        </th>
                        <th class="px-4 py-3 text-right font-semibold">Dabar</th>
                        <th class="px-4 py-3 text-right font-semibold">
                            Pokytis
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="change in biggestChanges"
                        :key="change.id"
                        class="border-b border-[#f1f0ec] last:border-b-0 transition-colors hover:bg-stone-50"
                    >
                        <td class="px-4 py-3">
                            <Link
                                v-if="change.snapshotId !== null"
                                :href="`/products/${change.snapshotId}`"
                                class="block"
                            >
                                <div class="text-[14px] font-semibold text-stone-900">
                                    {{ change.productName }}
                                </div>
                                <div
                                    v-if="change.brand || change.variant"
                                    class="mt-0.5 text-[12px] text-stone-500"
                                >
                                    <span v-if="change.brand">{{ change.brand }}</span>
                                    <span
                                        v-if="change.brand && change.variant"
                                        class="mx-1"
                                    >·</span>
                                    <span v-if="change.variant">{{ change.variant }}</span>
                                </div>
                            </Link>
                            <div v-else>
                                <div class="text-[14px] font-semibold text-stone-900">
                                    {{ change.productName }}
                                </div>
                            </div>
                        </td>
                        <td class="hidden px-4 py-3 sm:table-cell">
                            <StoreLogo
                                :store-id="change.storeSlug"
                                :size="22"
                                :width="78"
                            />
                        </td>
                        <td class="px-4 py-3 text-right text-stone-400 line-through">
                            {{ formatPrice(change.previousPrice) }}
                        </td>
                        <td
                            class="px-4 py-3 text-right font-bold"
                            :class="
                                change.changePct < 0
                                    ? 'text-[#15803d]'
                                    : 'text-red-600'
                            "
                        >
                            {{ formatPrice(change.newPrice) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <span
                                class="inline-flex items-center gap-1 rounded-[6px] px-2 py-1 text-[12px] font-bold text-white"
                                :class="
                                    change.changePct < 0
                                        ? 'bg-[#15803d]'
                                        : 'bg-red-600'
                                "
                            >
                                <ArrowDownRight
                                    v-if="change.changePct < 0"
                                    :size="12"
                                    :stroke-width="2.5"
                                />
                                <ArrowUpRight
                                    v-else
                                    :size="12"
                                    :stroke-width="2.5"
                                />
                                {{ formatPct(change.changePct) }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
