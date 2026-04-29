<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Minus, Plus, ShoppingCart, X } from 'lucide-vue-next';
import { onMounted, onUnmounted, watch } from 'vue';
import ProductImage from '@/components/kainu/ProductImage.vue';
import StoreLogo from '@/components/kainu/StoreLogo.vue';
import { useKainuCart } from '@/composables/useKainuCart';
import { formatPrice } from '@/lib/kainu';

const {
    items,
    isOpen,
    storeGroups,
    subtotal,
    savings,
    closeDrawer,
    increment,
    decrement,
    remove,
} = useKainuCart();

function handleKeyDown(event: KeyboardEvent) {
    if (event.key === 'Escape' && isOpen.value) {
        closeDrawer();
    }
}

onMounted(() => {
    window.addEventListener('keydown', handleKeyDown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeyDown);
});

watch(isOpen, (open) => {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.style.overflow = open ? 'hidden' : '';
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200"
            leave-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div
                v-if="isOpen"
                class="fixed inset-0 z-[60] bg-stone-900/40 backdrop-blur-[2px]"
                @click="closeDrawer"
            />
        </Transition>

        <aside
            class="fixed top-0 right-0 bottom-0 z-[61] flex w-[420px] max-w-full flex-col bg-[#fafaf7] shadow-[-16px_0_48px_rgba(0,0,0,0.12)] transition-transform duration-[280ms] ease-[cubic-bezier(0.32,0.72,0,1)]"
            :style="{
                transform: isOpen ? 'translateX(0)' : 'translateX(100%)',
            }"
            role="dialog"
            aria-label="Krepšelis"
        >
            <div
                class="flex items-center justify-between border-b border-[#e7e5e0] px-6 py-5"
            >
                <div>
                    <h2 class="m-0 text-[18px] font-bold tracking-tight">
                        Krepšelis
                    </h2>
                    <div class="mt-[2px] text-[12px] text-stone-500">
                        {{ items.length }}
                        {{ items.length === 1 ? 'prekė' : 'prekės (-ių)' }}
                    </div>
                </div>
                <button
                    type="button"
                    aria-label="Uždaryti"
                    class="flex items-center justify-center rounded p-2 text-stone-700 transition-colors hover:bg-stone-100"
                    @click="closeDrawer"
                >
                    <X :size="20" />
                </button>
            </div>

            <div class="flex-1 overflow-y-auto p-4">
                <div
                    v-if="items.length === 0"
                    class="p-12 text-center text-stone-500"
                >
                    <ShoppingCart
                        :size="40"
                        class="mx-auto mb-3 text-stone-300"
                    />
                    <div class="mb-1 font-medium text-stone-900">
                        Krepšelis tuščias
                    </div>
                    <div class="text-[13px]">
                        Pridėkite prekių su geriausia kaina
                    </div>
                </div>

                <template v-else>
                    <div
                        v-for="group in storeGroups"
                        :key="group.storeId"
                        class="mb-4 overflow-hidden rounded-[12px] border border-[#e7e5e0] bg-white"
                    >
                        <div
                            class="flex items-center gap-2 border-b border-[#e7e5e0] bg-[#fafaf7] px-[14px] py-[10px]"
                        >
                            <StoreLogo
                                :store-id="group.storeId"
                                :size="20"
                                :show-name="true"
                            />
                            <div class="flex-1" />
                            <div class="text-[11px] font-medium text-stone-500">
                                {{ group.items.length }}
                                {{
                                    group.items.length === 1
                                        ? 'prekė'
                                        : 'prekės'
                                }}
                            </div>
                        </div>

                        <div
                            v-for="i in group.items"
                            :key="`${i.id}-${i.storeId}`"
                            class="flex items-center gap-3 border-b border-[#f5f5f0] p-3 last:border-0"
                        >
                            <ProductImage
                                :seed="i.id"
                                :src="i.product.image"
                                :name="i.product.name"
                                :emoji="i.product.emoji"
                                :size="48"
                            />
                            <div class="min-w-0 flex-1">
                                <div
                                    class="truncate text-[13px] font-medium text-stone-900"
                                >
                                    {{ i.product.name }}
                                </div>
                                <div class="text-[11px] text-stone-500">
                                    <template v-if="i.product.unit">
                                        {{ i.product.unit }} ·
                                    </template>
                                    {{ formatPrice(i.product.bestPrice) }}
                                </div>
                            </div>

                            <div
                                class="flex items-center overflow-hidden rounded-[8px] border border-[#e7e5e0]"
                            >
                                <button
                                    type="button"
                                    aria-label="Mažinti"
                                    class="flex h-[26px] w-[26px] items-center justify-center text-stone-700 hover:bg-stone-100"
                                    @click="decrement(i.id, i.storeId)"
                                >
                                    <Minus :size="12" />
                                </button>
                                <div
                                    class="min-w-[22px] text-center text-[12px] font-semibold"
                                >
                                    {{ i.qty }}
                                </div>
                                <button
                                    type="button"
                                    aria-label="Daugiau"
                                    class="flex h-[26px] w-[26px] items-center justify-center text-stone-700 hover:bg-stone-100"
                                    @click="increment(i.id, i.storeId)"
                                >
                                    <Plus :size="12" />
                                </button>
                            </div>

                            <div
                                class="min-w-[60px] text-right text-[14px] font-bold text-stone-900"
                            >
                                {{
                                    formatPrice(
                                        (i.product.bestPrice ?? 0) * i.qty,
                                    )
                                }}
                            </div>

                            <button
                                type="button"
                                aria-label="Šalinti"
                                class="flex h-7 w-7 items-center justify-center rounded text-stone-400 hover:bg-red-50 hover:text-red-600"
                                @click="remove(i.id, i.storeId)"
                            >
                                <X :size="14" />
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            <div
                v-if="items.length > 0"
                class="border-t border-[#e7e5e0] bg-white p-5"
            >
                <div
                    class="mb-[6px] flex justify-between text-[13px] text-stone-500"
                >
                    <span>Tarpinė suma</span>
                    <span>{{ formatPrice(subtotal) }}</span>
                </div>
                <div
                    v-if="savings > 0"
                    class="mb-[6px] flex justify-between text-[13px] font-medium text-[#15803d]"
                >
                    <span>Sutaupėte</span>
                    <span>−{{ formatPrice(savings) }}</span>
                </div>
                <div
                    class="mt-[10px] flex justify-between border-t border-[#e7e5e0] pt-[10px] text-[17px] font-bold text-stone-900"
                >
                    <span>Viso</span>
                    <span>{{ formatPrice(subtotal) }}</span>
                </div>

                <Link
                    href="/cart"
                    class="mt-[14px] flex w-full items-center justify-center gap-2 rounded-[10px] bg-brand-700 px-4 py-[14px] text-[14px] font-semibold text-white transition-colors hover:bg-brand-800"
                    @click="closeDrawer"
                >
                    Peržiūrėti krepšelį · {{ formatPrice(subtotal) }}
                    <ArrowRight :size="16" :stroke-width="2" />
                </Link>
                <button
                    type="button"
                    class="mt-[6px] w-full rounded px-4 py-2 text-[12px] font-medium text-stone-700 transition-colors hover:bg-stone-100"
                >
                    Išsaugoti kaip savaitės sąrašą
                </button>
            </div>
        </aside>
    </Teleport>
</template>
