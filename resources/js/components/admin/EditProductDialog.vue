<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Clock, Store, Tag } from 'lucide-vue-next';
import type { AcceptableValue } from 'reka-ui';
import { computed, watch } from 'vue';
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

export type AdminOffer = {
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

export type AdminProduct = {
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

export type CategoryNode = {
    id: number;
    slug: string;
    name: string;
    subcategories: { id: number; slug: string; name: string }[];
};

type EditableOffer = AdminOffer & {
    priceInput: string;
    oldPriceInput: string;
    discountInput: string;
    conditionInput: string;
};

const props = defineProps<{
    product: AdminProduct | null;
    categories: CategoryNode[];
    saveLabel?: string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'saved'): void;
}>();

const form = useForm({
    canonical_name: '',
    brand: '',
    category_id: null as number | null,
    subcategory_id: null as number | null,
    offers: [] as EditableOffer[],
});

watch(
    () => props.product,
    (product) => {
        if (!product) {
            form.reset();

            return;
        }

        form.canonical_name = product.canonicalName;
        form.brand = product.brand ?? '';
        form.category_id = product.categoryId;
        form.subcategory_id = product.subcategoryId;
        form.offers = product.offers.map((o) => ({
            ...o,
            priceInput: o.price !== null ? formatNumberInput(o.price) : '',
            oldPriceInput:
                o.oldPrice !== null ? formatNumberInput(o.oldPrice) : '',
            discountInput:
                o.discount !== null ? formatNumberInput(o.discount) : '',
            conditionInput: o.condition ?? '',
        }));
    },
    { immediate: true },
);

const activeSubcategories = computed(() => {
    if (!form.category_id) {
return [];
}

    return (
        props.categories.find((c) => c.id === form.category_id)
            ?.subcategories ?? []
    );
});

function close() {
    emit('close');
}

function selectValueToId(value: AcceptableValue): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const parsed = Number.parseInt(String(value), 10);

    return Number.isFinite(parsed) ? parsed : null;
}

function onCategoryChange(value: AcceptableValue) {
    form.category_id = selectValueToId(value);
    form.subcategory_id = null;
}

function onSubcategoryChange(value: AcceptableValue) {
    form.subcategory_id = selectValueToId(value);
}

function submit() {
    if (!props.product) {
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

    form.transform(() => transformed).patch(update(props.product.id).url, {
        onSuccess: () => {
            emit('saved');
            close();
        },
    });
}

function formatPrice(value: number | null): string {
    if (value === null) {
return '–';
}

    return value.toFixed(2).replace('.', ',') + ' €';
}

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

function formatRelativeDate(iso: string | null): string {
    if (!iso) {
return '–';
}

    const date = new Date(iso);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffHours = Math.round(diffMs / (1000 * 60 * 60));

    if (diffHours < 1) {
return 'just now';
}

    if (diffHours < 24) {
return `${diffHours}h ago`;
}

    const diffDays = Math.round(diffHours / 24);

    if (diffDays < 30) {
return `${diffDays}d ago`;
}

    return date.toISOString().slice(0, 10);
}
</script>

<template>
    <Dialog :open="!!product" @update:open="(v) => !v && close()">
        <DialogContent class="max-h-[90vh] max-w-3xl overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Edit Product</DialogTitle>
            </DialogHeader>

            <div
                v-if="product"
                class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600"
            >
                <span class="inline-flex items-center gap-1">
                    <Store :size="12" class="text-slate-400" />
                    {{ product.storeCount }} store{{
                        product.storeCount === 1 ? '' : 's'
                    }}
                </span>
                <span
                    class="inline-flex items-center gap-1 font-semibold text-emerald-700"
                >
                    {{ formatPrice(product.bestPrice) }}
                </span>
                <span class="inline-flex items-center gap-1">
                    <Clock :size="12" class="text-slate-400" />
                    {{ formatRelativeDate(product.lastSeenAt) }}
                </span>
                <span v-if="product.categoryName" class="text-slate-500">
                    {{ product.categoryName }}
                    <span
                        v-if="product.subcategoryName"
                        class="text-slate-400"
                    >
                        / {{ product.subcategoryName }}
                    </span>
                </span>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
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
                            :model-value="
                                form.subcategory_id?.toString() ?? ''
                            "
                            @update:model-value="onSubcategoryChange"
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
                    <div
                        class="flex items-center gap-2 text-sm font-semibold text-slate-900"
                    >
                        <Tag :size="14" />
                        Prices per store
                    </div>
                    <p class="text-xs text-slate-500">
                        Edit price, old price, discount, or condition. If
                        discount is left empty, it is recalculated from price
                        and old price.
                    </p>

                    <div class="space-y-2">
                        <div
                            v-for="(offer, idx) in form.offers"
                            :key="offer.snapshotId"
                            class="rounded-xl border border-slate-200 bg-slate-50/50 p-3"
                        >
                            <div
                                class="mb-2 flex items-center justify-between gap-2"
                            >
                                <div class="flex items-center gap-2">
                                    <StoreLogo
                                        :store-id="offer.storeSlug"
                                        :size="22"
                                    />
                                    <span
                                        class="text-sm font-semibold text-slate-900"
                                    >
                                        {{ offer.storeName }}
                                    </span>
                                    <span class="text-xs text-slate-500">
                                        {{ offer.variantLabel }}
                                    </span>
                                </div>
                                <span
                                    class="inline-flex items-center gap-1 text-xs text-slate-400"
                                >
                                    <Clock :size="11" />
                                    {{ formatRelativeDate(offer.lastSeenAt) }}
                                </span>
                            </div>

                            <div
                                class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4"
                            >
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
                                        v-model="
                                            form.offers[idx].oldPriceInput
                                        "
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
                                        v-model="
                                            form.offers[idx].discountInput
                                        "
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
                                        v-model="
                                            form.offers[idx].conditionInput
                                        "
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
                    <Button type="button" variant="outline" @click="close">
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        class="bg-emerald-600 hover:bg-emerald-700"
                    >
                        {{
                            form.processing
                                ? 'Saving…'
                                : (saveLabel ?? 'Save')
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
