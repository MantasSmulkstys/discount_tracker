<script setup lang="ts">
import { AlertTriangle, X } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

const STORAGE_KEY = 'kainu.scraperNoticeDismissed';

const visible = ref(false);

onMounted(() => {
    try {
        visible.value = localStorage.getItem(STORAGE_KEY) !== '1';
    } catch {
        visible.value = true;
    }
});

const dismiss = () => {
    visible.value = false;
    try {
        localStorage.setItem(STORAGE_KEY, '1');
    } catch {
        // localStorage unavailable (private mode, etc.) — banner will reappear next visit
    }
};
</script>

<template>
    <div
        v-if="visible"
        role="status"
        class="border-b border-amber-200 bg-amber-50 text-amber-900"
    >
        <div
            class="mx-auto flex max-w-[1280px] items-start gap-3 px-6 py-2.5 text-[13px] leading-snug sm:items-center"
        >
            <AlertTriangle
                :size="16"
                :stroke-width="2"
                class="mt-0.5 shrink-0 sm:mt-0"
            />
            <p class="m-0 flex-1">
                Kainos surenkamos automatiškai ir kartais gali būti netikslios.
                Pastebėjote klaidą? Spauskite
                <AlertTriangle
                    :size="13"
                    :stroke-width="2"
                    class="-mt-0.5 inline align-middle"
                />
                ant prekės kortelės ir praneškite.
            </p>
            <button
                type="button"
                aria-label="Uždaryti pranešimą"
                class="-mr-1 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-amber-900/70 transition-colors hover:bg-amber-100 hover:text-amber-900 focus-visible:ring-2 focus-visible:ring-amber-600/40 focus-visible:outline-none"
                @click="dismiss"
            >
                <X :size="15" :stroke-width="2" />
            </button>
        </div>
    </div>
</template>
