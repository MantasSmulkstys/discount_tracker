<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineOptions({
    layout: {
        title: 'Sukurkite paskyrą',
        description:
            'Su paskyra išsaugosite mėgstamas prekes, sektumėte kainas ir gautumėte pranešimus apie nuolaidas.',
    },
});

defineProps<{
    oauthError?: string;
}>();
</script>

<template>
    <Head title="Registracija" />

    <div
        v-if="oauthError"
        class="rounded-[12px] border border-red-200 bg-red-50 px-4 py-3 text-[13px] font-medium text-red-700"
    >
        {{ oauthError }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-5">
            <div class="grid gap-2">
                <Label
                    for="name"
                    class="text-[13px] font-semibold text-stone-800"
                >
                    Vardas
                </Label>
                <Input
                    id="name"
                    type="text"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="name"
                    name="name"
                    placeholder="Vardas Pavardė"
                    class="h-11 rounded-[10px] border-kainu-border bg-white text-stone-900 placeholder:text-stone-400 focus:border-[#15803d] focus:ring-2 focus:ring-[#15803d]/15"
                />
                <InputError :message="errors.name" />
            </div>

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
                    required
                    :tabindex="2"
                    autocomplete="email"
                    name="email"
                    placeholder="vardas@pavyzdys.lt"
                    class="h-11 rounded-[10px] border-kainu-border bg-white text-stone-900 placeholder:text-stone-400 focus:border-[#15803d] focus:ring-2 focus:ring-[#15803d]/15"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label
                    for="password"
                    class="text-[13px] font-semibold text-stone-800"
                >
                    Slaptažodis
                </Label>
                <PasswordInput
                    id="password"
                    required
                    :tabindex="3"
                    autocomplete="new-password"
                    name="password"
                    placeholder="Slaptažodis"
                    class="h-11 rounded-[10px] border-kainu-border bg-white text-stone-900 placeholder:text-stone-400 focus:border-[#15803d] focus:ring-2 focus:ring-[#15803d]/15"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label
                    for="password_confirmation"
                    class="text-[13px] font-semibold text-stone-800"
                >
                    Pakartokite slaptažodį
                </Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    name="password_confirmation"
                    placeholder="Pakartokite slaptažodį"
                    class="h-11 rounded-[10px] border-kainu-border bg-white text-stone-900 placeholder:text-stone-400 focus:border-[#15803d] focus:ring-2 focus:ring-[#15803d]/15"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-1 h-11 w-full rounded-[10px] bg-[#15803d] text-[14px] font-semibold text-white hover:bg-[#166534]"
                tabindex="5"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                Sukurti paskyrą
            </Button>
        </div>

        <p class="text-center text-[12px] leading-5 text-stone-500">
            Spausdami „Sukurti paskyrą“ patvirtinate, kad sutinkate gauti
            pranešimus apie jus dominančias nuolaidas.
        </p>

        <div class="text-center text-[13px] text-stone-600">
            Jau turite paskyrą?
            <TextLink
                :href="login().url"
                class="font-semibold text-[#15803d] hover:underline"
                :tabindex="6"
            >
                Prisijunkite
            </TextLink>
        </div>
    </Form>
</template>
