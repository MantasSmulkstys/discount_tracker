<script setup lang="ts">
import CartDrawer from '@/components/kainu/CartDrawer.vue';
import ScraperNoticeBanner from '@/components/kainu/ScraperNoticeBanner.vue';
import TopNav from '@/components/kainu/TopNav.vue';
</script>

<template>
    <div class="min-h-screen bg-[#fafaf7] text-stone-900">
        <ScraperNoticeBanner />
        <TopNav />

        <main class="mx-auto max-w-[1280px] px-6 pb-16">
            <slot />
        </main>

        <footer
            class="border-t border-[#e7e5e0] bg-white py-8 text-center text-[12px] text-stone-500"
        >
            <div class="mx-auto max-w-[1280px] px-6">
                <p class="m-0">
                    Kainų seklys · Sekame kainas 6 didžiausiuose Lietuvos
                    prekybos tinkluose
                </p>
                <p class="m-0 mt-1 text-[11px] text-stone-400">
                    Nesame susiję su jokia parduotuve. Kainos atnaujinamos kas
                    parą.
                </p>
            </div>
        </footer>

        <CartDrawer />
    </div>
</template>
