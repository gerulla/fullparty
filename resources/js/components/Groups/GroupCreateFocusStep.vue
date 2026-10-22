<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import GroupCreateChoiceField from './GroupCreateChoiceField.vue'
import type { GroupCreateFocus, GroupDiscoveryLookups } from '@/Types/Groups'
import { sanitizeSingleLineText } from '@/utils/textInputSanitizer'
import { groupExtraTagKey } from '@/utils/groupExtraTags'

const props = defineProps<{ modelValue: GroupCreateFocus; lookups: GroupDiscoveryLookups; popularTags: string[]; reservedTagKeys: string[] }>()
const emit = defineEmits<{ 'update:modelValue': [value: GroupCreateFocus]; continue: [] }>()
const { t } = useI18n()
const tagInput = ref('')
const tagError = ref('')
const maxTags = computed(() => props.lookups.max_tags ?? 12)
const focusOptions = computed(() => (props.lookups.primary_focuses ?? []).map(value => ({
    value, label: t(`groups.index.create_modal.fields.primary_focuses.options.${value}`),
})))
const reservedKeys = computed(() => new Set(props.reservedTagKeys))
const isReserved = (tag: string) => reservedKeys.value.has(groupExtraTagKey(tag))
const suggestedTags = computed(() => props.popularTags.filter(tag => !isReserved(tag)).slice(0, 8))
const hasReservedTags = computed(() => props.modelValue.tags.some(isReserved))
const tagOptions = computed(() => [...new Set([...suggestedTags.value, ...props.modelValue.tags])].map(value => ({ value, label: value })))
const canContinue = computed(() => props.modelValue.primary_focuses.length > 0
    && props.modelValue.primary_focuses.every(value => focusOptions.value.some(option => option.value === value))
    && props.modelValue.tags.length <= maxTags.value
    && !hasReservedTags.value)

function update<K extends keyof GroupCreateFocus>(field: K, value: GroupCreateFocus[K]) {
    tagError.value = ''
    emit('update:modelValue', { ...props.modelValue, [field]: value })
}

function addTag(): boolean {
    const enteredTag = sanitizeSingleLineText(tagInput.value)
    tagError.value = ''
    if (!enteredTag) {
        tagInput.value = ''
        return true
    }
    if (isReserved(enteredTag)) {
        tagError.value = t('groups.common.validation.tag_covered_by_field')
        return false
    }
    if ([...enteredTag].length > 50) {
        tagError.value = t('groups.index.create_modal.validation.tag_too_long', { max: 50 })
        return false
    }
    const key = enteredTag.toLowerCase()
    if (props.modelValue.tags.some(tag => tag.toLowerCase() === key)) {
        tagInput.value = ''
        return true
    }
    if (props.modelValue.tags.length >= maxTags.value) {
        tagError.value = t('groups.index.create_modal.validation.max_tags', { max: maxTags.value })
        return false
    }
    const tag = suggestedTags.value.find(tag => tag.toLowerCase() === key) ?? enteredTag
    update('tags', [...props.modelValue.tags, tag])
    tagInput.value = ''
    return true
}

function continueStep() {
    if (canContinue.value && addTag()) emit('continue')
}
</script>

<template>
    <div class="mx-auto w-full max-w-2xl space-y-9 pt-4 sm:pt-6">
        <GroupCreateChoiceField required :model-value="modelValue.primary_focuses" :options="focusOptions"
            :label="t('groups.index.create_modal.fields.primary_focuses.label')"
            @update:model-value="update('primary_focuses', $event)" />
        <div class="space-y-5">
            <GroupCreateChoiceField :model-value="modelValue.tags" :options="tagOptions" :limit="maxTags"
                :label="t('groups.index.create_modal.fields.tags.label')"
                :help="t('groups.index.create_flow.focus.tags_help', { max: maxTags })"
                @update:model-value="update('tags', $event)" />
            <UFormField name="custom_tag" :label="t('groups.index.create_flow.focus.other_tag')"
                :error="tagError || (hasReservedTags ? t('groups.common.validation.remove_covered_tags') : undefined)"
                :hint="t('groups.index.create_flow.focus.tag_count', { count: modelValue.tags.length, max: maxTags })">
                <div class="flex items-start gap-3">
                    <UInput v-model="tagInput" size="lg" class="min-w-0 flex-1" :maxlength="100"
                        :placeholder="t('groups.index.create_flow.focus.tag_placeholder')"
                        :ui="{ base: 'rounded-lg bg-elevation-1 shadow-sm ring-neutral-300 dark:bg-elevation-4 dark:shadow-none dark:ring-elevation-6' }"
                        @update:model-value="tagError = ''" @keydown.enter.prevent="addTag" />
                    <UButton type="button" color="brand" variant="soft" size="lg" icon="i-lucide-plus" class="shrink-0 rounded-lg"
                        :disabled="!tagInput.trim()" @click="addTag">
                        {{ t('general.add') }}
                    </UButton>
                </div>
            </UFormField>
        </div>
        <div class="flex justify-center">
            <UButton color="brand" size="lg" trailing-icon="i-lucide-arrow-right" :disabled="!canContinue"
                class="w-full justify-center rounded-lg sm:w-auto sm:min-w-40" @click="continueStep">
                {{ t('general.continue') }}
            </UButton>
        </div>
    </div>
</template>
