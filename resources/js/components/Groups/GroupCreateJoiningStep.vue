<script setup lang="ts">
import { computed, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import type { GroupCreateJoining } from '@/Types/Groups'

const props = defineProps<{ modelValue: GroupCreateJoining; groupName: string; disabled?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: GroupCreateJoining]; submit: [] }>()
const { t } = useI18n()
const visibilityLabelId = useId()
const visibilityHelpId = useId()
const choices = [
    { value: 'invite_only', icon: 'i-lucide-key-round' },
    { value: 'open', icon: 'i-lucide-door-open' },
] as const
const summary = computed(() => t(
    `groups.index.create_flow.joining.summary.${props.modelValue.join_mode}_${props.modelValue.is_visible ? 'visible' : 'hidden'}`,
    { name: props.groupName.trim() || t('groups.index.create_modal.visibility_summary.default_name') },
))
const canContinue = computed(() => !props.disabled && choices.some(choice => choice.value === props.modelValue.join_mode))

function update(values: Partial<GroupCreateJoining>) {
    emit('update:modelValue', { ...props.modelValue, ...values })
}
</script>

<template>
    <div class="mx-auto w-full max-w-3xl space-y-8 pt-4 sm:pt-6">
        <div class="grid gap-4 md:grid-cols-2" role="group" :aria-label="t('groups.index.create_flow.joining.join_label')">
            <UCard v-for="choice in choices" :key="choice.value" as="button" type="button"
                :aria-label="t(`groups.index.create_flow.joining.choices.${choice.value}.title`)"
                :aria-pressed="modelValue.join_mode === choice.value"
                class="w-full min-w-0 cursor-pointer rounded-xl border-2 bg-elevation-1 text-left shadow-sm ring-0 transition-colors focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand motion-reduce:transition-none dark:bg-elevation-3 dark:shadow-none"
                :class="modelValue.join_mode === choice.value
                    ? 'border-brand-600 dark:border-brand-400'
                    : 'border-elevation-3 hover:border-brand-400 dark:border-elevation-5 dark:hover:border-brand-500'"
                :ui="{ body: 'flex h-full flex-col gap-4 p-5 sm:p-6' }"
                @click="update({ join_mode: choice.value })">
                <div class="flex items-center gap-3">
                    <UIcon :name="choice.icon" class="size-6 shrink-0 text-muted" aria-hidden="true" />
                    <h2 class="flex-1 text-lg font-semibold text-highlighted">
                        {{ t(`groups.index.create_flow.joining.choices.${choice.value}.title`) }}
                    </h2>
                    <UIcon :name="modelValue.join_mode === choice.value ? 'i-lucide-circle-check' : 'i-lucide-circle'"
                        class="size-6 shrink-0" aria-hidden="true"
                        :class="modelValue.join_mode === choice.value ? 'text-brand-700 dark:text-brand-300' : 'text-dimmed'" />
                </div>
                <p class="text-sm leading-relaxed text-muted">{{ t(`groups.index.create_flow.joining.choices.${choice.value}.description`) }}</p>
                <p class="mt-auto text-sm leading-relaxed text-toned">{{ t(`groups.index.create_flow.joining.choices.${choice.value}.reason`) }}</p>
            </UCard>
        </div>

        <div class="flex items-center justify-between gap-5 px-1">
            <div class="min-w-0 space-y-1">
                <p :id="visibilityLabelId" class="font-semibold text-highlighted">{{ t('groups.index.create_flow.joining.discovery_label') }}</p>
                <p :id="visibilityHelpId" class="text-sm text-muted">{{ t('groups.index.create_flow.joining.discovery_help') }}</p>
            </div>
            <USwitch :model-value="modelValue.is_visible" color="brand" size="lg"
                :aria-labelledby="visibilityLabelId" :aria-describedby="visibilityHelpId" class="shrink-0"
                :ui="{ base: 'rounded-full', thumb: 'rounded-full' }"
                @update:model-value="update({ is_visible: $event })" />
        </div>

        <UCard class="rounded-xl bg-elevation-1 shadow-sm ring-elevation-3 dark:bg-elevation-3 dark:shadow-none dark:ring-0"
            :ui="{ body: 'space-y-3 p-5 sm:p-6' }" aria-live="polite" aria-atomic="true">
            <h2 class="text-base font-semibold text-highlighted">{{ t('groups.index.create_flow.joining.summary_title') }}</h2>
            <p class="text-sm leading-relaxed text-toned">{{ summary }}</p>
            <p class="text-sm leading-relaxed text-muted">{{ t('groups.index.create_flow.joining.run_access') }}</p>
        </UCard>

        <div class="flex justify-center">
            <UButton color="brand" size="lg" trailing-icon="i-lucide-arrow-right" :disabled="!canContinue"
                class="w-full justify-center rounded-lg sm:w-auto sm:min-w-40" @click="canContinue && emit('submit')">
                {{ t('groups.index.create_modal.title') }}
            </UButton>
        </div>
    </div>
</template>
