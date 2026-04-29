<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Pamiršote slaptažodį',
        description:
            'Įveskite paskyros el. paštą ir atsiųsime nuorodą, pagal kurią galėsite nustatyti naują slaptažodį.',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Slaptažodžio atkūrimas" />

    <div
        v-if="status"
        class="rounded-[12px] border border-emerald-200 bg-emerald-50 px-4 py-3 text-[13px] font-medium text-emerald-800"
    >
        {{ status }}
    </div>

    <div class="space-y-6">
        <Form
            v-bind="email.form()"
            v-slot="{ errors, processing }"
            class="space-y-5"
        >
            <div class="grid gap-2">
                <Label
                    for="email"
                    class="text-[13px] font-semibold text-stone-800"
                >
                    El. pašto adresas
                </Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    autofocus
                    required
                    placeholder="vardas@pavyzdys.lt"
                    class="h-11 rounded-[10px] border-kainu-border bg-white text-stone-900 placeholder:text-stone-400 focus:border-[#15803d] focus:ring-2 focus:ring-[#15803d]/15"
                />
                <InputError :message="errors.email" />
            </div>

            <Button
                class="h-11 w-full rounded-[10px] bg-[#15803d] text-[14px] font-semibold text-white hover:bg-[#166534]"
                :disabled="processing"
                data-test="email-password-reset-link-button"
            >
                <Spinner v-if="processing" />
                Siųsti atkūrimo nuorodą
            </Button>
        </Form>

        <div class="text-center text-[13px] text-stone-600">
            Prisiminiau slaptažodį.
            <TextLink
                :href="login().url"
                class="font-semibold text-[#15803d] hover:underline"
            >
                Grįžti į prisijungimą
            </TextLink>
        </div>
    </div>
</template>
