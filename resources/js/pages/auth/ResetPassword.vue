<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Nustatyti naują slaptažodį',
        description:
            'Pasirinkite naują stiprų slaptažodį, kurį matysite tik jūs.',
    },
});

const props = defineProps<{
    token: string;
    email: string;
}>();

const inputEmail = ref(props.email);
</script>

<template>
    <Head title="Slaptažodžio nustatymas" />

    <Form
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token, email })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-6">
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
                    v-model="inputEmail"
                    class="h-11 rounded-[10px] border-kainu-border bg-stone-100 text-stone-600"
                    readonly
                />
                <InputError :message="errors.email" class="mt-1" />
            </div>

            <div class="grid gap-2">
                <Label
                    for="password"
                    class="text-[13px] font-semibold text-stone-800"
                >
                    Naujas slaptažodis
                </Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    class="h-11 rounded-[10px] border-kainu-border bg-white text-stone-900 placeholder:text-stone-400 focus:border-[#15803d] focus:ring-2 focus:ring-[#15803d]/15"
                    autofocus
                    placeholder="Įveskite naują slaptažodį"
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
                    name="password_confirmation"
                    autocomplete="new-password"
                    class="h-11 rounded-[10px] border-kainu-border bg-white text-stone-900 placeholder:text-stone-400 focus:border-[#15803d] focus:ring-2 focus:ring-[#15803d]/15"
                    placeholder="Pakartokite naują slaptažodį"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-1 h-11 w-full rounded-[10px] bg-[#15803d] text-[14px] font-semibold text-white hover:bg-[#166534]"
                :disabled="processing"
                data-test="reset-password-button"
            >
                <Spinner v-if="processing" />
                Išsaugoti slaptažodį
            </Button>
        </div>
    </Form>
</template>
