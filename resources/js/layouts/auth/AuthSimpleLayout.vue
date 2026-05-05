<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Home } from 'lucide-vue-next';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="min-h-svh bg-kainu-surface">
        <header class="border-b border-kainu-border bg-white">
            <nav
                class="mx-auto flex h-16 w-full max-w-6xl items-center justify-between px-5 sm:px-6 lg:px-8"
            >
                <Link :href="home().url" class="inline-flex items-center gap-3">
                    <span
                        class="flex h-9 w-9 items-center justify-center rounded-[9px] bg-[#15803d] text-[15px] font-extrabold tracking-tighter text-white"
                    >
                        <AppLogoIcon class="size-5 invert" />
                    </span>
                    <span class="text-left">
                        <span
                            class="block text-[15px] leading-none font-bold tracking-tight text-stone-900"
                        >
                            Kainų seklys
                        </span>
                        <span
                            class="mt-[2px] block text-[10px] font-medium text-stone-500"
                        >
                            nuolaidos · 6 parduotuvės
                        </span>
                    </span>
                </Link>

                <Link
                    :href="home().url"
                    class="inline-flex h-10 items-center gap-2 rounded-[10px] border border-kainu-border bg-white px-3 text-[13px] font-semibold text-stone-700 transition hover:bg-stone-50 hover:text-stone-950"
                >
                    <Home :size="16" :stroke-width="1.8" />
                    <span>Pradžia</span>
                </Link>
            </nav>
        </header>

        <main
            class="mx-auto flex min-h-[calc(100svh-4rem)] w-full max-w-6xl items-center justify-center px-5 py-10 sm:px-6 lg:px-8"
        >
            <div
                class="flex w-full max-w-md flex-col gap-7 rounded-[16px] border border-kainu-border bg-white px-6 py-8 shadow-[0_10px_40px_-24px_rgba(15,23,42,0.24)] sm:px-8"
            >
                <div class="space-y-2">
                    <h1
                        v-if="title"
                        class="text-[24px] font-semibold tracking-tight text-stone-950"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="text-[14px] leading-6 text-stone-500"
                    >
                        {{ description }}
                    </p>
                </div>

                <slot />
            </div>
        </main>
    </div>
</template>
