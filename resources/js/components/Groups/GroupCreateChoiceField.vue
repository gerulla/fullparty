<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { GroupCreateChoice } from '@/Types/Groups'

const props = withDefaults(defineProps<{
    modelValue: string[]
    options: GroupCreateChoice[]
    label: string
    required?: boolean
    help?: string
    multiple?: boolean
    limit?: number
}>(), { multiple: true })
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>()
const { t } = useI18n()

function select(value: string) {
    if (!props.multiple) {
        emit('update:modelValue', [value])
    } else if (props.modelValue.includes(value)) {
        emit('update:modelValue', props.modelValue.filter(item => item !== value))
    } else if (props.limit === undefined || props.modelValue.length < props.limit) {
        emit('update:modelValue', [...props.modelValue, value])
    }
}
</script>

<template>
    <fieldset class="min-w-0 space-y-4">
        <legend class="mb-1 text-base font-semibold text-highlighted">
            {{ label }}<span v-if="required" class="ms-0.5 text-error" aria-hidden="true">*</span>
            <span v-if="required" class="sr-only"> ({{ t('general.required') }})</span>
        </legend>
        <p class="text-sm text-muted">{{ help || t(`groups.index.create_flow.choices.${multiple ? 'multiple' : 'single'}`) }}</p>
        <div class="flex flex-wrap gap-2.5 sm:gap-3">
            <UButton v-for="option in options" :key="option.value" type="button" size="lg"
                :color="modelValue.includes(option.value) ? 'brand' : 'neutral'"
                :variant="modelValue.includes(option.value) ? 'solid' : 'outline'"
                :aria-pressed="modelValue.includes(option.value)"
                :icon="modelValue.includes(option.value) ? 'i-lucide-check' : (multiple ? 'i-lucide-plus' : 'i-lucide-circle')"
                :disabled="!modelValue.includes(option.value) && limit !== undefined && modelValue.length >= limit"
                class="min-h-11 max-w-full rounded-full px-5 py-3 text-sm whitespace-normal text-left transition-colors"
                :class="modelValue.includes(option.value) ? 'shadow-sm' : 'bg-elevation-1 text-toned shadow-sm ring-neutral-300 hover:bg-elevation-2 dark:bg-elevation-3 dark:shadow-none dark:ring-elevation-6 dark:hover:bg-elevation-4'"
                @click="select(option.value)">
                <span class="break-words [overflow-wrap:anywhere]">
                    <span class="block">{{ option.label }}</span>
                    <span v-if="option.description" class="mt-0.5 block text-xs font-normal opacity-80">{{ option.description }}</span>
                </span>
            </UButton>
        </div>
    </fieldset>
</template>
