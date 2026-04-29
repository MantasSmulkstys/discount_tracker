<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    Bell,
    ChefHat,
    Heart,
    Home,
    LogOut,
    Search,
    Settings,
    ShoppingCart,
    UserRound,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useKainuCart } from '@/composables/useKainuCart';
import { useKainuFavorites } from '@/composables/useKainuFavorites';
import { dashboard, login, logout, register } from '@/routes';
import { edit as editProfile } from '@/routes/profile';

type AuthUser = {
    id: number;
    name: string;
    email: string;
    role?: 'admin' | 'user';
    avatar?: string | null;
};

const page = usePage<{
    auth?: { user?: AuthUser | null };
}>();

const { totalCount, openDrawer } = useKainuCart();
const { favorites } = useKainuFavorites();

const user = computed(() => page.props.auth?.user ?? null);
const isAdmin = computed(() => user.value?.role === 'admin');

const currentPath = computed(() => {
    const url = page.url ?? '/';
    const idx = url.indexOf('?');

    return idx >= 0 ? url.slice(0, idx) : url;
});

const isActive = (prefix: string): boolean => {
    if (prefix === '/') {
        return currentPath.value === '/';
    }

    return currentPath.value.startsWith(prefix);
};

const searchQuery = ref<string>(
    typeof window !== 'undefined'
        ? (new URLSearchParams(window.location.search).get('q') ?? '')
        : '',
);

let debounceHandle: ReturnType<typeof setTimeout> | null = null;

watch(searchQuery, (q) => {
    if (debounceHandle) {
        clearTimeout(debounceHandle);
    }

    debounceHandle = setTimeout(() => {
        const params = new URLSearchParams(window.location.search);

        if (q) {
            params.set('q', q);
        } else {
            params.delete('q');
        }

        params.delete('page');
        router.get(
            `/products${params.toString() ? `?${params.toString()}` : ''}`,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});

const userInitials = computed(() => {
    if (!user.value) {
        return '?';
    }

    return user.value.name
        .split(' ')
        .map((p) => p.charAt(0).toUpperCase())
        .join('')
        .slice(0, 2);
});

const handleLogout = () => {
    router.post(
        logout().url,
        {},
        {
            onFinish: () => {
                router.flushAll();
            },
        },
    );
};
</script>

<template>
    <header
        class="sticky top-0 z-50 border-b border-kainu-border bg-white/88 backdrop-blur-lg"
    >
        <div class="mx-auto flex h-16 max-w-[1280px] items-center gap-6 px-6">
            <Link href="/" class="flex items-center gap-[10px]">
                <div
                    class="flex h-8 w-8 items-center justify-center rounded-[9px] bg-[#15803d] text-[15px] font-extrabold tracking-tighter text-white"
                >
                    KS
                </div>
                <div class="text-left">
                    <div
                        class="text-[15px] leading-none font-bold tracking-tight text-stone-900"
                    >
                        Kainų seklys
                    </div>
                    <div
                        class="mt-[2px] text-[10px] font-medium text-stone-500"
                    >
                        nuolaidos · 6 parduotuvės
                    </div>
                </div>
            </Link>

            <div class="relative hidden max-w-[480px] flex-1 md:block">
                <Search
                    :size="17"
                    class="pointer-events-none absolute top-1/2 left-[14px] -translate-y-1/2 text-stone-400"
                />
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Ieškoti prekių, pvz. „pienas“ arba „bananai“…"
                    class="h-10 w-full rounded-[10px] border border-kainu-border bg-kainu-surface pr-4 pl-10 text-[14px] text-stone-900 transition-all outline-none focus:border-[#15803d] focus:bg-white focus:shadow-[0_0_0_3px_rgba(21,128,61,0.12)]"
                />
            </div>

            <nav class="hidden items-center gap-1 lg:flex">
                <Link
                    href="/"
                    class="inline-flex items-center gap-2 rounded-[10px] px-[14px] py-[9px] text-[14px] transition-colors"
                    :class="
                        isActive('/') && currentPath === '/'
                            ? 'bg-brand-50 font-semibold text-[#15803d]'
                            : 'font-medium text-stone-700 hover:bg-stone-50'
                    "
                >
                    <Home :size="17" :stroke-width="1.75" />
                    Pradžia
                </Link>
                <Link
                    href="/products"
                    class="inline-flex items-center gap-2 rounded-[10px] px-[14px] py-[9px] text-[14px] transition-colors"
                    :class="
                        isActive('/products')
                            ? 'bg-brand-50 font-semibold text-[#15803d]'
                            : 'font-medium text-stone-700 hover:bg-stone-50'
                    "
                >
                    <BarChart3 :size="17" :stroke-width="1.75" />
                    Pasiūlymai
                </Link>
                <Link
                    v-if="isAdmin"
                    :href="dashboard().url"
                    class="inline-flex items-center gap-2 rounded-[10px] px-[14px] py-[9px] text-[14px] transition-colors"
                    :class="
                        isActive('/dashboard')
                            ? 'bg-brand-50 font-semibold text-[#15803d]'
                            : 'font-medium text-stone-700 hover:bg-stone-50'
                    "
                >
                    <ChefHat :size="17" :stroke-width="1.75" />
                    Valdymas
                </Link>
            </nav>

            <div class="ml-auto flex items-center gap-1">
                <button
                    type="button"
                    aria-label="Pranešimai"
                    class="relative flex h-10 w-10 items-center justify-center rounded-[10px] text-stone-700 transition-colors hover:bg-stone-100"
                >
                    <Bell :size="18" :stroke-width="1.75" />
                    <span
                        class="absolute top-[6px] right-[6px] h-[7px] w-[7px] rounded-full border-2 border-white bg-red-600"
                    />
                </button>

                <button
                    type="button"
                    aria-label="Mėgstami"
                    class="relative flex h-10 w-10 items-center justify-center rounded-[10px] text-stone-700 transition-colors hover:bg-stone-100"
                >
                    <Heart :size="18" :stroke-width="1.75" />
                    <span
                        v-if="favorites.size > 0"
                        class="absolute top-1 right-1 inline-flex min-w-[16px] items-center justify-center rounded-full bg-[#15803d] px-[5px] py-px text-[10px] font-bold text-white"
                    >
                        {{ favorites.size }}
                    </span>
                </button>

                <button
                    type="button"
                    aria-label="Krepšelis"
                    class="relative flex h-10 w-10 items-center justify-center rounded-[10px] text-stone-700 transition-colors hover:bg-stone-100"
                    @click="openDrawer"
                >
                    <ShoppingCart :size="18" :stroke-width="1.75" />
                    <span
                        v-if="totalCount > 0"
                        class="absolute top-1 right-1 inline-flex min-w-[16px] items-center justify-center rounded-full bg-[#15803d] px-[5px] py-px text-[10px] font-bold text-white"
                    >
                        {{ totalCount }}
                    </span>
                </button>

                <div class="mx-1 h-6 w-px bg-kainu-border" />

                <DropdownMenu v-if="user">
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            aria-label="Paskyra"
                            class="flex items-center gap-2 rounded-full p-0.5 transition-colors outline-none focus-visible:ring-2 focus-visible:ring-[#15803d]/40"
                        >
                            <img
                                v-if="user.avatar"
                                :src="user.avatar"
                                :alt="user.name"
                                class="h-9 w-9 rounded-full object-cover"
                            />
                            <span
                                v-else
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-amber-100 text-[13px] font-bold text-amber-900"
                            >
                                {{ userInitials }}
                            </span>
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        :side-offset="6"
                        class="min-w-[220px] rounded-[12px] border-kainu-border p-1"
                    >
                        <DropdownMenuLabel class="px-2 py-2">
                            <div class="flex flex-col gap-0.5">
                                <span
                                    class="truncate text-[13px] font-semibold text-stone-900"
                                >
                                    {{ user.name }}
                                </span>
                                <span
                                    class="truncate text-[11px] text-stone-500"
                                >
                                    {{ user.email }}
                                </span>
                            </div>
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem as-child>
                            <Link
                                :href="editProfile().url"
                                class="flex cursor-pointer items-center gap-2 rounded-[8px] px-2 py-2 text-[13px] text-stone-700"
                            >
                                <UserRound :size="15" :stroke-width="1.75" />
                                Mano paskyra
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem v-if="isAdmin" as-child>
                            <Link
                                :href="dashboard().url"
                                class="flex cursor-pointer items-center gap-2 rounded-[8px] px-2 py-2 text-[13px] text-stone-700"
                            >
                                <Settings :size="15" :stroke-width="1.75" />
                                Valdymas
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            class="flex cursor-pointer items-center gap-2 rounded-[8px] px-2 py-2 text-[13px] text-red-600 focus:bg-red-50 focus:text-red-700"
                            @select.prevent="handleLogout"
                            data-test="topnav-logout"
                        >
                            <LogOut :size="15" :stroke-width="1.75" />
                            Atsijungti
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <div v-else class="flex items-center gap-2">
                    <Link
                        :href="login().url"
                        class="hidden items-center rounded-[10px] px-3 py-2 text-[13px] font-medium text-stone-700 transition-colors hover:bg-stone-100 sm:inline-flex"
                    >
                        Prisijungti
                    </Link>
                    <Link
                        :href="register().url"
                        class="inline-flex items-center justify-center rounded-[10px] bg-stone-900 px-4 py-2 text-[13px] font-semibold text-white transition-colors hover:bg-[#15803d]"
                    >
                        Registruotis
                    </Link>
                </div>
            </div>
        </div>
    </header>
</template>
