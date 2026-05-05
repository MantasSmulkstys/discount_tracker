<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    Bot,
    Clock,
    Flag,
    Pencil,
    Store,
    Tag,
    TriangleAlert,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { update } from '@/actions/App/Http/Controllers/AdminReportsController';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type AdminOffer = {
    snapshotId: number;
    storeId: number;
    variantId: number;
    storeName: string;
    storeSlug: string;
    variantLabel: string;
    price: number | null;
    oldPrice: number | null;
    discount: number | null;
    condition: string | null;
    lastSeenAt: string | null;
};

type AdminProduct = {
    id: number;
    canonicalName: string;
    brand: string | null;
    categoryId: number | null;
    categoryName: string | null;
    subcategoryId: number | null;
    subcategoryName: string | null;
    aiConfidence: number | null;
    needsReview: boolean;
    reportsCount: number;
    imageUrl: string | null;
    offers: AdminOffer[];
    bestPrice: number | null;
    storeCount: number;
    lastSeenAt: string | null;
};

type CategoryNode = {
    id: number;
    slug: string;
    name: string;
    subcategories: { id: number; slug: string; name: string }[];
};

const props = defineProps<{
    userReported: AdminProduct[];
    aiFlagged: AdminProduct[];
    categories: CategoryNode[];
}>();

const activeTab = ref<'user' | 'ai'>('user');
const editingProduct = ref<AdminProduct | null>(null);

type EditableOffer = AdminOffer & {
    priceInput: string;
    oldPriceInput: string;
    discountInput: string;
    conditionInput: string;
};

const form = useForm({
    canonical_name: '',
    brand: '',
    category_id: null as number | null,
    subcategory_id: null as number | null,
    offers: [] as EditableOffer[],
});

function openEdit(product: AdminProduct) {
    editingProduct.value = product;
    form.canonical_name = product.canonicalName;
    form.brand = product.brand ?? '';
    form.category_id = product.categoryId;
    form.subcategory_id = product.subcategoryId;
    form.offers = product.offers.map((o) => ({
        ...o,
        priceInput: o.price !== null ? formatNumberInput(o.price) : '',
        oldPriceInput: o.oldPrice !== null ? formatNumberInput(o.oldPrice) : '',
        discountInput: o.discount !== null ? formatNumberInput(o.discount) : '',
        conditionInput: o.condition ?? '',
    }));
}

function closeEdit() {
    editingProduct.value = null;
    form.reset();
}

function submitEdit() {
    if (!editingProduct.value) {
        return;
    }

    const transformed = {
        canonical_name: form.canonical_name,
        brand: form.brand,
        category_id: form.category_id,
        subcategory_id: form.subcategory_id,
        offers: form.offers.map((o) => ({
            snapshotId: o.snapshotId,
            storeId: o.storeId,
            variantId: o.variantId,
            price: parseNumberInput(o.priceInput),
            oldPrice: parseNumberInput(o.oldPriceInput),
            discount: parseNumberInput(o.discountInput),
            condition: o.conditionInput.trim() || null,
        })),
    };

    form
        .transform(() => transformed)
        .patch(update(editingProduct.value.id).url, {
            onSuccess: () => closeEdit(),
        });
}

const activeSubcategories = computed(() => {
    if (!form.category_id) return [];
    return (
        props.categories.find((c) => c.id === form.category_id)?.subcategories ??
        []
    );
});

function onCategoryChange(val: string) {
    form.category_id = val ? parseInt(val) : null;
    form.subcategory_id = null;
}

function confidenceClass(conf: number | null): string {
    if (conf === null) return 'text-stone-400';
    if (conf < 0.4) return 'text-red-600 font-semibold';
    if (conf < 0.7) return 'text-amber-600 font-semibold';
    return 'text-emerald-600';
}

function confidenceLabel(conf: number | null): string {
    if (conf === null) return '–';
    return (conf * 100).toFixed(0) + '%';
}

function formatPrice(value: number | null): string {
    if (value === null) return '–';
    return value.toFixed(2).replace('.', ',') + ' €';
}

function formatNumberInput(value: number): string {
    return value.toFixed(2);
}

function parseNumberInput(value: string): number | null {
    const trimmed = value.trim().replace(',', '.');
    if (trimmed === '') return null;
    const parsed = parseFloat(trimmed);
    return Number.isFinite(parsed) ? parsed : null;
}

function formatRelativeDate(iso: string | null): string {
    if (!iso) return '–';
    const date = new Date(iso);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffHours = Math.round(diffMs / (1000 * 60 * 60));
    if (diffHours < 1) return 'just now';
    if (diffHours < 24) return `${diffHours}h ago`;
    const diffDays = Math.round(diffHours / 24);
    if (diffDays < 30) return `${diffDays}d ago`;
    return date.toISOString().slice(0, 10);
}

const currentList = computed(() =>
    activeTab.value === 'user' ? props.userReported : props.aiFlagged,
);
</script>

<template>
    <Head title="Reports" />

    <div class="p-4 md:p-6">
        <div class="mx-auto max-w-3xl space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-slate-950">
                        Flagged Products
                    </h1>
                    <p class="mt-1 text-sm text-slate-500">
                        User-reported (5+ reports) and AI low-confidence items.
                    </p>
                </div>
            </div>

            <div class="flex gap-1 rounded-2xl border border-emerald-100 bg-emerald-50/40 p-1">
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
                    User Reported
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700">
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
                    AI Flagged
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                        {{ aiFlagged.length }}
                    </span>
                </button>
            </div>

            <div
                v-if="currentList.length === 0"
                class="rounded-3xl border border-dashed border-emerald-200 bg-white p-12 text-center text-slate-500"
            >
                No items in this queue.
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
                            :alt="product.canonicalName"
                            class="h-10 w-10 shrink-0 rounded-lg object-contain"
                        />
                        <div
                            v-else
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-stone-100 text-stone-400"
                        >
                            <TriangleAlert :size="14" />
                        </div>

                        <p class="min-w-0 flex-1 truncate font-medium text-slate-900">
                            {{ product.canonicalName }}
                        </p>

                        <span
                            v-if="activeTab === 'user'"
                            class="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700"
                        >
                            <Flag :size="11" />
                            {{ product.reportsCount }}
                        </span>
                        <span
                            v-else
                            class="shrink-0 text-sm tabular-nums"
                            :class="confidenceClass(product.aiConfidence)"
                        >
                            {{ confidenceLabel(product.aiConfidence) }}
                        </span>

                        <Button
                            variant="outline"
                            size="sm"
                            class="shrink-0 gap-1.5"
                            @click="openEdit(product)"
                        >
                            <Pencil :size="13" />
                            Edit
                        </Button>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <Dialog :open="!!editingProduct" @update:open="(v) => !v && closeEdit()">
        <DialogContent class="max-h-[90vh] max-w-3xl overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Edit Product</DialogTitle>
            </DialogHeader>

            <div
                v-if="editingProduct"
                class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600"
            >
                <span class="inline-flex items-center gap-1">
                    <Store :size="12" class="text-slate-400" />
                    {{ editingProduct.storeCount }} store{{ editingProduct.storeCount === 1 ? '' : 's' }}
                </span>
                <span class="inline-flex items-center gap-1 font-semibold text-emerald-700">
                    {{ formatPrice(editingProduct.bestPrice) }}
                </span>
                <span class="inline-flex items-center gap-1">
                    <Clock :size="12" class="text-slate-400" />
                    {{ formatRelativeDate(editingProduct.lastSeenAt) }}
                </span>
                <span v-if="editingProduct.categoryName" class="text-slate-500">
                    {{ editingProduct.categoryName }}
                    <span v-if="editingProduct.subcategoryName" class="text-slate-400">
                        / {{ editingProduct.subcategoryName }}
                    </span>
                </span>
            </div>

            <form class="space-y-6" @submit.prevent="submitEdit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5 sm:col-span-2">
                        <Label for="canonical_name">Name</Label>
                        <Input
                            id="canonical_name"
                            v-model="form.canonical_name"
                            :disabled="form.processing"
                        />
                        <p
                            v-if="form.errors.canonical_name"
                            class="text-xs text-red-600"
                        >
                            {{ form.errors.canonical_name }}
                        </p>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="brand">Brand</Label>
                        <Input
                            id="brand"
                            v-model="form.brand"
                            placeholder="Optional"
                            :disabled="form.processing"
                        />
                    </div>

                    <div class="space-y-1.5">
                        <Label>Category</Label>
                        <Select
                            :model-value="form.category_id?.toString() ?? ''"
                            @update:model-value="onCategoryChange"
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="No category" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">No category</SelectItem>
                                <SelectItem
                                    v-for="cat in categories"
                                    :key="cat.id"
                                    :value="cat.id.toString()"
                                >
                                    {{ cat.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div
                        v-if="activeSubcategories.length > 0"
                        class="space-y-1.5 sm:col-span-2"
                    >
                        <Label>Subcategory</Label>
                        <Select
                            :model-value="form.subcategory_id?.toString() ?? ''"
                            @update:model-value="
                                (v) =>
                                    (form.subcategory_id = v
                                        ? parseInt(v)
                                        : null)
                            "
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="No subcategory" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">No subcategory</SelectItem>
                                <SelectItem
                                    v-for="sub in activeSubcategories"
                                    :key="sub.id"
                                    :value="sub.id.toString()"
                                >
                                    {{ sub.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div v-if="form.offers.length > 0" class="space-y-3">
                    <div class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                        <Tag :size="14" />
                        Prices per store
                    </div>
                    <p class="text-xs text-slate-500">
                        Edit price, old price, discount, or condition. If discount is left empty, it is recalculated from price and old price.
                    </p>

                    <div class="space-y-2">
                        <div
                            v-for="(offer, idx) in form.offers"
                            :key="offer.snapshotId"
                            class="rounded-xl border border-slate-200 bg-slate-50/50 p-3"
                        >
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <StoreLogo :store-id="offer.storeSlug" :size="22" />
                                    <span class="text-sm font-semibold text-slate-900">
                                        {{ offer.storeName }}
                                    </span>
                                    <span class="text-xs text-slate-500">
                                        {{ offer.variantLabel }}
                                    </span>
                                </div>
                                <span class="inline-flex items-center gap-1 text-xs text-slate-400">
                                    <Clock :size="11" />
                                    {{ formatRelativeDate(offer.lastSeenAt) }}
                                </span>
                            </div>

                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                <div class="space-y-1">
                                    <Label
                                        :for="`price-${offer.snapshotId}`"
                                        class="text-xs text-slate-600"
                                    >
                                        Price (€)
                                    </Label>
                                    <Input
                                        :id="`price-${offer.snapshotId}`"
                                        v-model="form.offers[idx].priceInput"
                                        type="text"
                                        inputmode="decimal"
                                        placeholder="–"
                                        :disabled="form.processing"
                                    />
                                </div>
                                <div class="space-y-1">
                                    <Label
                                        :for="`old-${offer.snapshotId}`"
                                        class="text-xs text-slate-600"
                                    >
                                        Old price (€)
                                    </Label>
                                    <Input
                                        :id="`old-${offer.snapshotId}`"
                                        v-model="form.offers[idx].oldPriceInput"
                                        type="text"
                                        inputmode="decimal"
                                        placeholder="–"
                                        :disabled="form.processing"
                                    />
                                </div>
                                <div class="space-y-1">
                                    <Label
                                        :for="`disc-${offer.snapshotId}`"
                                        class="text-xs text-slate-600"
                                    >
                                        Discount (%)
                                    </Label>
                                    <Input
                                        :id="`disc-${offer.snapshotId}`"
                                        v-model="form.offers[idx].discountInput"
                                        type="text"
                                        inputmode="decimal"
                                        placeholder="auto"
                                        :disabled="form.processing"
                                    />
                                </div>
                                <div class="space-y-1">
                                    <Label
                                        :for="`cond-${offer.snapshotId}`"
                                        class="text-xs text-slate-600"
                                    >
                                        Condition
                                    </Label>
                                    <Input
                                        :id="`cond-${offer.snapshotId}`"
                                        v-model="form.offers[idx].conditionInput"
                                        type="text"
                                        placeholder="e.g. 1+1"
                                        :disabled="form.processing"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="closeEdit">
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        class="bg-emerald-600 hover:bg-emerald-700"
                    >
                        {{ form.processing ? 'Saving…' : 'Save & Dismiss Reports' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
