<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';

defineOptions({
    layout: {
        title: 'Patvirtinkite slaptažodį',
        description:
            'Tai saugi zona. Prieš tęsdami, patvirtinkite savo dabartinį slaptažodį.',
    },
});
</script>

<template>
    <Head title="Patvirtinti slaptažodį" />

    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
    >
        <div class="space-y-6">
            <div class="grid gap-2">
                <Label
                    htmlFor="password"
                    class="text-[13px] font-semibold text-stone-800"
                >
                    Slaptažodis
                </Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="h-11 rounded-[10px] border-kainu-border bg-white text-stone-900 placeholder:text-stone-400 focus:border-[#15803d] focus:ring-2 focus:ring-[#15803d]/15"
                    required
                    autocomplete="current-password"
                    autofocus
                    placeholder="Jūsų slaptažodis"
                />

                <InputError :message="errors.password" />
            </div>

            <Button
                type="submit"
                class="h-11 w-full rounded-[10px] bg-[#15803d] text-[14px] font-semibold text-white hover:bg-[#166534]"
                :disabled="processing"
                data-test="confirm-password-button"
            >
                <Spinner v-if="processing" />
                Patvirtinti
            </Button>
        </div>
    </Form>
</template>
