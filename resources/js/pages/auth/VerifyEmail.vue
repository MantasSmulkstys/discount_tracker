<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { MailCheck } from 'lucide-vue-next';
import { computed } from 'vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'Patvirtinkite el. paštą',
        description:
            'Patvirtinkite paskyrą paspausdami nuorodą, kurią išsiuntėme į jūsų el. pašto dėžutę.',
    },
});

defineProps<{
    status?: string;
}>();

const page = usePage<{ auth?: { user?: { email?: string } | null } }>();
const email = computed(() => page.props.auth?.user?.email ?? '');
</script>

<template>
    <Head title="Patvirtinti el. paštą" />

    <div class="space-y-6">
        <div
            class="flex items-start gap-3 rounded-[12px] border border-kainu-border bg-kainu-surface p-4 text-[13px] text-stone-700"
        >
            <MailCheck
                :size="20"
                :stroke-width="1.75"
                class="mt-0.5 shrink-0 text-[#15803d]"
            />
            <div class="space-y-1">
                <p>
                    Patvirtinimo nuoroda išsiųsta į
                    <span class="font-semibold text-stone-900">
                        {{ email || 'jūsų paskyros el. paštą' }} </span
                    >.
                </p>
                <p class="text-stone-500">
                    Jei laiško negavote per kelias minutes, patikrinkite
                    šiukšlių aplanką arba užsakykite naują laišką žemiau.
                </p>
            </div>
        </div>

        <div
            v-if="status === 'verification-link-sent'"
            class="rounded-[12px] border border-emerald-200 bg-emerald-50 px-4 py-3 text-center text-[13px] font-medium text-emerald-800"
        >
            Naujas patvirtinimo laiškas jau pakeliui į jūsų pašto dėžutę.
        </div>

        <Form v-bind="send.form()" class="space-y-4" v-slot="{ processing }">
            <Button
                type="submit"
                :disabled="processing"
                class="h-11 w-full rounded-[10px] bg-[#15803d] text-[14px] font-semibold text-white hover:bg-[#166534]"
            >
                <Spinner v-if="processing" />
                Siųsti patvirtinimo laišką dar kartą
            </Button>

            <TextLink
                :href="logout()"
                as="button"
                class="mx-auto block text-center text-[13px] font-medium text-stone-500 hover:text-stone-700"
            >
                Atsijungti
            </TextLink>
        </Form>
    </div>
</template>
