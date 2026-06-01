<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Bell, BellOff } from 'lucide-vue-next';
import StoreLogo from '@/components/kainu/StoreLogo.vue';
import KainuLayout from '@/layouts/KainuLayout.vue';
import { formatPrice } from '@/lib/kainu';
import subscriptionRoutes from '@/routes/subscriptions';

defineOptions({ layout: KainuLayout });

type Subscription = {
    id: number;
    familyId: number | null;
    familyName: string;
    createdAt: string | null;
    lastSeen: null | {
        name: string;
        storeSlug: string;
        storeName: string;
        price: number;
        oldPrice: number | null;
        image: string | null;
    };
};

defineProps<{
    subscriptions: Subscription[];
}>();

const handleUnsubscribe = (sub: Subscription) => {
    router.delete(
        subscriptionRoutes.destroy({ familySubscription: sub.id }).url,
        {
            preserveScroll: true,
        },
    );
};
</script>

<template>
    <Head title="Mano sekimai · Kainų seklys" />

    <section class="pt-8 pb-2">
        <h1 class="m-0 text-[28px] font-bold tracking-tight text-stone-900">
            Mano sekimai
        </h1>
        <p class="mt-2 mb-0 max-w-[640px] text-[14px] text-stone-600">
            Prekės, kurių akcijas norite stebėti. Apie naujas akcijas
            pranešime el. paštu.
        </p>
    </section>

    <section v-if="subscriptions.length === 0" class="mt-6">
        <div
            class="rounded-[12px] border border-dashed border-kainu-border bg-white p-10 text-center"
        >
            <Bell
                :size="32"
                :stroke-width="1.5"
                class="mx-auto mb-3 text-stone-400"
            />
            <p class="m-0 text-[14px] font-medium text-stone-700">
                Dar nesekate jokios prekės
            </p>
            <p
                class="mx-auto mt-1 mb-0 max-w-[420px] text-[12px] text-stone-500"
            >
                Pasiūlymų sąraše spauskite varpelio ikoną prie prekės — kai ji
                bus rasta naujose akcijose, gausite pranešimą.
            </p>
        </div>
    </section>

    <section v-else class="mt-6 flex flex-col gap-3">
        <article
            v-for="sub in subscriptions"
            :key="sub.id"
            class="flex flex-wrap items-center gap-4 rounded-[12px] border border-kainu-border bg-white px-5 py-4"
        >
            <div class="min-w-0 flex-1">
                <h3
                    class="m-0 text-[15px] font-semibold tracking-tight text-stone-900"
                >
                    {{ sub.familyName }}
                </h3>
                <p
                    v-if="sub.lastSeen"
                    class="mt-1 text-[12px] text-stone-500"
                >
                    Naujausias pasiūlymas:
                    <span class="font-medium text-stone-700">
                        {{ sub.lastSeen.name }}
                    </span>
                </p>
                <p v-else class="mt-1 text-[12px] text-stone-400">
                    Šiuo metu nėra šios prekės akcijoje
                </p>
            </div>

            <div v-if="sub.lastSeen" class="flex items-center gap-3">
                <StoreLogo
                    :store-id="sub.lastSeen.storeSlug"
                    :size="22"
                    :width="80"
                />
                <div
                    class="text-[15px] font-bold tracking-tight text-stone-900 tabular-nums"
                >
                    {{ formatPrice(sub.lastSeen.price) }}
                </div>
            </div>

            <button
                type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-[10px] border border-kainu-border bg-white px-3 text-[12px] font-medium text-stone-700 transition-colors hover:bg-kainu-surface"
                @click="handleUnsubscribe(sub)"
            >
                <BellOff :size="13" :stroke-width="2" />
                Nebesekti
            </button>
        </article>
    </section>
</template>
