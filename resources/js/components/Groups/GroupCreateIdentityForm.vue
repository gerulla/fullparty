<script setup lang="ts">
import { computed, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { FormError, FormErrorEvent } from '@nuxt/ui'
import type { GroupCreateIdentity } from '@/Types/Groups'
import { groupDescriptionMaxLength, groupNameMaxLength, groupSlugMaxLength, groupSlugValidationError, normalizeGroupSlug } from '@/utils/groupIdentity'
import { sanitizeMultilineText, sanitizeMultilineTextForInput, sanitizeSingleLineText, sanitizeSingleLineTextForInput } from '@/utils/textInputSanitizer'

const props = withDefaults(defineProps<{ modelValue: GroupCreateIdentity; errors?: Record<string, string> }>(), { errors: () => ({}) })
const emit = defineEmits<{
    'update:modelValue': [identity: GroupCreateIdentity]
    continue: [identity: GroupCreateIdentity]
}>()
const { t } = useI18n()
const dashboardPath = computed(() => route('groups.dashboard', {
    group: props.modelValue.slug || t('groups.index.create_modal.slug_fallback'),
}, false))
const inputUi = {
    base: 'rounded-lg bg-elevation-1 shadow-md ring-neutral-300 placeholder:text-muted dark:bg-elevation-4 dark:shadow-none dark:ring-elevation-6 aria-invalid:ring-error dark:aria-invalid:ring-error',
}

function updateField(field: keyof GroupCreateIdentity, value: string | number | undefined) {
    const text = String(value ?? '')
    const cleanValue = field === 'slug' ? normalizeGroupSlug(text)
        : field === 'name' ? sanitizeSingleLineTextForInput(text)
        : sanitizeMultilineTextForInput(text)
    emit('update:modelValue', { ...props.modelValue, [field]: cleanValue })
}

function validate(state: GroupCreateIdentity): FormError[] {
    const errors: FormError[] = []
    if (!sanitizeSingleLineText(state.name)) {
        errors.push({ name: 'name', message: t('groups.index.create_modal.validation.required', { field: t('general.name') }) })
    } else if (state.name.length > groupNameMaxLength) {
        errors.push({ name: 'name', message: t('groups.index.create_modal.validation.max_characters', { field: t('general.name'), max: groupNameMaxLength }) })
    }
    const slugError = groupSlugValidationError(state.slug)
    if (slugError) {
        errors.push({ name: 'slug', message: t(`groups.index.create_modal.validation.${slugError}`, { field: t('groups.index.create_modal.fields.slug.label') }) })
    }
    if (state.description.length > groupDescriptionMaxLength) {
        errors.push({ name: 'description', message: t('groups.index.create_modal.validation.max_characters', { field: t('general.description'), max: groupDescriptionMaxLength }) })
    }
    return errors
}

function submit() {
    const identity = {
        name: sanitizeSingleLineText(props.modelValue.name),
        slug: normalizeGroupSlug(props.modelValue.slug),
        description: sanitizeMultilineText(props.modelValue.description),
    }
    emit('update:modelValue', identity)
    emit('continue', identity)
}

async function focusFirstError(event: FormErrorEvent) {
    const id = event.errors[0]?.id
    await nextTick()
    if (id) document.getElementById(id)?.focus()
}
</script>

<template>
    <UForm :state="modelValue" :validate="validate" :loading-auto="false"
        class="mx-auto w-full max-w-2xl space-y-6 pt-4 sm:pt-6" @submit="submit" @error="focusFirstError">
        <UFormField name="name" :label="t('general.name')" :error="errors.name" required>
            <UInput :model-value="modelValue.name" :maxlength="groupNameMaxLength" class="w-full" size="xl"
                :placeholder="t('groups.index.create_modal.fields.name.placeholder')"
                :ui="inputUi" autocomplete="off"
                @update:model-value="updateField('name', $event)" />
        </UFormField>
        <div class="grid gap-6 sm:grid-cols-5 sm:items-start">
            <div class="min-w-0 space-y-2 sm:col-span-3">
                <p class="text-sm font-medium text-default">{{ t('groups.index.create_flow.identity.group_link') }}</p>
                <p class="break-all py-2 font-mono text-sm font-medium text-toned">{{ dashboardPath }}</p>
                <p class="text-xs text-muted">{{ t('groups.index.create_modal.slug_warning') }}</p>
            </div>
            <UFormField name="slug" :label="t('groups.index.create_modal.fields.slug.label')"
                :error="errors.slug"
                :help="t('groups.index.create_modal.fields.slug.help')" required class="min-w-0 sm:col-span-2">
                <UInput :model-value="modelValue.slug" :maxlength="groupSlugMaxLength" class="w-full" size="xl"
                    :placeholder="t('groups.index.create_modal.fields.slug.placeholder')"
                    :ui="inputUi" autocapitalize="off" autocomplete="off" :spellcheck="false"
                    @update:model-value="updateField('slug', $event)" />
            </UFormField>
        </div>
        <UFormField name="description" :label="t('general.description')"
            :error="errors.description"
            :hint="t('groups.index.create_flow.identity.optional')">
            <UTextarea :model-value="modelValue.description" :rows="7" :maxlength="groupDescriptionMaxLength"
                class="w-full" size="lg" :placeholder="t('groups.index.create_modal.fields.description.placeholder')"
                :ui="{ base: `${inputUi.base} resize-y` }" @update:model-value="updateField('description', $event)" />
            <template #help>
                <span class="block text-right text-xs text-muted">{{ modelValue.description.length }} / {{ groupDescriptionMaxLength }}</span>
            </template>
        </UFormField>
        <div class="flex justify-center">
            <UButton type="submit" color="brand" trailing-icon="i-lucide-arrow-right" size="lg" class="w-full justify-center rounded-lg sm:w-auto sm:min-w-40">
                {{ t('general.continue') }}
            </UButton>
        </div>
    </UForm>
</template>
