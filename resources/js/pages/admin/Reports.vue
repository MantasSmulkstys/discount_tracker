<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Bot,
    Eye,
    EyeOff,
    Flag,
    History,
    Pencil,
    TriangleAlert,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { unhide as unhideProduct } from '@/actions/App/Http/Controllers/AdminReportsController';
import EditHistoryDialog from '@/components/admin/EditHistoryDialog.vue';
import EditProductDialog from '@/components/admin/EditProductDialog.vue';
import type {
    AdminProduct,
    CategoryNode,
    FamilyOption,
} from '@/components/admin/EditProductDialog.vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    userReported: AdminProduct[];
    aiFlagged: AdminProduct[];
    hidden: AdminProduct[];
    categories: CategoryNode[];
    families: FamilyOption[];
}>();

const activeTab = ref<'user' | 'ai' | 'hidden'>('user');

function unhide(product: AdminProduct) {
    router.delete(unhideProduct(product.id).url, {
        preserveScroll: true,
        preserveState: false,
    });
}
const editingProduct = ref<AdminProduct | null>(null);

function openEdit(product: AdminProduct) {
    editingProduct.value = product;
}

function closeEdit() {
    editingProduct.value = null;
}

const historyProductId = ref<number | null>(null);
const historyEditorOpen = ref(false);

function openHistory(product: AdminProduct) {
    historyProductId.value = product.id;
    historyEditorOpen.value = true;
}

function confidenceClass(conf: number | null): string {
    if (conf === null) {
        return 'text-stone-400';
    }

    if (conf < 0.4) {
        return 'text-red-600 font-semibold';
    }

    if (conf < 0.7) {
        return 'text-amber-600 font-semibold';
    }

    return 'text-emerald-600';
}

function confidenceLabel(conf: number | null): string {
    if (conf === null) {
        return '–';
    }

    return (conf * 100).toFixed(0) + '%';
}

const currentList = computed(() => {
    if (activeTab.value === 'user') {
        return props.userReported;
    }

    if (activeTab.value === 'ai') {
        return props.aiFlagged;
    }

    return props.hidden;
});
</script>

<template>
    <Head title="Reports" />

    <div class="p-4 md:p-6">
        <div class="mx-auto max-w-3xl space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-slate-950">
                        Pranešti produktai
                    </h1>
                </div>
            </div>

            <div
                class="flex gap-1 rounded-2xl border border-emerald-100 bg-emerald-50/40 p-1"
            >
                <button
                    type="button"
                    class="flex flex-1 items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'user'
                            ? 'bg-white text-slate-950 shadow-sm'
                            : 'text-slate-600 hover:text-slate-900'
                    "
                    @click="activeTab = 'user'"
                >
                    <Flag :size="15" />
                    Naudotojų pranešti produktai
                    <span
                        class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700"
                    >
                        {{ userReported.length }}
                    </span>
                </button>
                <button
                    type="button"
                    class="flex flex-1 items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'ai'
                            ? 'bg-white text-slate-950 shadow-sm'
                            : 'text-slate-600 hover:text-slate-900'
                    "
                    @click="activeTab = 'ai'"
                >
                    <Bot :size="15" />
                    DI pranešti produktai
                    <span
                        class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"
                    >
                        {{ aiFlagged.length }}
                    </span>
                </button>
                <button
                    type="button"
                    class="flex flex-1 items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'hidden'
                            ? 'bg-white text-slate-950 shadow-sm'
                            : 'text-slate-600 hover:text-slate-900'
                    "
                    @click="activeTab = 'hidden'"
                >
                    <EyeOff :size="15" />
                    Paslėpti produktai
                    <span
                        class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"
                    >
                        {{ hidden.length }}
                    </span>
                </button>
            </div>

            <div
                v-if="currentList.length === 0"
                class="rounded-3xl border border-dashed border-emerald-200 bg-white p-12 text-center text-slate-500"
            >
                Nėra produktų eilėje.
            </div>

            <div
                v-else
                class="overflow-hidden rounded-3xl border border-emerald-100 bg-white shadow-sm"
            >
                <ul class="divide-y divide-emerald-50">
                    <li
                        v-for="product in currentList"
                        :key="product.id"
                        class="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-emerald-50/30"
                    >
                        <img
                            v-if="product.imageUrl"
                            :src="product.imageUrl"
                            :alt="product.rawName"
                            class="h-10 w-10 shrink-0 rounded-lg object-contain"
                        />
                        <div
                            v-else
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-stone-100 text-stone-400"
                        >
                            <TriangleAlert :size="14" />
                        </div>

                        <p
                            class="min-w-0 flex-1 truncate font-medium text-slate-900"
                        >
                            {{ product.rawName }}
                        </p>

                        <span
                            v-if="activeTab === 'user'"
                            class="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700"
                        >
                            <Flag :size="11" />
                            {{ product.reportsCount }}
                        </span>
                        <span
                            v-else-if="activeTab === 'ai'"
                            class="shrink-0 text-sm tabular-nums"
                            :class="confidenceClass(product.aiConfidence)"
                        >
                            {{ confidenceLabel(product.aiConfidence) }}
                        </span>
                        <span
                            v-else
                            class="inline-flex shrink-0 items-center gap-1 rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-600"
                        >
                            <EyeOff :size="11" />
                            Paslėpta
                        </span>

                        <Button
                            v-if="activeTab === 'hidden'"
                            variant="outline"
                            size="sm"
                            class="shrink-0 gap-1.5"
                            title="Grąžinti į katalogą"
                            @click="unhide(product)"
                        >
                            <Eye :size="13" />
                            Grąžinti
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            class="shrink-0 gap-1.5"
                            @click="openEdit(product)"
                        >
                            <Pencil :size="13" />
                            Redaguoti
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            class="shrink-0 gap-1.5"
                            title="Redaguoti kainų istoriją"
                            @click="openHistory(product)"
                        >
                            <History :size="13" />
                            Istorija
                        </Button>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <EditProductDialog
        :product="editingProduct"
        :categories="categories"
        :families="families"
        save-label="Išsaugoti ir atmesti pranešimus"
        @close="closeEdit"
    />

    <EditHistoryDialog
        v-model:open="historyEditorOpen"
        :product-id="historyProductId"
    />
</template>
