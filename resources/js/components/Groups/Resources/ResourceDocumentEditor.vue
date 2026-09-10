<script setup lang="ts">
import '@/bootstrap/markdownEditor.js'
import { MdEditor, type ExposeParam, type ToolbarNames } from 'md-editor-v3'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceWorkspaceController } from '@/Types/ResourceWorkspace'
import { readWorkspaceImage } from '@/utils/resourceWorkspaceImage'
import { workspaceActivities, workspaceAuthors } from '@/utils/mockResourceData'

const props = defineProps<{ workspace: ResourceWorkspaceController }>()
const { t, locale } = useI18n()
const l = (key: string) => t('groups.resources.workspace.' + key)
const draft = computed(() => props.workspace.state.draft)
const editor = ref<ExposeParam>()
const mode = ref<'markdown' | 'preview'>(draft.value?.body ? 'preview' : 'markdown')
const access = computed(() => ['everyone', 'moderators', 'admins'].map(value => ({ value, label: l(value) })))
const editedAt = computed(() => props.workspace.selected ? new Date(props.workspace.selected.updatedAt).toLocaleString(locale.value, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }) : '')
const toolbars: ToolbarNames[] = ['bold', 'italic', 'link', 'codeRow', '-', 'unorderedList', 'orderedList', '-', 'image', 'table', 'revoke', 'next', '=', 0]
function setMode(value: 'markdown' | 'preview') {
    mode.value = value
    editor.value?.togglePreview(value === 'preview')
    editor.value?.togglePreviewOnly(value === 'preview')
}
onMounted(() => setMode(mode.value))
async function uploadImages(files: File[], callback: (images: string[]) => void) {
    try { callback(await Promise.all(files.map(readWorkspaceImage))) }
    catch { props.workspace.state.error = l('image_invalid') }
}
</script>

<template>
    <section v-if="draft" class="studio-document">
        <div class="studio-metadata">
            <USelect v-model="draft.access" :items="access" icon="i-lucide-users" size="sm" :aria-label="l('access')" class="studio-access" />
            <USelectMenu v-model="draft.activities" multiple :items="workspaceActivities" icon="i-lucide-gamepad-2" size="sm" :aria-label="l('activities')" :placeholder="l('activities')" :search-input="false" class="studio-activities" :ui="{ content: 'rounded-none', item: 'rounded-none' }">
                <template #default><span class="studio-activity-values"><span v-for="activity in draft.activities" :key="activity">{{ activity }}</span><span v-if="!draft.activities.length">{{ l('activities') }}</span></span></template>
            </USelectMenu>
            <UInputTags v-model="draft.tags" icon="i-lucide-tag" size="sm" :aria-label="l('tags')" :placeholder="l('add_tag')" :add-on-blur="true" class="studio-tags" :ui="{ base: 'rounded-none bg-transparent ring-0', item: 'rounded-none', input: 'min-w-12' }" />
        </div>
        <UInput v-model="draft.title" :placeholder="l('title')" :aria-label="l('title')" class="studio-title w-full" :ui="{ base: 'rounded-none px-3 py-2 text-3xl font-semibold bg-transparent' }" />
        <UTextarea v-model="draft.description" :placeholder="l('description')" :aria-label="l('description')" :rows="1" autoresize class="studio-description w-full" :ui="{ base: 'rounded-none px-3 py-2 text-sm bg-transparent resize-none' }" />
        <div class="studio-editor-surface">
            <MdEditor ref="editor" v-model="draft.body" language="en-US" theme="dark" :toolbars="toolbars" :preview="false" :footers="[]"
                :no-mermaid="true" :no-katex="true" :no-echarts="true" class="resource-markdown-editor" @on-upload-img="uploadImages" @on-save="workspace.save()">
                <template #defToolbars>
                    <div class="studio-document-modes" role="group" :aria-label="l('document_view')">
                        <UButton :color="mode === 'markdown' ? 'primary' : 'neutral'" :variant="mode === 'markdown' ? 'solid' : 'ghost'" size="xs" :aria-pressed="mode === 'markdown'" :label="l('markdown')" @click="setMode('markdown')" />
                        <UButton :color="mode === 'preview' ? 'primary' : 'neutral'" :variant="mode === 'preview' ? 'solid' : 'ghost'" size="xs" :aria-pressed="mode === 'preview'" :label="l('preview')" @click="setMode('preview')" />
                    </div>
                </template>
            </MdEditor>
        </div>
        <footer class="studio-document-footer">
            <div class="studio-author">
                <UAvatar src="/characters/char1.png" :alt="draft.author" size="md" />
                <UFormField :label="l('author')"><USelect v-model="draft.author" :items="workspaceAuthors" variant="none" size="sm" class="w-full" :ui="{ base: 'p-0 pe-5 bg-transparent', trailing: 'pe-0' }" /></UFormField>
            </div>
            <div class="studio-last-edited"><span>{{ l('last_edit') }}</span><time :datetime="workspace.selected?.updatedAt">{{ editedAt }}</time></div>
            <UFormField :label="l('change_summary')" class="studio-summary"><UInput v-model="workspace.state.summary" :placeholder="l('summary_placeholder')" size="sm" class="w-full" /></UFormField>
        </footer>
    </section>
</template>

<style scoped>
.studio-document { display: flex; flex-direction: column; gap: 12px; min-width: 0; min-height: 0; padding: 12px 14px 14px; }
.studio-metadata { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; flex: none; min-width: 0; }
.studio-access { width: 134px; }.studio-activities { max-width: 240px; min-width: 115px; }
.studio-activity-values { display: flex; align-items: center; flex-wrap: wrap; gap: 4px; min-width: 0; }
.studio-activity-values > span { padding: 1px 5px; background: var(--ui-bg-elevated); font-size: 12px; white-space: nowrap; }
.studio-tags { flex: 1; min-width: 180px; }
.studio-title, .studio-description { flex: none; }
.studio-title :deep(input) { font-size: 32px; font-weight: 600; line-height: 40px; padding: 4px 12px; }
.studio-description :deep(textarea) { font-size: 14px; font-weight: 400; }
.studio-editor-surface { flex: 1; min-height: 240px; display: flex; border: 1px solid var(--ui-border); background: color-mix(in srgb, var(--ui-bg-elevated) 50%, transparent); }
.resource-markdown-editor { height: 100%; min-height: 240px; flex: 1; min-width: 0; }
.studio-document-modes { display: flex; align-items: center; border: 1px solid var(--ui-border); margin: 0 2px 0 8px; }
.studio-document-modes > button { min-height: 28px; padding: 4px 12px; }
.studio-document-footer { display: grid; grid-template-columns: minmax(0, 1fr) minmax(100px, .85fr) minmax(0, 1.4fr); align-items: center; gap: 16px; padding: 10px 12px; border: 1px solid var(--ui-border); background: color-mix(in srgb, var(--ui-bg-elevated) 45%, transparent); flex: none; }
.studio-author { display: flex; align-items: center; gap: 10px; min-width: 0; }.studio-author > div { min-width: 0; flex: 1; }
.studio-document-footer :deep(label), .studio-last-edited > span { font-size: 11px; font-weight: 400; color: var(--ui-text-muted); }
.studio-last-edited { display: flex; flex-direction: column; gap: 5px; font-size: 12px; padding-left: 16px; border-left: 1px solid var(--ui-border); }
.studio-summary { padding-left: 16px; border-left: 1px solid var(--ui-border); min-width: 0; }
@media (max-width: 767px) { .studio-document-footer { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }.studio-summary { grid-column: 1 / -1; border-left: 0; padding-left: 0; }.studio-editor-surface { flex: none; height: 620px; } }
</style>

<style>
.resource-markdown-editor.md-editor-dark { --md-bk-color: transparent; --md-color: var(--ui-text); --md-border-color: var(--ui-border); --md-bk-color-outstand: var(--ui-bg-elevated); border: 0; border-radius: 0; font-family: inherit; }
.resource-markdown-editor .md-editor-toolbar-wrapper { padding: 5px 8px; border-bottom: 1px solid var(--ui-border); background: color-mix(in srgb, var(--ui-bg-elevated) 55%, transparent); flex-wrap: wrap; }
.resource-markdown-editor .md-editor-toolbar { flex-wrap: wrap; }
.resource-markdown-editor .md-editor-toolbar-left { display: flex; flex-wrap: wrap; min-width: 0; max-width: 100%; }
.resource-markdown-editor .md-editor-toolbar-left > button { flex: none; }
.resource-markdown-editor .md-editor-toolbar-item { width: 28px; height: 28px; }
.resource-markdown-editor .md-editor-input-wrapper { padding: 18px; }
.resource-markdown-editor .md-editor-preview-wrapper { padding: 18px; }
.resource-markdown-editor .md-editor-preview { padding: 0; font-family: inherit; font-size: 14px; line-height: 1.65; word-break: normal; overflow-wrap: anywhere; }
.resource-markdown-editor .md-editor-preview h2 { padding: 0; border: 0; margin: 0 0 8px; font-size: 26px; line-height: 1.35; font-weight: 600; }
.resource-markdown-editor .md-editor-preview h3 { padding: 0; margin: 18px 0 8px; font-size: 20px; line-height: 1.4; font-weight: 600; }
.resource-markdown-editor .md-editor-preview p { margin: 8px 0 12px; }
.resource-markdown-editor .md-editor-preview img { display: block; width: 100%; max-height: 240px; object-fit: contain; border: 1px solid var(--ui-border); border-radius: 0; background: #161318; }
.resource-markdown-editor .md-editor-preview table { display: table; width: 100%; margin: 16px 0; font-size: 13px; border-collapse: collapse; }
.resource-markdown-editor .md-editor-preview th, .resource-markdown-editor .md-editor-preview td { border: 1px solid var(--ui-border); padding: 6px 10px; text-align: left; }
.resource-markdown-editor .md-editor-preview th { background: var(--ui-bg-elevated); }
.resource-markdown-editor .md-editor-preview table tbody tr { background: transparent; }
.resource-markdown-editor .md-editor-preview ul { padding-left: 20px; }.resource-markdown-editor .md-editor-preview li { margin: 4px 0; }
</style>
