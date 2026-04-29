<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    ChefHat,
    Flame,
    Sparkles,
    TrendingUp,
} from 'lucide-vue-next';
import CategoryPills from '@/components/kainu/CategoryPills.vue';
import ProductCard from '@/components/kainu/ProductCard.vue';
import StoreLogo from '@/components/kainu/StoreLogo.vue';
import { useKainuCart } from '@/composables/useKainuCart';
import { useKainuFavorites } from '@/composables/useKainuFavorites';
import type { KainuProduct, KainuStoreCount } from '@/types/kainu';

defineProps<{
    canRegister: boolean;
    topDeals: KainuProduct[];
    trending: KainuProduct[];
    stores: KainuStoreCount[];
    stats: {
        offersCount: number;
        storeCount: number;
        averageDiscount: number;
    };
}>();

const { addToCart, openDrawer } = useKainuCart();
const { toggle, isFavorited } = useKainuFavorites();

function handleAdd(product: KainuProduct) {
    addToCart(product);
    openDrawer();
}
</script>

<template>
    <Head title="Pradžia" />

    <section class="pt-6 pb-8">
        <div
            class="relative grid grid-cols-1 gap-8 overflow-hidden rounded-[20px] px-8 py-10 text-white md:grid-cols-[1fr_auto] md:px-10"
            style="
                background: linear-gradient(135deg, #15803d 0%, #16a34a 100%);
            "
        >
            <div
                class="pointer-events-none absolute inset-0 opacity-[0.08]"
                style="
                    background:
                        radial-gradient(
                            circle at 85% 30%,
                            #fff 0,
                            transparent 45%
                        ),
                        radial-gradient(
                            circle at 15% 80%,
                            #fff 0,
                            transparent 35%
                        );
                "
            />

            <div class="relative z-[1]">
                <div
                    class="mb-[14px] inline-flex items-center gap-[6px] rounded-full bg-white/20 px-[10px] py-[5px] text-[12px] font-semibold backdrop-blur"
                >
                    <Flame :size="14" :stroke-width="1.75" />
                    Šios savaitės pasiūlymai
                </div>
                <h1
                    class="mb-[10px] text-[38px] leading-[1.05] font-bold tracking-tighter"
                >
                    Sutaupykite iki {{ stats.averageDiscount || 40 }}%<br />
                    visose
                    {{ stats.storeCount > 0 ? stats.storeCount : 6 }}
                    parduotuvėse
                </h1>
                <p
                    class="mb-5 max-w-[440px] text-[15px] leading-[1.5] opacity-90"
                >
                    Palyginkite kainas Maxima, Lidl, Iki, Rimi, Norfa ir Aibėje.
                    Mes stebime, jūs taupote.
                </p>
                <div class="flex flex-wrap gap-[10px]">
                    <Link
                        href="/products"
                        class="inline-flex items-center gap-[6px] rounded-[10px] bg-white px-5 py-[11px] text-[14px] font-semibold text-[#15803d] transition-colors hover:bg-stone-100"
                    >
                        Žiūrėti visas nuolaidas
                        <ArrowRight :size="16" :stroke-width="2.25" />
                    </Link>
                    <Link
                        href="/products?discount=1"
                        class="inline-flex items-center gap-[6px] rounded-[10px] border border-white/30 bg-white/15 px-5 py-[11px] text-[14px] font-medium text-white backdrop-blur transition-colors hover:bg-white/25"
                    >
                        Tik su nuolaida
                    </Link>
                </div>
            </div>

            <div
                class="relative z-[1] min-w-[220px] rounded-[16px] border border-white/20 bg-white/12 p-5 backdrop-blur"
            >
                <div
                    class="mb-2 text-[11px] font-semibold tracking-[0.05em] uppercase opacity-85"
                >
                    Šią savaitę platformoje
                </div>
                <div
                    class="text-[36px] leading-none font-bold tracking-tighter"
                >
                    {{ stats.offersCount.toLocaleString('lt-LT') }}
                </div>
                <div
                    class="mt-[6px] flex items-center gap-1 text-[12px] opacity-85"
                >
                    <TrendingUp :size="14" :stroke-width="1.75" />
                    pasiūlymai atnaujinami kasdien
                </div>
                <div
                    class="mt-[14px] flex gap-4 border-t border-white/20 pt-[14px]"
                >
                    <div>
                        <div class="text-[18px] font-bold">
                            {{ stats.storeCount }}
                        </div>
                        <div class="text-[11px] opacity-80">parduotuvės</div>
                    </div>
                    <div>
                        <div class="text-[18px] font-bold">
                            {{ stats.averageDiscount }}%
                        </div>
                        <div class="text-[11px] opacity-80">vid. nuolaida</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="mb-8">
        <CategoryPills active="all" />
    </section>

    <section class="mb-10">
        <div class="mb-4 flex items-baseline justify-between">
            <div>
                <h2
                    class="m-0 text-[22px] font-semibold tracking-tight text-stone-900"
                >
                    Geriausios šios savaitės nuolaidos
                </h2>
                <p class="mt-[4px] text-[13px] text-stone-500">
                    Daugiausiai sutaupoma
                </p>
            </div>
            <Link
                href="/products?sort=discount"
                class="inline-flex items-center gap-1 text-[13px] font-semibold text-[#15803d] hover:underline"
            >
                Visos
                <ArrowRight :size="14" :stroke-width="2.25" />
            </Link>
        </div>

        <div
            v-if="topDeals.length === 0"
            class="rounded-[12px] border border-dashed border-[#d6d3d1] bg-white p-12 text-center text-stone-500"
        >
            Dar neatnaujinti šios savaitės pasiūlymai. Patikrinkite vėliau.
        </div>

        <div
            v-else
            class="grid grid-cols-2 gap-[14px] sm:grid-cols-3 lg:grid-cols-4"
        >
            <ProductCard
                v-for="p in topDeals"
                :key="p.id"
                :product="p"
                :favorited="isFavorited(p.id)"
                @add-to-cart="handleAdd"
                @toggle-favorite="toggle(p.id)"
            />
        </div>
    </section>

    <section class="mb-10">
        <h2
            class="m-0 mb-4 text-[22px] font-semibold tracking-tight text-stone-900"
        >
            Parduotuvės
        </h2>
        <div class="grid grid-cols-2 gap-[10px] sm:grid-cols-3 lg:grid-cols-6">
            <Link
                v-for="s in stores"
                :key="s.id"
                :href="`/products?stores=${s.id}`"
                class="flex flex-col items-center gap-2 rounded-[12px] border border-[#e7e5e0] bg-white px-3 py-4 transition-colors hover:border-stone-300"
            >
                <StoreLogo :store-id="s.id" :size="36" />
                <div class="text-[13px] font-semibold text-stone-900">
                    {{ s.name }}
                </div>
                <div class="text-[11px] text-stone-500">
                    {{ s.count.toLocaleString('lt-LT') }} pasiūlymų
                </div>
            </Link>
        </div>
    </section>

    <section v-if="trending.length > 0" class="mb-10">
        <div class="mb-4 flex items-baseline justify-between">
            <div>
                <h2
                    class="m-0 inline-flex items-center gap-2 text-[22px] font-semibold tracking-tight text-stone-900"
                >
                    <Sparkles :size="20" class="text-[#15803d]" />
                    Šviežiausi pasiūlymai
                </h2>
                <p class="mt-[4px] text-[13px] text-stone-500">
                    Ką tik pridėti į platformą
                </p>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-[14px] sm:grid-cols-3 lg:grid-cols-4">
            <ProductCard
                v-for="p in trending"
                :key="p.id"
                :product="p"
                :favorited="isFavorited(p.id)"
                @add-to-cart="handleAdd"
                @toggle-favorite="toggle(p.id)"
            />
        </div>
    </section>

    <section class="mb-10">
        <div
            class="grid grid-cols-1 items-center gap-8 rounded-[16px] border border-[#e7e5e0] bg-[#fafaf7] px-8 py-7 md:grid-cols-[1fr_auto]"
        >
            <div>
                <div
                    class="mb-2 inline-flex items-center gap-[6px] text-[11px] font-semibold tracking-[0.05em] text-[#15803d] uppercase"
                >
                    <ChefHat :size="14" :stroke-width="1.75" />
                    Receptai iš nuolaidų
                </div>
                <h2
                    class="m-0 mb-[6px] text-[22px] font-semibold tracking-tight text-stone-900"
                >
                    Kas gaminama šią savaitę?
                </h2>
                <p
                    class="m-0 max-w-[540px] text-[14px] leading-[1.5] text-stone-600"
                >
                    Sukurkite savaitės meniu iš prekių, kurios dabar pigiausios.
                    Vidutiniškai 3 receptai šeimai, sutaupydami 12–18 €.
                </p>
            </div>
            <button
                type="button"
                class="inline-flex items-center gap-[6px] rounded-[10px] bg-stone-900 px-[22px] py-3 text-[14px] font-semibold text-white transition-colors hover:bg-[#15803d]"
            >
                Generuoti meniu
                <ArrowRight :size="16" :stroke-width="2.25" />
            </button>
        </div>
    </section>
</template>
