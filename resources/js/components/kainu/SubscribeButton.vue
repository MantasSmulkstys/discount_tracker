<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Bell, BellOff, Check, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FamilySubscriptionsController from '@/actions/App/Http/Controllers/FamilySubscriptionsController';

type Props = {
    familyId: number | null | undefined;
};

const props = defineProps<Props>();

const page = usePage<{
    auth?: { user?: { id: number } | null };
    subscribedFamilies?: Record<string, number>;
}>();

const isAuthenticated = computed(() => Boolean(page.props.auth?.user));

const subscriptionId = computed<number | null>(() => {
    if (props.familyId === null || props.familyId === undefined) {
        return null;
    }
    const map = page.props.subscribedFamilies ?? {};
    const id = map[String(props.familyId)];

    return typeof id === 'number' ? id : null;
});

const isSubscribed = computed(() => subscriptionId.value !== null);

const showVisible = computed(
    () =>
        isAuthenticated.value &&
        props.familyId !== null &&
        props.familyId !== undefined,
);

const modalOpen = ref(false);
const pending = ref(false);

const openModal = (event: MouseEvent) => {
    event.preventDefault();
    event.stopPropagation();
    if (!showVisible.value) {
        return;
    }
    modalOpen.value = true;
};

const closeModal = () => {
    modalOpen.value = false;
};

const handleConfirm = () => {
    if (props.familyId === null || props.familyId === undefined) {
        return;
    }

    pending.value = true;

    if (isSubscribed.value && subscriptionId.value !== null) {
        router.delete(
            FamilySubscriptionsController.destroy({
                familySubscription: subscriptionId.value,
            }).url,
            {
                preserveScroll: true,
                preserveState: false,
                onFinish: () => {
                    pending.value = false;
                    closeModal();
                },
            },
        );
    } else {
        router.post(
            FamilySubscriptionsController.store().url,
            { family_id: props.familyId },
            {
                preserveScroll: true,
                preserveState: false,
                onFinish: () => {
                    pending.value = false;
                    closeModal();
                },
            },
        );
    }
};
</script>

<template>
    <button
        v-if="showVisible"
        type="button"
        :aria-label="isSubscribed ? 'Nebesekti' : 'Sekti'"
        :title="isSubscribed ? 'Nebesekti šios prekės' : 'Sekti šią prekę'"
        class="flex h-7 w-7 items-center justify-center rounded-full border transition-colors"
        :class="
            isSubscribed
                ? 'border-amber-300 bg-amber-50 text-amber-600 hover:bg-amber-100'
                : 'border-kainu-border bg-white/90 text-stone-400 hover:border-amber-300 hover:bg-amber-50 hover:text-amber-500'
        "
        @click="openModal"
    >
        <Bell v-if="!isSubscribed" :size="13" :stroke-width="2" />
        <BellOff v-else :size="13" :stroke-width="2" />
    </button>

    <Teleport to="body">
        <div
            v-if="modalOpen"
            class="fixed inset-0 z-[70] flex items-center justify-center bg-stone-900/40 px-4"
            @click.self="closeModal"
        >
            <div
                class="w-full max-w-md overflow-hidden rounded-[16px] bg-white shadow-2xl"
                role="dialog"
                aria-label="Sekti prekę"
            >
                <div class="border-b border-kainu-border px-5 py-4">
                    <h3
                        class="m-0 text-[16px] font-bold tracking-tight text-stone-900"
                    >
                        {{
                            isSubscribed
                                ? 'Nebesekti šios prekės'
                                : 'Sekti šią prekę?'
                        }}
                    </h3>
                    <p class="mt-1 text-[12px] text-stone-500">
                        <template v-if="isSubscribed">
                            Daugiau nesiųsime pranešimų apie šios prekės
                            akcijas.
                        </template>
                        <template v-else>
                            Pranešime el. paštu, kai ši prekė bus rasta naujose
                            savaitės akcijose.
                        </template>
                    </p>
                </div>
                <div
                    class="flex items-center justify-end gap-2 border-t border-kainu-border bg-kainu-surface px-5 py-3"
                >
                    <button
                        type="button"
                        :disabled="pending"
                        class="inline-flex h-9 items-center rounded-[10px] border border-kainu-border bg-white px-3 text-[13px] font-medium text-stone-700 hover:bg-stone-50"
                        @click="closeModal"
                    >
                        <X :size="14" :stroke-width="2" class="mr-1" />
                        Atšaukti
                    </button>
                    <button
                        type="button"
                        :disabled="pending"
                        class="inline-flex h-9 items-center gap-1.5 rounded-[10px] bg-brand-700 px-3 text-[13px] font-semibold text-white hover:bg-brand-800 disabled:cursor-wait disabled:opacity-60"
                        @click="handleConfirm"
                    >
                        <Check :size="14" :stroke-width="2" />
                        {{ isSubscribed ? 'Nebesekti' : 'Sekti' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
