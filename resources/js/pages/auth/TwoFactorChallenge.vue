<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/two-factor/login';
import type { TwoFactorConfigContent } from '@/types';

const authConfigContent = computed<TwoFactorConfigContent>(() => {
    if (showRecoveryInput.value) {
        return {
            title: 'Atkūrimo kodas',
            description:
                'Įveskite vieną iš atsarginių atkūrimo kodų, kad patvirtintumėte prieigą prie savo paskyros.',
            buttonText: 'naudoti patvirtinimo kodą',
        };
    }

    return {
        title: 'Patvirtinkite prisijungimą',
        description:
            'Įveskite 6 ženklų kodą iš savo autentifikacijos programėlės.',
        buttonText: 'naudoti atkūrimo kodą',
    };
});

watchEffect(() => {
    setLayoutProps({
        title: authConfigContent.value.title,
        description: authConfigContent.value.description,
    });
});

const showRecoveryInput = ref<boolean>(false);

const toggleRecoveryMode = (clearErrors: () => void): void => {
    showRecoveryInput.value = !showRecoveryInput.value;
    clearErrors();
    code.value = '';
};

const code = ref<string>('');
</script>

<template>
    <Head title="Dviejų žingsnių patvirtinimas" />

    <div class="space-y-6">
        <template v-if="!showRecoveryInput">
            <Form
                v-bind="store.form()"
                class="space-y-5"
                reset-on-error
                @error="code = ''"
                #default="{ errors, processing, clearErrors }"
            >
                <input type="hidden" name="code" :value="code" />
                <div
                    class="flex flex-col items-center justify-center space-y-3 text-center"
                >
                    <div class="flex w-full items-center justify-center">
                        <InputOTP
                            id="otp"
                            v-model="code"
                            :maxlength="6"
                            :disabled="processing"
                            autofocus
                        >
                            <InputOTPGroup>
                                <InputOTPSlot
                                    v-for="index in 6"
                                    :key="index"
                                    :index="index - 1"
                                />
                            </InputOTPGroup>
                        </InputOTP>
                    </div>
                    <InputError :message="errors.code" />
                </div>
                <Button
                    type="submit"
                    class="h-11 w-full rounded-[10px] bg-[#15803d] text-[14px] font-semibold text-white hover:bg-[#166534]"
                    :disabled="processing"
                >
                    <Spinner v-if="processing" />
                    Tęsti
                </Button>
                <div class="text-center text-[13px] text-stone-600">
                    <span>arba galite </span>
                    <button
                        type="button"
                        class="font-semibold text-[#15803d] underline underline-offset-4 transition-colors hover:text-[#166534]"
                        @click="() => toggleRecoveryMode(clearErrors)"
                    >
                        {{ authConfigContent.buttonText }}
                    </button>
                </div>
            </Form>
        </template>

        <template v-else>
            <Form
                v-bind="store.form()"
                class="space-y-5"
                reset-on-error
                #default="{ errors, processing, clearErrors }"
            >
                <Input
                    name="recovery_code"
                    type="text"
                    placeholder="Įveskite atkūrimo kodą"
                    class="h-11 rounded-[10px] border-kainu-border bg-white text-stone-900 placeholder:text-stone-400 focus:border-[#15803d] focus:ring-2 focus:ring-[#15803d]/15"
                    :autofocus="showRecoveryInput"
                    required
                />
                <InputError :message="errors.recovery_code" />
                <Button
                    type="submit"
                    class="h-11 w-full rounded-[10px] bg-[#15803d] text-[14px] font-semibold text-white hover:bg-[#166534]"
                    :disabled="processing"
                >
                    <Spinner v-if="processing" />
                    Tęsti
                </Button>

                <div class="text-center text-[13px] text-stone-600">
                    <span>arba galite </span>
                    <button
                        type="button"
                        class="font-semibold text-[#15803d] underline underline-offset-4 transition-colors hover:text-[#166534]"
                        @click="() => toggleRecoveryMode(clearErrors)"
                    >
                        {{ authConfigContent.buttonText }}
                    </button>
                </div>
            </Form>
        </template>
    </div>
</template>
