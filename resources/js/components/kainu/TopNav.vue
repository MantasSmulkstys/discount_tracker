<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Bell,
    ChefHat,
    LineChart,
    ListChecks,
    LogOut,
    Menu,
    RefreshCw,
    Settings,
    ShoppingCart,
    UserRound,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useKainuCart } from '@/composables/useKainuCart';
import { home, login, logout } from '@/routes';
import { reports as adminReports } from '@/routes/admin';
import { index as adminScrape } from '@/routes/admin/scrape';
import { index as cartIndex } from '@/routes/cart';
import { edit as editProfile } from '@/routes/profile';
import { index as savedCartsIndex } from '@/routes/saved-carts';
import { index as statsIndex } from '@/routes/stats';
import { index as subscriptionsIndex } from '@/routes/subscriptions';

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

const { totalCount } = useKainuCart();

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
        <div
            class="mx-auto flex h-16 max-w-[1280px] items-center gap-3 px-4 sm:gap-4 sm:px-6 lg:gap-6"
        >
            <Link :href="home().url" class="flex items-center gap-[10px]">
                <div
                    class="flex h-8 w-8 items-center justify-center rounded-[9px] bg-[#15803d] text-[15px] font-extrabold tracking-tighter text-white"
                >
                    <AppLogoIcon class="size-5 invert" />
                </div>
                <div class="text-left">
                    <div
                        class="text-[15px] leading-none font-bold tracking-tight text-stone-900"
                    >
                        Kainų seklys
                    </div>
                </div>
            </Link>

            <nav class="hidden items-center gap-1 lg:flex">
                <Link
                    v-if="user"
                    :href="statsIndex().url"
                    class="inline-flex items-center gap-2 rounded-[10px] px-[14px] py-[9px] text-[14px] transition-colors"
                    :class="
                        isActive('/statistika')
                            ? 'bg-brand-50 font-semibold text-[#15803d]'
                            : 'font-medium text-stone-700 hover:bg-stone-50'
                    "
                >
                    <LineChart :size="16" :stroke-width="1.75" />
                    Statistika
                </Link>
                <Link
                    v-if="isAdmin"
                    :href="adminReports().url"
                    class="inline-flex items-center gap-2 rounded-[10px] px-[14px] py-[9px] text-[14px] transition-colors"
                    :class="
                        isActive('/admin/reports')
                            ? 'bg-brand-50 font-semibold text-[#15803d]'
                            : 'font-medium text-stone-700 hover:bg-stone-50'
                    "
                >
                    <ChefHat :size="17" :stroke-width="1.75" />
                    Valdymas
                </Link>
                <Link
                    v-if="isAdmin"
                    :href="adminScrape().url"
                    class="inline-flex items-center gap-2 rounded-[10px] px-[14px] py-[9px] text-[14px] transition-colors"
                    :class="
                        isActive('/admin/scrape')
                            ? 'bg-brand-50 font-semibold text-[#15803d]'
                            : 'font-medium text-stone-700 hover:bg-stone-50'
                    "
                >
                    <RefreshCw :size="16" :stroke-width="1.75" />
                    Duomenų tvarkymas
                </Link>
            </nav>

            <div class="ml-auto flex items-center gap-1">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            aria-label="Meniu"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-[10px] text-stone-700 transition-colors hover:bg-stone-100 lg:hidden"
                        >
                            <Menu :size="20" :stroke-width="1.75" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        :side-offset="6"
                        class="min-w-[220px] rounded-[12px] border-kainu-border p-1"
                    >
                        <DropdownMenuItem v-if="user" as-child>
                            <Link
                                :href="statsIndex().url"
                                class="flex cursor-pointer items-center gap-2 rounded-[8px] px-2 py-2 text-[13px] text-stone-700"
                            >
                                <LineChart :size="15" :stroke-width="1.75" />
                                Statistika
                            </Link>
                        </DropdownMenuItem>
                        <template v-if="isAdmin">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem as-child>
                                <Link
                                    :href="adminReports().url"
                                    class="flex cursor-pointer items-center gap-2 rounded-[8px] px-2 py-2 text-[13px] text-stone-700"
                                >
                                    <ChefHat :size="15" :stroke-width="1.75" />
                                    Valdymas
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem as-child>
                                <Link
                                    :href="adminScrape().url"
                                    class="flex cursor-pointer items-center gap-2 rounded-[8px] px-2 py-2 text-[13px] text-stone-700"
                                >
                                    <RefreshCw :size="15" :stroke-width="1.75" />
                                    Duomenų tvarkymas
                                </Link>
                            </DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>

                <Link
                    v-if="user"
                    :href="cartIndex().url"
                    aria-label="Krepšelis"
                    class="relative inline-flex h-10 items-center gap-2 rounded-[10px] px-3 text-stone-700 transition-colors hover:bg-stone-100"
                >
                    <ShoppingCart :size="18" :stroke-width="1.75" />
                    <span class="hidden text-[14px] font-medium sm:inline">
                        Krepšelis
                    </span>
                    <span
                        v-if="totalCount > 0"
                        class="inline-flex min-w-[18px] items-center justify-center rounded-full bg-[#15803d] px-[6px] py-px text-[11px] font-bold text-white"
                    >
                        {{ totalCount }}
                    </span>
                </Link>

                <div v-if="user" class="mx-1 h-6 w-px bg-kainu-border" />

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
                        <DropdownMenuItem as-child>
                            <Link
                                :href="savedCartsIndex().url"
                                class="flex cursor-pointer items-center gap-2 rounded-[8px] px-2 py-2 text-[13px] text-stone-700"
                            >
                                <ListChecks :size="15" :stroke-width="1.75" />
                                Mano sąrašai
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem as-child>
                            <Link
                                :href="subscriptionsIndex().url"
                                class="flex cursor-pointer items-center gap-2 rounded-[8px] px-2 py-2 text-[13px] text-stone-700"
                            >
                                <Bell :size="15" :stroke-width="1.75" />
                                Mano sekimai
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem v-if="isAdmin" as-child>
                            <Link
                                :href="adminReports().url"
                                class="flex cursor-pointer items-center gap-2 rounded-[8px] px-2 py-2 text-[13px] text-stone-700"
                            >
                                <Settings :size="15" :stroke-width="1.75" />
                                Valdymas
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem v-if="isAdmin" as-child>
                            <Link
                                :href="adminScrape().url"
                                class="flex cursor-pointer items-center gap-2 rounded-[8px] px-2 py-2 text-[13px] text-stone-700"
                            >
                                <RefreshCw :size="15" :stroke-width="1.75" />
                                Duomenų tvarkymas
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
                        class="inline-flex items-center rounded-[10px] px-3 py-2 text-[13px] font-medium text-stone-700 transition-colors hover:bg-stone-100"
                    >
                        Prisijungti
                    </Link>
                </div>
            </div>
        </div>
    </header>
</template>
