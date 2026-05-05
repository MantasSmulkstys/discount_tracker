<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Bot,
    Loader2,
    Store as StoreIcon,
    TriangleAlert,
    Workflow,
} from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type StoreOption = {
    id: number;
    slug: string;
    name: string;
    logoPath: string | null;
    scrapeCron: string | null;
};

defineProps<{
    stores: StoreOption[];
}>();

type ConfirmAction =
    | { kind: 'normalize' }
    | { kind: 'pipeline' }
    | { kind: 'store'; slug: string; name: string };

const pendingAction = ref<ConfirmAction | null>(null);
const submitting = ref(false);

const ACTION_LABELS: Record<ConfirmAction['kind'], { title: string; description: string }> = {
    normalize: {
        title: 'Paleisti AI normalizaciją?',
        description:
            'Paleidžia AI normalizaciją visiems laukiantiems raw_offers įrašams. Naudoja OpenAI kreditus kiekvienam neapdorotam variantui — vykdykite tik kai yra naujų skenuotų produktų.',
    },
    pipeline: {
        title: 'Paleisti pilną procesą?',
        description:
            'Paleidžia visų aktyvių parduotuvių skenavimą paeiliui, tada AI normalizaciją. Trukmė — kelios minutės iki 30 min. Naudoja OpenAI kreditus naujiems variantams.',
    },
    store: {
        title: '',
        description: '',
    },
};

function describe(action: ConfirmAction): { title: string; description: string } {
    if (action.kind === 'store') {
        return {
            title: `Skenuoti ${action.name}?`,
            description:
                `Paleidžia "${action.name}" skenavimą ir AI normalizaciją. ` +
                'Naudoja OpenAI kreditus jei rasta naujų variantų.',
        };
    }
    return ACTION_LABELS[action.kind];
}

function ask(action: ConfirmAction) {
    pendingAction.value = action;
}

function cancel() {
    if (submitting.value) return;
    pendingAction.value = null;
}

function confirm() {
    const action = pendingAction.value;
    if (!action || submitting.value) return;

    submitting.value = true;

    const onFinish = () => {
        submitting.value = false;
        pendingAction.value = null;
    };

    if (action.kind === 'store') {
        router.post(
            '/admin/scrape/run',
            { store: action.slug },
            { preserveScroll: true, onFinish },
        );
    } else if (action.kind === 'normalize') {
        router.post('/admin/scrape/normalize', {}, { preserveScroll: true, onFinish });
    } else {
        router.post('/admin/scrape/all', {}, { preserveScroll: true, onFinish });
    }
}
</script>

<template>
    <Head title="Skenavimas" />

    <div class="p-4 md:p-6">
        <div class="mx-auto max-w-4xl space-y-8">
            <div>
                <h1 class="text-2xl font-semibold text-slate-950">Skenavimo valdiklis</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Rankinis paleidimas administratoriui. Cron grafikas veikia automatiškai
                    pagal kiekvienos parduotuvės nustatymus.
                </p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <Button
                    type="button"
                    variant="outline"
                    class="h-auto justify-start gap-3 rounded-2xl px-4 py-4"
                    @click="ask({ kind: 'normalize' })"
                >
                    <Bot :size="18" />
                    <div class="text-left">
                        <div class="text-sm font-semibold">Tik AI normalizacija</div>
                        <div class="text-xs text-slate-500">
                            Apdoroja laukiančius raw_offers.
                        </div>
                    </div>
                </Button>

                <Button
                    type="button"
                    variant="outline"
                    class="h-auto justify-start gap-3 rounded-2xl px-4 py-4"
                    @click="ask({ kind: 'pipeline' })"
                >
                    <Workflow :size="18" />
                    <div class="text-left">
                        <div class="text-sm font-semibold">Pilnas procesas</div>
                        <div class="text-xs text-slate-500">
                            Visos parduotuvės + normalizacija.
                        </div>
                    </div>
                </Button>
            </div>

            <section>
                <div class="mb-3 flex items-baseline justify-between">
                    <h2 class="text-base font-semibold text-slate-900">
                        Skenuoti vieną parduotuvę
                    </h2>
                    <span class="text-xs text-slate-500">
                        Skenavimas + AI normalizacija
                    </span>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <button
                        v-for="store in stores"
                        :key="store.id"
                        type="button"
                        class="group flex flex-col items-center gap-2 rounded-2xl border border-emerald-100 bg-white p-4 text-center transition hover:border-emerald-300 hover:bg-emerald-50/50 disabled:opacity-50"
                        :disabled="submitting"
                        @click="ask({ kind: 'store', slug: store.slug, name: store.name })"
                    >
                        <div
                            class="flex h-14 w-14 items-center justify-center overflow-hidden rounded-xl bg-stone-50 transition group-hover:bg-white"
                        >
                            <img
                                v-if="store.logoPath"
                                :src="store.logoPath"
                                :alt="store.name"
                                class="h-full w-full object-contain p-2"
                            />
                            <StoreIcon v-else :size="20" class="text-stone-400" />
                        </div>
                        <span class="text-sm font-medium text-slate-900">
                            {{ store.name }}
                        </span>
                        <span
                            v-if="store.scrapeCron"
                            class="text-[11px] tabular-nums text-slate-400"
                        >
                            {{ store.scrapeCron }}
                        </span>
                    </button>
                </div>
            </section>
        </div>
    </div>

    <Dialog
        :open="pendingAction !== null"
        @update:open="(open) => (open ? null : cancel())"
    >
        <DialogContent v-if="pendingAction">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <TriangleAlert :size="18" class="text-amber-500" />
                    {{ describe(pendingAction).title }}
                </DialogTitle>
            </DialogHeader>

            <p class="text-sm leading-relaxed text-slate-600">
                {{ describe(pendingAction).description }}
            </p>

            <DialogFooter>
                <Button
                    variant="outline"
                    type="button"
                    :disabled="submitting"
                    @click="cancel"
                >
                    Atšaukti
                </Button>
                <Button type="button" :disabled="submitting" @click="confirm">
                    <Loader2
                        v-if="submitting"
                        :size="14"
                        class="animate-spin"
                    />
                    Paleisti
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
