<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowDownRight,
    ArrowUpRight,
    ListChecks,
    Loader2,
    Package2,
    PiggyBank,
    Sparkles,
    Store,
    TrendingDown,
    TrendingUp,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import ProductImage from '@/components/kainu/ProductImage.vue';
import StoreLogo from '@/components/kainu/StoreLogo.vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import KainuLayout from '@/layouts/KainuLayout.vue';
import { resolveStore } from '@/lib/kainu';
import { changes as statsChanges } from '@/routes/stats';

defineOptions({ layout: KainuLayout });

type BiggestChange = {
    id: number;
    snapshotId: number | null;
    productName: string;
    rawName: string;
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

type PersonalStats = {
    listsCount: number;
    totalSavings: number;
    totalItems: number;
    topProduct: { name: string; qty: number } | null;
    topStoreId: string | null;
};

const props = defineProps<{
    biggestChanges: BiggestChange[];
    summary: Summary;
    personal: PersonalStats | null;
}>();

const formatPrice = (value: number | null) =>
    value === null ? '—' : `${value.toFixed(2).replace('.', ',')} €`;

const formatPct = (value: number) => {
    const rounded = Math.round(value);

    return `${rounded > 0 ? '+' : ''}${rounded}%`;
};

const hasChanges = computed(() => props.biggestChanges.length > 0);
const hasPersonalActivity = computed(
    () => (props.personal?.listsCount ?? 0) > 0,
);
const topStore = computed(() =>
    props.personal?.topStoreId ? resolveStore(props.personal.topStoreId) : null,
);

type ChangeDirection = 'drops' | 'raises';

const changesModalOpen = ref(false);
const changesDirection = ref<ChangeDirection>('drops');
const changesLoading = ref(false);
const changesRows = ref<BiggestChange[]>([]);

const changesTitle = computed(() =>
    changesDirection.value === 'drops' ? 'Atpigimai' : 'Pabrangimai',
);

async function openChanges(direction: ChangeDirection) {
    changesDirection.value = direction;
    changesRows.value = [];
    changesModalOpen.value = true;
    changesLoading.value = true;

    try {
        const response = await fetch(
            statsChanges.url({ query: { direction } }),
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            },
        );

        if (!response.ok) {
            throw new Error('Nepavyko užkrauti pokyčių');
        }

        const payload = (await response.json()) as {
            direction: ChangeDirection;
            changes: BiggestChange[];
        };

        // Ignore a slow response if the user has since opened the other list.
        if (payload.direction === changesDirection.value) {
            changesRows.value = payload.changes;
        }
    } catch {
        changesRows.value = [];
    } finally {
        changesLoading.value = false;
    }
}

const formatWeek = (value: string | null): string =>
    value ? value.slice(0, 10) : '—';
</script>

<template>
    <Head title="Statistika · Kainų seklys" />

    <section class="pt-8 pb-2">
        <h1 class="m-0 text-[28px] font-bold tracking-tight text-stone-900">
            Statistika
        </h1>
        <p class="mt-2 mb-0 max-w-[640px] text-[14px] text-stone-600">
            Kaip kainos kinta savaitę po savaitės. Šis puslapis surenka kainų
            pokyčius tarp skirtingų savaičių, tad didžiausi pokyčiai matomi tik
            tada, kai surinkta bent dviejų savaičių duomenų.
        </p>
    </section>

    <section v-if="personal" class="mt-6">
        <div class="flex items-baseline justify-between gap-4">
            <h2 class="m-0 text-[20px] font-bold tracking-tight text-stone-900">
                Mano statistika
            </h2>
            <span class="text-[12px] text-stone-500">
                Pagal sugeneruotus pirkinių sąrašus
            </span>
        </div>

        <div
            v-if="!hasPersonalActivity"
            class="mt-4 rounded-[12px] border border-dashed border-[#e7e5e0] bg-white p-6 text-center"
        >
            <Sparkles
                :size="24"
                :stroke-width="1.5"
                class="mx-auto mb-2 text-stone-400"
            />
            <p class="m-0 text-[13px] font-medium text-stone-700">
                Dar nesi sugeneravęs pirkinių sąrašo
            </p>
            <p
                class="mx-auto mt-1 mb-0 max-w-[420px] text-[12px] text-stone-500"
            >
                Sudėliokite krepšelį ir spauskite „Generuoti apsipirkimo
                maršrutą“ — čia atsiras santaupos, dažniausiai perkamos prekės
                ir mėgstamiausios parduotuvės.
            </p>
        </div>

        <div v-else class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-[12px] border border-[#e7e5e0] bg-white p-4">
                <div class="flex items-center gap-2 text-[11px] text-stone-500">
                    <PiggyBank :size="14" :stroke-width="1.75" />
                    <span class="tracking-[0.04em] uppercase">Sutaupiau</span>
                </div>
                <div
                    class="mt-1 text-[22px] font-bold tracking-tight text-[#15803d]"
                >
                    {{ formatPrice(personal.totalSavings) }}
                </div>
            </div>

            <div class="rounded-[12px] border border-[#e7e5e0] bg-white p-4">
                <div class="flex items-center gap-2 text-[11px] text-stone-500">
                    <ListChecks :size="14" :stroke-width="1.75" />
                    <span class="tracking-[0.04em] uppercase">Sąrašai</span>
                </div>
                <div
                    class="mt-1 text-[22px] font-bold tracking-tight text-stone-900"
                >
                    {{ personal.listsCount.toLocaleString('lt-LT') }}
                </div>
                <div class="mt-0.5 text-[11px] text-stone-500">
                    {{ personal.totalItems.toLocaleString('lt-LT') }} prekės iš
                    viso
                </div>
            </div>

            <div class="rounded-[12px] border border-[#e7e5e0] bg-white p-4">
                <div class="flex items-center gap-2 text-[11px] text-stone-500">
                    <Sparkles :size="14" :stroke-width="1.75" />
                    <span class="tracking-[0.04em] uppercase">Top prekė</span>
                </div>
                <div
                    v-if="personal.topProduct"
                    class="mt-1 truncate text-[15px] font-bold tracking-tight text-stone-900"
                    :title="personal.topProduct.name"
                >
                    {{ personal.topProduct.name }}
                </div>
                <div v-else class="mt-1 text-[15px] font-medium text-stone-400">
                    —
                </div>
                <div
                    v-if="personal.topProduct"
                    class="mt-0.5 text-[11px] text-stone-500"
                >
                    {{ personal.topProduct.qty }} vnt. pirktos
                </div>
            </div>

            <div class="rounded-[12px] border border-[#e7e5e0] bg-white p-4">
                <div class="flex items-center gap-2 text-[11px] text-stone-500">
                    <Store :size="14" :stroke-width="1.75" />
                    <span class="tracking-[0.04em] uppercase">
                        Mėgstama parduotuvė
                    </span>
                </div>
                <div v-if="topStore" class="mt-2">
                    <StoreLogo
                        :store-id="topStore.id"
                        :size="22"
                        :show-name="true"
                    />
                </div>
                <div v-else class="mt-1 text-[15px] font-medium text-stone-400">
                    —
                </div>
            </div>
        </div>
    </section>

    <section class="mt-10 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-[12px] border border-[#e7e5e0] bg-white p-4">
            <div class="flex items-center gap-2 text-[11px] text-stone-500">
                <Package2 :size="14" :stroke-width="1.75" />
                <span class="tracking-[0.04em] uppercase">Produktai</span>
            </div>
            <div
                class="mt-1 text-[22px] font-bold tracking-tight text-stone-900"
            >
                {{ summary.totalProducts.toLocaleString('lt-LT') }}
            </div>
        </div>

        <div class="rounded-[12px] border border-[#e7e5e0] bg-white p-4">
            <div class="flex items-center gap-2 text-[11px] text-stone-500">
                <Store :size="14" :stroke-width="1.75" />
                <span class="tracking-[0.04em] uppercase">Parduotuvės</span>
            </div>
            <div
                class="mt-1 text-[22px] font-bold tracking-tight text-stone-900"
            >
                {{ summary.totalStores }}
            </div>
        </div>

        <button
            type="button"
            :disabled="summary.totalDrops === 0"
            class="rounded-[12px] border border-[#e7e5e0] bg-white p-4 text-left transition-colors enabled:cursor-pointer enabled:hover:border-[#15803d] disabled:opacity-60"
            @click="openChanges('drops')"
        >
            <div class="flex items-center gap-2 text-[11px] text-stone-500">
                <TrendingDown :size="14" :stroke-width="1.75" />
                <span class="tracking-[0.04em] uppercase">Atpigimai</span>
            </div>
            <div
                class="mt-1 text-[22px] font-bold tracking-tight text-[#15803d]"
            >
                {{ summary.totalDrops.toLocaleString('lt-LT') }}
            </div>
        </button>

        <button
            type="button"
            :disabled="summary.totalRaises === 0"
            class="rounded-[12px] border border-[#e7e5e0] bg-white p-4 text-left transition-colors enabled:cursor-pointer enabled:hover:border-red-400 disabled:opacity-60"
            @click="openChanges('raises')"
        >
            <div class="flex items-center gap-2 text-[11px] text-stone-500">
                <TrendingUp :size="14" :stroke-width="1.75" />
                <span class="tracking-[0.04em] uppercase">Pabrangimai</span>
            </div>
            <div class="mt-1 text-[22px] font-bold tracking-tight text-red-600">
                {{ summary.totalRaises.toLocaleString('lt-LT') }}
            </div>
        </button>
    </section>

    <section class="mt-10">
        <div class="flex items-baseline justify-between gap-4">
            <h2 class="m-0 text-[20px] font-bold tracking-tight text-stone-900">
                Didžiausi kainų pokyčiai
            </h2>
            <span class="text-[12px] text-stone-500">
                Top 5 · palyginimas su praėjusia savaite
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
            <p
                class="mx-auto mt-1 mb-0 max-w-[420px] text-[12px] text-stone-500"
            >
                Kainų pokyčiai bus rodomi po antrojo skenavimo (kitą
                pirmadienį), kai bus su kuo palyginti šios savaitės kainas.
            </p>
        </div>

        <div
            v-else
            class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5"
        >
            <component
                :is="change.snapshotId !== null ? Link : 'div'"
                v-for="change in biggestChanges"
                :key="change.id"
                :href="
                    change.snapshotId !== null
                        ? `/products/${change.snapshotId}`
                        : undefined
                "
                class="group relative flex flex-col rounded-[12px] border border-[#e7e5e0] bg-white p-3 transition-[border-color,box-shadow] duration-150 hover:border-[#15803d] hover:shadow-[0_2px_8px_rgba(21,128,61,0.08)]"
            >
                <span
                    class="absolute top-2 right-2 z-[1] inline-flex items-center gap-0.5 rounded-[6px] px-1.5 py-0.5 text-[11px] font-bold text-white"
                    :class="
                        change.changePct < 0 ? 'bg-[#15803d]' : 'bg-red-600'
                    "
                >
                    <ArrowDownRight
                        v-if="change.changePct < 0"
                        :size="11"
                        :stroke-width="2.5"
                    />
                    <ArrowUpRight v-else :size="11" :stroke-width="2.5" />
                    {{ formatPct(change.changePct) }}
                </span>

                <div class="mb-2 flex justify-center">
                    <ProductImage
                        :seed="change.id"
                        :src="change.image"
                        :name="change.rawName"
                        :size="92"
                    />
                </div>

                <div class="mb-2 min-h-9 flex-1">
                    <div
                        v-if="change.brand"
                        class="mb-[2px] truncate text-[10px] font-semibold tracking-[0.04em] text-stone-400 uppercase"
                    >
                        {{ change.brand }}
                    </div>
                    <h3
                        class="m-0 line-clamp-2 text-[13px] leading-[1.3] font-medium text-stone-900"
                        :title="change.rawName"
                    >
                        {{ change.rawName }}
                    </h3>
                </div>

                <div class="mb-2 flex items-baseline gap-1.5">
                    <span
                        class="text-[16px] font-bold tracking-tight"
                        :class="
                            change.changePct < 0
                                ? 'text-[#15803d]'
                                : 'text-red-600'
                        "
                    >
                        {{ formatPrice(change.newPrice) }}
                    </span>
                    <span class="text-[11px] text-stone-400 line-through">
                        {{ formatPrice(change.previousPrice) }}
                    </span>
                </div>

                <div class="flex items-center">
                    <StoreLogo
                        :store-id="change.storeSlug"
                        :size="24"
                        :width="76"
                    />
                </div>
            </component>
        </div>
    </section>

    <Dialog
        :open="changesModalOpen"
        @update:open="(v) => (changesModalOpen = v)"
    >
        <DialogContent class="sm:max-w-4xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <TrendingDown
                        v-if="changesDirection === 'drops'"
                        :size="18"
                        class="text-[#15803d]"
                    />
                    <TrendingUp v-else :size="18" class="text-red-600" />
                    {{ changesTitle }}
                </DialogTitle>
                <p class="text-sm text-slate-500">
                    Visi kainų pokyčiai, palyginus su praėjusia savaite.
                </p>
            </DialogHeader>

            <div
                v-if="changesLoading"
                class="flex items-center justify-center py-12 text-stone-400"
            >
                <Loader2 :size="22" class="animate-spin" />
            </div>

            <div
                v-else-if="changesRows.length === 0"
                class="py-12 text-center text-sm text-stone-500"
            >
                Nėra duomenų.
            </div>

            <div v-else class="max-h-[60vh] overflow-y-auto">
                <table class="w-full text-left text-[13px]">
                    <thead
                        class="sticky top-0 bg-white text-[11px] tracking-[0.04em] text-stone-500 uppercase"
                    >
                        <tr class="border-b border-kainu-border">
                            <th class="py-2 pr-2 font-medium">Prekė</th>
                            <th class="px-2 py-2 font-medium">Parduotuvė</th>
                            <th class="px-2 py-2 text-right font-medium">
                                Sena
                            </th>
                            <th class="px-2 py-2 text-right font-medium">
                                Nauja
                            </th>
                            <th class="py-2 pl-2 text-right font-medium">
                                Pokytis
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in changesRows"
                            :key="row.id"
                            class="border-b border-stone-50 last:border-0"
                        >
                            <td class="max-w-[220px] py-2 pr-2">
                                <component
                                    :is="
                                        row.snapshotId !== null ? Link : 'span'
                                    "
                                    :href="
                                        row.snapshotId !== null
                                            ? `/products/${row.snapshotId}`
                                            : undefined
                                    "
                                    class="block truncate font-medium text-stone-800 hover:text-[#15803d]"
                                    :title="row.rawName"
                                >
                                    {{ row.rawName }}
                                </component>
                                <span class="text-[11px] text-stone-400">
                                    {{ formatWeek(row.weekStarting) }}
                                </span>
                            </td>
                            <td class="px-2 py-2">
                                <StoreLogo
                                    :store-id="row.storeSlug"
                                    :size="18"
                                    :width="64"
                                />
                            </td>
                            <td
                                class="px-2 py-2 text-right text-stone-400 tabular-nums line-through"
                            >
                                {{ formatPrice(row.previousPrice) }}
                            </td>
                            <td
                                class="px-2 py-2 text-right font-semibold tabular-nums"
                                :class="
                                    row.changePct < 0
                                        ? 'text-[#15803d]'
                                        : 'text-red-600'
                                "
                            >
                                {{ formatPrice(row.newPrice) }}
                            </td>
                            <td class="py-2 pl-2 text-right">
                                <span
                                    class="inline-flex items-center gap-0.5 font-bold tabular-nums"
                                    :class="
                                        row.changePct < 0
                                            ? 'text-[#15803d]'
                                            : 'text-red-600'
                                    "
                                >
                                    <ArrowDownRight
                                        v-if="row.changePct < 0"
                                        :size="12"
                                        :stroke-width="2.5"
                                    />
                                    <ArrowUpRight
                                        v-else
                                        :size="12"
                                        :stroke-width="2.5"
                                    />
                                    {{ formatPct(row.changePct) }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </DialogContent>
    </Dialog>
</template>
