<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{ disabled?: boolean }>()
const name = defineModel<string>('name', { required: true })
const description = defineModel<string>('description', { required: true })
const active = defineModel<boolean>('active', { required: true })
const isDefault = defineModel<boolean>('default', { required: true })
const { t } = useI18n()
const k = 'groups.index.content.forked_tower_blood.compositions.'
</script>

<template>
    <fieldset :disabled="disabled" class="min-w-0 space-y-5">
        <UFormField :label="t(k + 'editor.fields.name')" required>
            <UInput v-model="name" class="w-full min-w-0" :placeholder="t(k + 'editor.fields.name_placeholder')" :ui="{ base: 'rounded-md' }" />
        </UFormField>
        <UFormField :label="t(k + 'editor.fields.description')">
            <UTextarea v-model="description" :rows="5" class="w-full min-w-0" :placeholder="t(k + 'editor.fields.description_placeholder')" :ui="{ base: 'rounded-md' }" />
        </UFormField>
        <div class="space-y-4 rounded-lg bg-elevation-2 p-4 dark:bg-elevation-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium">{{ t(k + 'editor.fields.active') }}</p>
                    <p class="mt-1 text-xs text-muted">{{ t(k + (active ? 'active' : 'inactive')) }}</p>
                </div>
                <USwitch v-model="active" :disabled="disabled" :aria-label="t(k + 'editor.fields.active')" />
            </div>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium">{{ t(k + 'editor.fields.default') }}</p>
                    <p class="mt-1 text-xs text-muted">{{ t(k + 'editor.' + (isDefault ? 'default_enabled' : 'default_disabled')) }}</p>
                </div>
                <USwitch v-model="isDefault" :disabled="disabled" :aria-label="t(k + 'editor.fields.default')" />
            </div>
        </div>
    </fieldset>
</template>
