<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { WorkspaceDocument } from '@/Types/ResourceWorkspace'
import ResourceEmbedPreview from './ResourceEmbedPreview.vue'
import ResourceImagePicker from './ResourceImagePicker.vue'

const props = defineProps<{ document: WorkspaceDocument }>()
defineEmits<{ preview: [] }>()
const { t } = useI18n()
const l = (key: string) => t('groups.resources.workspace.' + key)
const fieldsOpen = ref(false)
const fieldEditor = ref<HTMLElement>()
async function addField() {
    props.document.embed.fields.push({ name: '', value: '', inline: false })
    fieldsOpen.value = true
    await nextTick()
    fieldEditor.value?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
}
</script>

<template>
    <div class="studio-discord-editor">
        <div class="studio-discord-heading"><h2>{{ l('discord_embed') }}</h2><USwitch v-model="document.embed.enabled" :aria-label="l('discord_embed')" size="sm" /></div>
        <template v-if="document.embed.enabled">
            <div class="studio-embed-form">
                <UFormField :label="l('command')" class="studio-form-row">
                    <UInput v-model="document.embed.command" :maxlength="64" size="sm" class="w-full" :ui="{ leading: 'h-full border-r border-default px-3 text-xs text-muted', base: 'ps-16 font-mono' }"><template #leading>/info</template></UInput>
                </UFormField>
                <UFormField :label="l('embed_title')" class="studio-form-row"><UInput v-model="document.embed.title" :maxlength="256" size="sm" class="w-full" /></UFormField>
                <UFormField :label="l('description')" class="studio-form-row"><UTextarea v-model="document.embed.description" :rows="3" :maxlength="4096" size="sm" class="w-full" /></UFormField>
                <UFormField :label="l('color')" class="studio-form-row"><div class="studio-color-field"><input v-model="document.embed.color" type="color" :aria-label="l('color')" /><UInput v-model="document.embed.color" :maxlength="7" variant="none" size="sm" class="min-w-0 flex-1" :ui="{ base: 'px-2 font-mono bg-transparent' }" /></div></UFormField>
                <UFormField :label="l('author')" class="studio-form-row"><UInput v-model="document.embed.author" icon="i-lucide-user-round" :maxlength="256" size="sm" class="w-full" /></UFormField>
                <ResourceImagePicker v-model="document.embed.thumbnail" :label="l('thumbnail')" compact />
                <ResourceImagePicker v-model="document.embed.image" :label="l('image')" compact />
                <div class="studio-add-field"><UButton icon="i-lucide-plus" color="neutral" variant="outline" size="sm" :label="l('add_field')" :disabled="document.embed.fields.length >= 25" @click="addField" /></div>
            </div>
            <section class="studio-embed-live-preview"><h3>{{ l('preview') }}</h3><ResourceEmbedPreview :document="document" @open="$emit('preview')" /></section>
            <UCollapsible class="studio-settings-section">
                <UButton icon="i-lucide-chevron-right" color="neutral" variant="ghost" :label="l('links_author_timestamp')" class="studio-settings-trigger group" :ui="{ leadingIcon: 'group-data-[state=open]:rotate-90 transition-transform' }" />
                <template #content>
                    <div class="studio-settings-content">
                        <UFormField :label="l('title_url')" class="studio-form-row"><UInput v-model="document.embed.url" type="url" size="sm" class="w-full" /></UFormField>
                        <UFormField :label="l('author_url')" class="studio-form-row"><UInput v-model="document.embed.authorUrl" type="url" size="sm" class="w-full" /></UFormField>
                        <ResourceImagePicker v-model="document.embed.authorIcon" :label="l('author_icon')" compact />
                        <UFormField :label="l('timestamp')" class="studio-form-row"><UInput v-model="document.embed.timestamp" type="datetime-local" size="sm" class="w-full" /></UFormField>
                    </div>
                </template>
            </UCollapsible>
            <UCollapsible v-model:open="fieldsOpen" class="studio-settings-section">
                <UButton icon="i-lucide-chevron-right" color="neutral" variant="ghost" class="studio-settings-trigger group" :ui="{ leadingIcon: 'group-data-[state=open]:rotate-90 transition-transform' }">{{ l('fields') }}<UBadge color="neutral" variant="soft" size="xs" class="ml-auto rounded-none">{{ document.embed.fields.length }}</UBadge></UButton>
                <template #content>
                    <div ref="fieldEditor" class="studio-settings-content">
                        <div v-for="(field, index) in document.embed.fields" :key="index" class="studio-embed-field-editor">
                            <div class="flex items-center gap-2"><UInput v-model="field.name" :aria-label="l('field_name')" :placeholder="l('field_name')" :maxlength="256" size="sm" class="min-w-0 flex-1" /><UTooltip :text="l('delete')"><UButton icon="i-lucide-trash-2" :aria-label="l('delete')" color="error" variant="ghost" size="xs" @click="document.embed.fields.splice(index, 1)" /></UTooltip></div>
                            <UTextarea v-model="field.value" :aria-label="l('field_value')" :placeholder="l('field_value')" :maxlength="1024" :rows="2" size="sm" class="w-full" />
                            <UCheckbox v-model="field.inline" :label="l('inline')" />
                        </div>
                        <p v-if="!document.embed.fields.length" class="text-xs text-muted">{{ l('none') }}</p>
                    </div>
                </template>
            </UCollapsible>
        </template>
        <p v-else class="text-sm text-muted">{{ l('no_embed') }}</p>
    </div>
</template>

<style scoped>
.studio-discord-editor { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.studio-discord-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.studio-discord-heading h2, .studio-embed-live-preview h3 { font-size: 14px; font-weight: 600; }
.studio-embed-form { display: flex; flex-direction: column; gap: 10px; }
.studio-color-field { display: flex; align-items: center; width: 140px; max-width: 100%; padding-left: 6px; border: 1px solid var(--ui-border); background: var(--ui-bg-elevated); }
.studio-color-field > input { width: 22px; height: 22px; flex: none; padding: 0; border: 0; cursor: pointer; background: transparent; }
.studio-color-field > input::-webkit-color-swatch-wrapper { padding: 0; }.studio-color-field > input::-webkit-color-swatch { border: 0; border-radius: 0; }
.studio-add-field { padding-left: 88px; }
.studio-embed-live-preview { display: flex; flex-direction: column; gap: 10px; margin: 2px 0; }
.studio-settings-section { border: 1px solid var(--ui-border); background: color-mix(in srgb, var(--ui-bg-elevated) 50%, transparent); }
.studio-settings-trigger { width: 100%; justify-content: flex-start; padding: 10px 12px; font-size: 12px; font-weight: 400; }
.studio-settings-content { padding: 0 12px 12px; display: flex; flex-direction: column; gap: 12px; }
.studio-embed-field-editor { display: flex; flex-direction: column; gap: 8px; padding-top: 12px; border-top: 1px solid var(--ui-border); }
.studio-embed-live-preview :deep(.discord-embed) { border-radius: 0; }
</style>
