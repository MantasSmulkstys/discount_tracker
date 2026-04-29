<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { redirect as googleRedirect } from '@/actions/App/Http/Controllers/Auth/GoogleAuthenticatedSessionController';
import GoogleMark from '@/components/GoogleMark.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Prisijungti prie paskyros',
        description:
            'Naudokite el. paštą arba Google, kad sugrįžtumėte prie išsaugotų prekių ir valdymo srities.',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
    canUseGoogle: boolean;
    oauthError?: string;
}>();
</script>

<template>
    <Head title="Prisijungti" />

    <div
        v-if="status"
        class="rounded-[12px] border border-emerald-200 bg-emerald-50 px-4 py-3 text-[13px] font-medium text-emerald-800"
    >
        {{ status }}
    </div>

    <div
        v-if="oauthError"
        class="rounded-[12px] border border-red-200 bg-red-50 px-4 py-3 text-[13px] font-medium text-red-700"
    >
        {{ oauthError }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-5">
            <Button
                v-if="canUseGoogle"
                as="a"
                :href="googleRedirect.url()"
                variant="outline"
                class="h-11 w-full justify-center rounded-[10px] border-kainu-border bg-white text-[14px] font-semibold text-stone-900 hover:bg-stone-50 hover:text-stone-900"
                :tabindex="1"
            >
                <GoogleMark className="size-4" />
                Prisijungti su Google
            </Button>

            <div
                v-if="canUseGoogle"
                class="flex items-center gap-3 text-[11px] font-semibold tracking-[0.18em] text-stone-400 uppercase"
            >
                <span class="h-px flex-1 bg-[#e7e5e0]" />
                <span>Arba su el. paštu</span>
                <span class="h-px flex-1 bg-[#e7e5e0]" />
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
                    name="email"
                    required
                    autofocus
                    :tabindex="2"
                    autocomplete="email"
                    placeholder="vardas@pavyzdys.lt"
                    class="h-11 rounded-[10px] border-kainu-border bg-white text-stone-900 placeholder:text-stone-400 focus:border-[#15803d] focus:ring-2 focus:ring-[#15803d]/15"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label
                        for="password"
                        class="text-[13px] font-semibold text-stone-800"
                    >
                        Slaptažodis
                    </Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request().url"
                        class="text-[12px] font-medium text-[#15803d] hover:underline"
                        :tabindex="4"
                    >
                        Pamiršote slaptažodį?
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="3"
                    autocomplete="current-password"
                    placeholder="Slaptažodis"
                    class="h-11 rounded-[10px] border-kainu-border bg-white text-stone-900 placeholder:text-stone-400 focus:border-[#15803d] focus:ring-2 focus:ring-[#15803d]/15"
                />
                <InputError :message="errors.password" />
            </div>

            <label
                for="remember"
                class="flex cursor-pointer items-center gap-3 rounded-[10px] border border-kainu-border bg-white px-3 py-2 text-[13px] font-medium text-stone-700"
            >
                <Checkbox id="remember" name="remember" :tabindex="5" />
                <span>Prisiminti mane šiame įrenginyje</span>
            </label>

            <Button
                type="submit"
                class="mt-1 h-11 w-full rounded-[10px] bg-[#15803d] text-[14px] font-semibold text-white hover:bg-[#166534]"
                :tabindex="6"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Prisijungti
            </Button>
        </div>

        <div v-if="canRegister" class="text-center text-[13px] text-stone-600">
            Dar neturite paskyros?
            <TextLink
                :href="register().url"
                class="font-semibold text-[#15803d] hover:underline"
                :tabindex="7"
            >
                Registruokitės
            </TextLink>
        </div>
    </Form>
</template>
