<script setup lang="ts">
import CartDrawer from '@/components/kainu/CartDrawer.vue';
import ScraperNoticeBanner from '@/components/kainu/ScraperNoticeBanner.vue';
import TopNav from '@/components/kainu/TopNav.vue';
</script>

<template>
    <div class="min-h-screen bg-[#fafaf7] text-stone-900">
        <ScraperNoticeBanner />
        <TopNav />

        <main class="mx-auto max-w-[1280px] px-4 pb-16 sm:px-6">
            <slot />
        </main>

        <footer
            class="border-t border-[#e7e5e0] bg-white py-8 text-center text-[12px] text-stone-500"
        >
            <div class="mx-auto max-w-[1280px] px-4 sm:px-6">
                <p class="m-0">
                    Kainų seklys
                </p>
            </div>
        </footer>

        <CartDrawer />
    </div>
</template>
