<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { WorkspaceDocument, WorkspaceEmbed } from '@/Types/ResourceWorkspace'
import { resourceEmbedCharacterCount } from '@/utils/resourceEmbedPreview'
import ResourceEmbedPreview from './ResourceEmbedPreview.vue'
import ResourceImagePicker from './ResourceImagePicker.vue'

const props = defineProps<{ document: WorkspaceDocument; embed: WorkspaceEmbed; previewEmbed: WorkspaceEmbed; commandError?: string; publicResource?: boolean; resourceUrl?: string; fieldError?: (path: string) => string | undefined; embedIndex?: number }>()
defineEmits<{ back: []; save: [] }>()
const { t, locale } = useI18n()
const l = (key: string) => t(`groups.resources.workspace.${key}`)
const help = (key: string) => t(`groups.resources.workspace.embed_help.${key}`)
const botAvatarUrl = '/logos/compact.png'
const path = (key = '') => `commands.${props.embedIndex ?? 0}.embed${key ? `.${key}` : ''}`
const error = (key = '') => props.fieldError?.(path(key))
const fieldEditor = ref<HTMLElement>()
const characterCount = computed(() => resourceEmbedCharacterCount(props.previewEmbed))
const timestamp = computed(() => {
    const date = new Date(props.previewEmbed.timestamp)
    return Number.isNaN(date.getTime()) ? l('set_on_save') : date.toLocaleString(locale.value, { dateStyle: 'medium', timeStyle: 'short' })
})
async function addField() {
    if (props.embed.fields.length >= 25) return
    props.embed.fields.push({ name: '', value: '', inline: false })
    await nextTick()
    const input = fieldEditor.value?.lastElementChild?.querySelector('input')
    input?.focus({ preventScroll: true })
    input?.scrollIntoView({ block: 'nearest' })
}
function moveField(index: number, offset: number) {
    const target = index + offset
    if (target < 0 || target >= props.embed.fields.length) return
    const [field] = props.embed.fields.splice(index, 1)
    props.embed.fields.splice(target, 0, field)
}
</script>

<template>
    <section class="embed-editor" @keydown.ctrl.s.prevent="$emit('save')" @keydown.meta.s.prevent="$emit('save')">
        <header class="embed-editor-heading">
            <div class="flex min-w-0 items-center gap-3"><UIcon name="ic:baseline-discord" class="size-6 shrink-0" /><h2>{{ l('edit_embed') }}</h2></div>
            <UButton icon="i-lucide-file-text" color="neutral" variant="outline" size="sm" :label="l('back_to_resource')" @click="$emit('back')" />
        </header>
        <section class="embed-editor-section">
            <UFormField name="command" :data-resource-field="`commands.${embedIndex ?? 0}.name`" :label="l('command')" :description="help('command')" :error="commandError" required>
                <UInput v-model="embed.command" :maxlength="64" class="w-full" :ui="{ leading: 'h-full border-r border-default px-3 font-mono text-muted', base: 'ps-20 font-mono' }"><template #leading>/info</template></UInput>
            </UFormField>
        </section>
        <section class="embed-editor-section" :data-resource-field="path()" :class="{ 'ring-1 ring-error p-2': error() }">
            <h3>{{ l('embed_content') }}</h3>
            <p v-if="error()" role="alert" class="text-sm text-error">{{ error() }}</p>
            <div class="embed-editor-grid">
                <UFormField :name="path('title')" :data-resource-field="path('title')" :error="error('title')" :label="l('embed_title')" :description="help('title')" class="embed-editor-wide" :hint="`${embed.title.length}/256`"><UInput v-model="embed.title" :maxlength="256" class="w-full" /></UFormField>
                <UFormField :name="path('description')" :data-resource-field="path('description')" :error="error('description')" :label="l('description')" :description="help('description')" class="embed-editor-wide" :hint="`${embed.description.length}/4096`"><UTextarea v-model="embed.description" :rows="6" :maxlength="4096" autoresize class="w-full" /></UFormField>
                <UFormField :name="path('url')" :data-resource-field="path('url')" :error="error('url')" :label="l('title_url')" :description="help('title_url')"><UInput v-model="embed.url" type="url" :maxlength="2048" placeholder="https://" class="w-full" /></UFormField>
                <UFormField :name="path('color')" :data-resource-field="path('color')" :error="error('color')" :label="l('color')" :description="help('color')">
                    <div class="flex items-center gap-2">
                        <UPopover :content="{ align: 'start' }" :ui="{ content: 'rounded-none bg-elevated p-3' }">
                            <UButton color="neutral" variant="outline" :aria-label="l('color')" class="h-9 w-10 justify-center"><span class="size-5 ring ring-default" :style="{ backgroundColor: /^#[a-f0-9]{6}$/i.test(embed.color) ? embed.color : '#8457b0' }" /></UButton>
                            <template #content><UColorPicker v-model="embed.color" format="hex" :aria-label="l('color')" /></template>
                        </UPopover>
                        <UInput v-model="embed.color" :maxlength="7" placeholder="#8457B0" :aria-label="l('color')" class="min-w-0 flex-1" :ui="{ base: 'font-mono' }" />
                    </div>
                </UFormField>
            </div>
        </section>
        <section class="embed-editor-section">
            <h3 class="flex items-center gap-2"><UIcon name="i-lucide-lock-keyhole" class="size-4" />{{ l('automatic_metadata') }}</h3>
            <p class="text-sm text-muted">{{ help('automatic_metadata') }}</p>
            <dl class="embed-metadata">
                <dt>{{ l('author') }}</dt><dd>{{ previewEmbed.author }}</dd>
                <dt>{{ l('author_url') }}</dt><dd><a v-if="previewEmbed.authorUrl" :href="previewEmbed.authorUrl" target="_blank" rel="noopener noreferrer" class="text-primary underline">{{ previewEmbed.authorUrl }}</a><span v-else class="text-muted">{{ l('no_public_link') }}</span></dd>
                <dt>{{ l('author_icon') }}</dt><dd><img v-if="previewEmbed.authorIcon" :src="previewEmbed.authorIcon" alt="" class="size-8 object-contain" /><span v-else class="text-muted">{{ l('no_group_icon') }}</span></dd>
                <dt>{{ l('timestamp') }}</dt><dd><time :datetime="previewEmbed.timestamp || undefined">{{ timestamp }}</time><p class="mt-1 text-xs text-muted">{{ help('timestamp') }}</p></dd>
            </dl>
        </section>
        <section class="embed-editor-section">
            <h3>{{ l('embed_media') }}</h3>
            <div class="embed-editor-grid">
                <ResourceImagePicker v-model="embed.thumbnail" :name="path('thumbnail')" :data-resource-field="path('thumbnail')" :error="error('thumbnail')" :label="l('thumbnail')" :description="help('thumbnail')" />
                <ResourceImagePicker v-model="embed.image" :name="path('image')" :data-resource-field="path('image')" :error="error('image')" :label="l('image')" :description="help('image')" />
            </div>
        </section>
        <section class="embed-editor-section">
            <div class="flex flex-wrap items-center justify-between gap-2"><h3>{{ l('fields') }} <span class="text-xs font-normal text-muted">{{ embed.fields.length }}/25</span></h3><UButton icon="i-lucide-plus" color="neutral" variant="outline" size="sm" :label="l('add_field')" :disabled="embed.fields.length >= 25" @click="addField" /></div>
            <p class="text-sm text-muted">{{ help('fields') }}</p>
            <p v-if="error('fields')" :data-resource-field="path('fields')" role="alert" class="text-sm text-error">{{ error('fields') }}</p>
            <div v-if="embed.fields.length" ref="fieldEditor" class="space-y-4">
                <div v-for="(field, index) in embed.fields" :key="index" class="embed-field-row">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-xs text-muted">{{ t('groups.resources.workspace.embed_field_number', { number: index + 1 }) }}</span>
                        <div class="flex gap-1">
                            <UTooltip :text="l('move_up')"><UButton icon="i-lucide-arrow-up" color="neutral" variant="ghost" size="xs" :aria-label="l('move_up')" :disabled="index === 0" @click="moveField(index, -1)" /></UTooltip>
                            <UTooltip :text="l('move_down')"><UButton icon="i-lucide-arrow-down" color="neutral" variant="ghost" size="xs" :aria-label="l('move_down')" :disabled="index === embed.fields.length - 1" @click="moveField(index, 1)" /></UTooltip>
                            <UTooltip :text="l('delete')"><UButton icon="i-lucide-trash-2" color="error" variant="outline" size="xs" :aria-label="l('delete')" @click="embed.fields.splice(index, 1)" /></UTooltip>
                        </div>
                    </div>
                    <UFormField :name="path(`fields.${index}.name`)" :data-resource-field="path(`fields.${index}.name`)" :error="error(`fields.${index}.name`)" :label="l('field_name')" :hint="`${field.name.length}/256`" required><UInput v-model="field.name" :maxlength="256" class="w-full" /></UFormField>
                    <UFormField :name="path(`fields.${index}.value`)" :data-resource-field="path(`fields.${index}.value`)" :error="error(`fields.${index}.value`)" :label="l('field_value')" :hint="`${field.value.length}/1024`" required><UTextarea v-model="field.value" :maxlength="1024" :rows="3" autoresize class="w-full" /></UFormField>
                    <UCheckbox v-model="field.inline" :label="l('inline')" :description="help('inline')" />
                </div>
            </div>
        </section>
        <section class="embed-editor-section">
            <p class="flex items-start gap-2 text-sm text-muted"><UIcon name="i-lucide-lock-keyhole" class="mt-0.5 size-4 shrink-0" /><span>{{ help('footer') }}</span></p>
        </section>
        <section class="embed-editor-section embed-preview-section">
            <div class="flex flex-wrap items-center justify-between gap-2"><h3>{{ l('preview') }}</h3><span class="text-xs tabular-nums" :class="characterCount > 6000 ? 'text-error' : 'text-muted'">{{ characterCount }}/6000</span></div>
            <p v-if="characterCount > 6000" role="alert" class="text-sm text-error">{{ help('character_limit') }}</p>
            <div class="embed-message-preview">
                <img :src="botAvatarUrl" alt="" class="embed-message-avatar" />
                <div class="min-w-0"><div class="mb-2 flex items-center gap-2 text-sm font-semibold text-white">FullParty <span class="bg-[#5865f2] px-1 py-0.5 text-[10px] leading-none">APP</span></div><ResourceEmbedPreview :document="document" :embed="previewEmbed" :public-resource="publicResource" :resource-url="resourceUrl" /></div>
            </div>
        </section>
    </section>
</template>

<style scoped>
.embed-editor { min-width: 0; min-height: 0; padding: 16px 20px 24px; container-type: inline-size; }
.embed-editor-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding-bottom: 16px; }
.embed-editor-heading h2 { font-size: 20px; font-weight: 600; }
.embed-editor-section { display: flex; flex-direction: column; gap: 16px; padding: 20px 0; border-top: 1px solid var(--ui-border); }
.embed-editor-section h3 { font-size: 15px; font-weight: 600; }
.embed-editor-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 20px 16px; }
.embed-metadata { display: grid; grid-template-columns: minmax(0, 100px) minmax(0, 1fr); gap: 12px 16px; font-size: 14px; }
.embed-metadata dt { color: var(--ui-text-muted); }
.embed-metadata dd { min-width: 0; overflow-wrap: anywhere; }
.embed-field-row { display: flex; flex-direction: column; gap: 12px; border-left: 2px solid var(--ui-border-accented); padding-left: 14px; }
.embed-message-preview { display: grid; grid-template-columns: 36px minmax(0, 1fr); gap: 12px; padding: 16px; background: #313338; }
.embed-message-avatar { width: 36px; height: 36px; object-fit: contain; }
.embed-preview-section :deep(.discord-embed-preview) { width: 100%; }
.embed-editor :deep(button), .embed-editor :deep(input), .embed-editor :deep(textarea) { border-radius: 0; }
@container (min-width: 560px) { .embed-editor-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }.embed-editor-wide { grid-column: 1 / -1; } }
@container (max-width: 360px) { .embed-message-preview { grid-template-columns: minmax(0, 1fr); }.embed-message-avatar { display: none; } }
</style>
