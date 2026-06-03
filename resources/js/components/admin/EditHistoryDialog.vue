<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { RotateCcw, Trash2 } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import {
    history as historyRoute,
    historyUpdate as historyUpdateRoute,
} from '@/actions/App/Http/Controllers/AdminReportsController';
import StoreLogo from '@/components/kainu/StoreLogo.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

export type AdminHistoryRecord = {
    snapshotId: number;
    storeId: number;
    variantId: number;
    storeName: string;
    storeSlug: string;
    variantLabel: string;
    weekStarting: string;
    price: number | null;
    oldPrice: number | null;
    discount: number | null;
    condition: string | null;
    isPromotion: boolean;
    loyaltyCard: boolean;
    unitPrice: number | null;
    unitPriceUnit: string | null;
    validFrom: string | null;
    validTo: string | null;
    scrapedAt: string | null;
    imageUrl: string | null;
    rawName: string | null;
    isCurrent: boolean;
};

type EditableHistory = AdminHistoryRecord & {
    priceInput: string;
    oldPriceInput: string;
    discountInput: string;
    conditionInput: string;
    deleted: boolean;
};

const props = defineProps<{
    open: boolean;
    productId: number | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'saved'): void;
}>();

const rows = ref<EditableHistory[]>([]);
const loading = ref(false);
const saving = ref(false);

function formatNumberInput(value: number): string {
    return value.toFixed(2);
}

function parseNumberInput(value: string): number | null {
    const trimmed = value.trim().replace(',', '.');

    if (trimmed === '') {
        return null;
    }

    const parsed = parseFloat(trimmed);

    return Number.isFinite(parsed) ? parsed : null;
}

function formatPriceShort(value: number | null): string {
    return value === null ? '–' : value.toFixed(2).replace('.', ',');
}

function toEditableRow(record: AdminHistoryRecord): EditableHistory {
    return {
        ...record,
        priceInput: record.price !== null ? formatNumberInput(record.price) : '',
        oldPriceInput:
            record.oldPrice !== null ? formatNumberInput(record.oldPrice) : '',
        discountInput:
            record.discount !== null ? formatNumberInput(record.discount) : '',
        conditionInput: record.condition ?? '',
        deleted: false,
    };
}

async function loadHistory(productId: number) {
    loading.value = true;
    rows.value = [];

    try {
        const response = await fetch(historyRoute(productId).url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return;
        }

        const data = (await response.json()) as {
            history: AdminHistoryRecord[];
        };

        rows.value = data.history
            .filter((record) => !record.isCurrent)
            .map(toEditableRow);
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.open, props.productId] as const,
    ([open, productId]) => {
        if (!open || productId === null) {
            return;
        }

        void loadHistory(productId);
    },
    { immediate: true },
);

function toggleDeleted(record: EditableHistory) {
    record.deleted = !record.deleted;
}

function close() {
    if (saving.value) {
        return;
    }

    emit('update:open', false);
}

function submit() {
    if (props.productId === null || saving.value) {
        return;
    }

    saving.value = true;

    router.patch(
        historyUpdateRoute(props.productId).url,
        {
            history: rows.value
                .filter((record) => !record.deleted)
                .map((record) => ({
                    snapshotId: record.snapshotId,
                    price: parseNumberInput(record.priceInput),
                    oldPrice: parseNumberInput(record.oldPriceInput),
                    discount: parseNumberInput(record.discountInput),
                    condition: record.conditionInput.trim() || null,
                })),
            deleted_snapshot_ids: rows.value
                .filter((record) => record.deleted)
                .map((record) => record.snapshotId),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                emit('saved');
                emit('update:open', false);
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && close()">
        <DialogContent
            class="max-h-[90vh] w-[95vw] max-w-6xl overflow-y-auto sm:max-w-6xl"
        >
            <DialogHeader>
                <DialogTitle>Redaguoti kainų istoriją</DialogTitle>
            </DialogHeader>

            <p class="text-xs text-slate-500">
                Pataisykite ankstesnių savaičių kainas arba pažymėkite klaidingus
                įrašus trynimui. Pakeitimai išsaugomi paspaudus „Išsaugoti“.
            </p>

            <p v-if="loading" class="text-xs text-slate-400">
                Įkeliama istorija…
            </p>
            <p
                v-else-if="rows.length === 0"
                class="rounded-xl border border-dashed border-slate-200 bg-slate-50/50 p-3 text-xs text-slate-400"
            >
                Ankstesnių įrašų nėra.
            </p>

            <div v-else class="overflow-x-auto">
                <table
                    class="w-full border-separate border-spacing-y-1 text-xs"
                >
                    <thead>
                        <tr
                            class="text-left text-[11px] uppercase tracking-[0.04em] text-slate-400"
                        >
                            <th class="px-2 py-1"></th>
                            <th class="px-2 py-1 font-medium">Savaitė</th>
                            <th class="px-2 py-1 font-medium">Parduotuvė</th>
                            <th class="px-2 py-1 font-medium">Pavadinimas</th>
                            <th class="px-2 py-1 font-medium">Kiekis</th>
                            <th class="px-2 py-1 font-medium">Galioja</th>
                            <th class="px-2 py-1 font-medium">Kaina (€)</th>
                            <th class="px-2 py-1 font-medium">Sena (€)</th>
                            <th class="px-2 py-1 font-medium">Nuol. %</th>
                            <th class="px-2 py-1 font-medium">Vnt. kaina</th>
                            <th class="px-2 py-1 font-medium">Sąlyga</th>
                            <th class="px-2 py-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(record, idx) in rows"
                            :key="record.snapshotId"
                            class="align-middle"
                            :class="record.deleted ? 'opacity-50' : ''"
                        >
                            <td class="px-2 py-1">
                                <img
                                    v-if="record.imageUrl"
                                    :src="record.imageUrl"
                                    :alt="record.rawName ?? record.storeName"
                                    class="h-11 w-11 rounded-md border border-slate-200 bg-white object-contain"
                                />
                                <div
                                    v-else
                                    class="flex h-11 w-11 items-center justify-center rounded-md border border-dashed border-slate-200 bg-slate-50 text-[9px] text-slate-300"
                                >
                                    —
                                </div>
                            </td>
                            <td
                                class="whitespace-nowrap px-2 py-1 tabular-nums text-slate-600"
                            >
                                {{ record.weekStarting }}
                            </td>
                            <td class="px-2 py-1">
                                <div class="flex items-center gap-1.5">
                                    <StoreLogo
                                        :store-id="record.storeSlug"
                                        :size="18"
                                    />
                                    <span class="truncate text-slate-700">
                                        {{ record.storeName }}
                                    </span>
                                    <span
                                        v-if="record.isPromotion"
                                        class="rounded bg-amber-100 px-1 py-0.5 text-[9px] font-medium text-amber-700"
                                    >
                                        Akcija
                                    </span>
                                </div>
                            </td>
                            <td class="px-2 py-1 text-slate-600">
                                <span
                                    class="block max-w-45 truncate"
                                    :title="record.rawName ?? ''"
                                >
                                    {{ record.rawName ?? '–' }}
                                </span>
                            </td>
                            <td
                                class="whitespace-nowrap px-2 py-1 text-slate-600"
                            >
                                {{ record.variantLabel }}
                            </td>
                            <td
                                class="whitespace-nowrap px-2 py-1 text-slate-500"
                            >
                                <template
                                    v-if="record.validFrom || record.validTo"
                                >
                                    {{ record.validFrom ?? '…' }} –
                                    {{ record.validTo ?? '…' }}
                                </template>
                                <template v-else>–</template>
                            </td>
                            <td class="px-2 py-1">
                                <Input
                                    v-model="rows[idx].priceInput"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="–"
                                    class="h-8 w-20"
                                    :disabled="saving || record.deleted"
                                />
                            </td>
                            <td class="px-2 py-1">
                                <Input
                                    v-model="rows[idx].oldPriceInput"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="–"
                                    class="h-8 w-20"
                                    :disabled="saving || record.deleted"
                                />
                            </td>
                            <td class="px-2 py-1">
                                <Input
                                    v-model="rows[idx].discountInput"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="auto"
                                    class="h-8 w-16"
                                    :disabled="saving || record.deleted"
                                />
                            </td>
                            <td
                                class="whitespace-nowrap px-2 py-1 tabular-nums text-slate-500"
                            >
                                <template v-if="record.unitPrice !== null">
                                    {{ formatPriceShort(record.unitPrice)
                                    }}<template v-if="record.unitPriceUnit"
                                        >/{{ record.unitPriceUnit }}</template
                                    >
                                </template>
                                <template v-else>–</template>
                            </td>
                            <td class="px-2 py-1">
                                <Input
                                    v-model="rows[idx].conditionInput"
                                    type="text"
                                    placeholder="pvz. 1+1"
                                    class="h-8 w-24"
                                    :disabled="saving || record.deleted"
                                />
                            </td>
                            <td class="px-2 py-1 text-right">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="h-7 px-2"
                                    :class="
                                        record.deleted
                                            ? 'text-slate-500'
                                            : 'text-red-600 hover:text-red-700'
                                    "
                                    :disabled="saving"
                                    :title="
                                        record.deleted ? 'Atstatyti' : 'Trinti'
                                    "
                                    @click="toggleDeleted(record)"
                                >
                                    <RotateCcw
                                        v-if="record.deleted"
                                        :size="12"
                                    />
                                    <Trash2 v-else :size="12" />
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" @click="close">
                    Atšaukti
                </Button>
                <Button
                    type="button"
                    :disabled="saving || loading || rows.length === 0"
                    class="bg-emerald-600 hover:bg-emerald-700"
                    @click="submit"
                >
                    {{ saving ? 'Saugoma…' : 'Išsaugoti' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
