<script setup lang="ts">
import { Check, ChevronsUpDown, Search } from 'lucide-vue-next';
import {
    ComboboxAnchor,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxItemIndicator,
    ComboboxPortal,
    ComboboxRoot,
    ComboboxTrigger,
    ComboboxViewport,
} from 'reka-ui';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

export type ComboboxOption = {
    value: number | string;
    label: string;
};

const props = withDefaults(
    defineProps<{
        modelValue: number | string | null;
        options: ComboboxOption[];
        placeholder?: string;
        searchPlaceholder?: string;
        emptyText?: string;
        disabled?: boolean;
    }>(),
    {
        placeholder: 'Pasirinkti…',
        searchPlaceholder: 'Ieškoti…',
        emptyText: 'Nieko nerasta.',
        disabled: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: number | string | null];
}>();

const selectedLabel = computed(
    () =>
        props.options.find((option) => option.value === props.modelValue)
            ?.label ?? null,
);

function onUpdate(value: unknown) {
    emit(
        'update:modelValue',
        value === undefined || value === ''
            ? null
            : (value as number | string),
    );
}
</script>

<template>
    <ComboboxRoot
        :model-value="props.modelValue ?? undefined"
        :disabled="props.disabled"
        @update:model-value="onUpdate"
    >
        <ComboboxAnchor as-child>
            <ComboboxTrigger
                :class="
                    cn(
                        'border-input data-[placeholder]:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 flex h-9 w-full items-center justify-between gap-2 rounded-md border bg-transparent px-3 py-2 text-sm whitespace-nowrap shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50',
                    )
                "
            >
                <span
                    :class="
                        selectedLabel ? 'text-foreground' : 'text-muted-foreground'
                    "
                    class="line-clamp-1 text-left"
                >
                    {{ selectedLabel ?? props.placeholder }}
                </span>
                <ChevronsUpDown class="size-4 shrink-0 opacity-50" />
            </ComboboxTrigger>
        </ComboboxAnchor>

        <ComboboxPortal>
            <ComboboxContent
                position="popper"
                :side-offset="4"
                :class="
                    cn(
                        'bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 relative z-50 max-h-[320px] w-[var(--reka-combobox-trigger-width)] min-w-[8rem] overflow-hidden rounded-md border shadow-md',
                    )
                "
            >
                <div class="flex items-center border-b px-3">
                    <Search class="mr-2 size-4 shrink-0 opacity-50" />
                    <ComboboxInput
                        :placeholder="props.searchPlaceholder"
                        class="placeholder:text-muted-foreground flex h-9 w-full bg-transparent py-2 text-sm outline-none"
                    />
                </div>
                <ComboboxViewport class="max-h-[260px] overflow-y-auto p-1">
                    <ComboboxEmpty
                        class="text-muted-foreground py-6 text-center text-sm"
                    >
                        {{ props.emptyText }}
                    </ComboboxEmpty>
                    <ComboboxItem
                        v-for="option in props.options"
                        :key="option.value"
                        :value="option.value"
                        :text-value="option.label"
                        :class="
                            cn(
                                'data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground relative flex w-full cursor-pointer items-center rounded-sm py-1.5 pr-2 pl-8 text-sm outline-none select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
                            )
                        "
                    >
                        <span
                            class="absolute left-2 flex size-3.5 items-center justify-center"
                        >
                            <ComboboxItemIndicator>
                                <Check class="size-4" />
                            </ComboboxItemIndicator>
                        </span>
                        {{ option.label }}
                    </ComboboxItem>
                </ComboboxViewport>
            </ComboboxContent>
        </ComboboxPortal>
    </ComboboxRoot>
</template>
