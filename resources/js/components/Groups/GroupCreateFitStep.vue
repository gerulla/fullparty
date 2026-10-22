<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import GroupCreateChoiceField from './GroupCreateChoiceField.vue'
import { uiLocales } from '@/i18n/uiLocales'
import type { GroupCreateFit, GroupDiscoveryLookups } from '@/Types/Groups'

const props = defineProps<{ modelValue: GroupCreateFit; lookups: GroupDiscoveryLookups }>()
const emit = defineEmits<{ 'update:modelValue': [value: GroupCreateFit]; continue: [] }>()
const { t } = useI18n()
const experienceOptions = computed(() => (props.lookups.experience_expectations ?? []).map(value => ({
    value, label: t(`groups.index.create_modal.fields.experience_expectation.options.${value}`),
})))
const voiceOptions = computed(() => (props.lookups.voice_expectations ?? []).map(value => ({
    value, label: t(`groups.common.voice_expectations.${value}`),
})))
const languageOptions = computed(() => (props.lookups.preferred_languages ?? []).map(value => ({
    value, label: uiLocales[value as keyof typeof uiLocales]?.name ?? value.toUpperCase(),
})))
const canContinue = computed(() => experienceOptions.value.some(option => option.value === props.modelValue.experience_expectation)
    && voiceOptions.value.some(option => option.value === props.modelValue.voice_expectation)
    && props.modelValue.preferred_languages.length > 0
    && props.modelValue.preferred_languages.every(value => languageOptions.value.some(option => option.value === value)))

function update<K extends keyof GroupCreateFit>(field: K, value: GroupCreateFit[K]) {
    emit('update:modelValue', { ...props.modelValue, [field]: value })
}
</script>

<template>
    <div class="mx-auto w-full max-w-2xl space-y-9 pt-4 sm:pt-6">
        <GroupCreateChoiceField required :model-value="[modelValue.experience_expectation]" :options="experienceOptions" :multiple="false"
            :label="t('groups.index.create_modal.fields.experience_expectation.label')"
            @update:model-value="update('experience_expectation', $event[0])" />
        <GroupCreateChoiceField required :model-value="[modelValue.voice_expectation]" :options="voiceOptions" :multiple="false"
            :label="t('groups.index.create_modal.fields.voice_expectation.label')"
            @update:model-value="update('voice_expectation', $event[0])" />
        <GroupCreateChoiceField required :model-value="modelValue.preferred_languages" :options="languageOptions"
            :label="t('groups.index.create_modal.fields.preferred_languages.label')"
            @update:model-value="update('preferred_languages', $event)" />
        <div class="flex justify-center">
            <UButton color="brand" size="lg" trailing-icon="i-lucide-arrow-right" :disabled="!canContinue"
                class="w-full justify-center rounded-lg sm:w-auto sm:min-w-40" @click="canContinue && emit('continue')">
                {{ t('general.continue') }}
            </UButton>
        </div>
    </div>
</template>
