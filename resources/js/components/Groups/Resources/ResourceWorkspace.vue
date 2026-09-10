<script setup lang="ts">
import '@/bootstrap/markdownEditor.js'
import { MdPreview } from 'md-editor-v3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMockResourceWorkspace } from '@/composables/useMockResourceWorkspace'
import { collectionDescendants } from '@/utils/resourceWorkspace'
import ConfirmationModal from '@/components/Shared/Modals/ConfirmationModal.vue'
import ResourceCollectionSidebar from './ResourceCollectionSidebar.vue'
import ResourceLibraryBrowser from './ResourceLibraryBrowser.vue'
import ResourceDocumentEditor from './ResourceDocumentEditor.vue'
import ResourceEditorToolbar from './ResourceEditorToolbar.vue'
import ResourceWorkspaceInspector from './ResourceWorkspaceInspector.vue'
import ResourceLibraryInspector from './ResourceLibraryInspector.vue'

const workspace = useMockResourceWorkspace()
const { state } = workspace
const { t, locale } = useI18n()
const l = (key: string) => t(`groups.resources.workspace.${key}`)
const showCollections = ref(false)
const folders = computed(() => [{ value: 'root', label: l('unfiled') }, ...state.collections.map(item => ({ value: item.id, label: item.name }))])
const parents = computed(() => {
    const excluded = state.collectionForm.id ? collectionDescendants(state.collections, state.collectionForm.id) : []
    return folders.value.filter(item => !excluded.includes(item.value))
})
const parent = computed({ get: () => state.collectionForm.parentId ?? 'root', set: value => { state.collectionForm.parentId = value === 'root' ? null : value } })
const siblings = computed(() => state.collections.filter(item => item.parentId === state.collections.find(item => item.id === state.collectionForm.id)?.parentId))
const siblingIndex = computed(() => siblings.value.findIndex(item => item.id === state.collectionForm.id))
</script>

<template>
    <div class="resource-workspace-shell">
        <UButton class="workspace-mobile-toggle mb-3" icon="i-lucide-panel-left" color="neutral" variant="outline" :label="l('collections')" :aria-expanded="showCollections" @click="showCollections = !showCollections" />
        <UAlert v-if="state.error && !state.collectionDialog" color="error" variant="soft" icon="i-lucide-circle-alert" :title="state.error" class="mb-3" close @update:open="state.error = ''" />
        <div class="resource-workspace-grid min-h-[760px] overflow-hidden bg-transparent" :class="{ 'is-library': state.mode === 'library', 'is-editor': state.mode === 'editor' }">
            <ResourceCollectionSidebar :workspace="workspace" class="workspace-folders border-b border-default" :class="{ 'is-open': showCollections }">
                <template #library-actions><slot name="library-actions" /></template>
            </ResourceCollectionSidebar>
            <ResourceEditorToolbar v-if="state.mode === 'editor'" :workspace="workspace" class="workspace-editor-toolbar" />
            <ResourceLibraryBrowser v-if="state.mode === 'library'" :workspace="workspace" class="workspace-centre" />
            <ResourceDocumentEditor v-else :key="`editor-${state.selectedId ?? 'new'}`" :workspace="workspace" class="workspace-centre" />
            <ResourceLibraryInspector v-if="state.mode === 'library'" :workspace="workspace" class="workspace-inspector border-t border-default" />
            <ResourceWorkspaceInspector v-else :key="`inspector-${state.selectedId ?? 'new'}`" :workspace="workspace" class="workspace-inspector border-t border-default" />
        </div>
        <UModal v-model:open="state.collectionDialog" :title="l(state.collectionForm.id ? 'edit_collection' : 'new_collection')">
            <template #body>
                <form class="space-y-5" @submit.prevent="workspace.saveCollection()">
                    <UAlert v-if="state.error" :title="state.error" color="error" variant="soft" />
                    <UFormField :label="l('name')" required><UInput v-model="state.collectionForm.name" autofocus :maxlength="100" class="w-full" /></UFormField>
                    <UFormField :label="l('parent_collection')"><USelect v-model="parent" :items="parents" class="w-full" /></UFormField>
                    <div v-if="state.collectionForm.id" class="flex gap-2">
                        <UTooltip :text="l('move_up')"><UButton icon="i-lucide-arrow-up" color="neutral" variant="outline" :aria-label="l('move_up')" :disabled="siblingIndex <= 0" @click="workspace.reorderCollection(state.collectionForm.id, -1)" /></UTooltip>
                        <UTooltip :text="l('move_down')"><UButton icon="i-lucide-arrow-down" color="neutral" variant="outline" :aria-label="l('move_down')" :disabled="siblingIndex >= siblings.length - 1" @click="workspace.reorderCollection(state.collectionForm.id, 1)" /></UTooltip>
                    </div>
                    <div class="flex flex-wrap justify-end gap-2 border-t border-default pt-4">
                        <UButton v-if="state.collectionForm.id" icon="i-lucide-trash-2" color="error" variant="ghost" :label="l('delete')" class="mr-auto" @click="workspace.removeCollection(state.collectionForm.id)" />
                        <UButton color="neutral" variant="outline" :label="l('cancel')" @click="state.collectionDialog = false" />
                        <UButton type="submit" icon="i-lucide-check" :label="l('save')" />
                    </div>
                </form>
            </template>
        </UModal>
        <UModal v-model:open="state.moveDialog" :title="l('move_resources')">
            <template #body>
                <div class="space-y-5"><UFormField :label="l('collection')"><USelect v-model="state.moveTarget" :items="folders" class="w-full" /></UFormField>
                    <div class="flex justify-end gap-2"><UButton color="neutral" variant="outline" :label="l('cancel')" @click="state.moveDialog = false" /><UButton icon="i-lucide-folder-input" :label="l('move')" @click="workspace.move()" /></div>
                </div>
            </template>
        </UModal>
        <ConfirmationModal v-model:open="state.confirmation.open" :title="state.confirmation.title" description="" :warning-text="state.confirmation.description" :confirm-label="state.confirmation.label" severity="warning" :on-confirm="workspace.confirm" />
        <UModal v-model:open="state.historyOpen" :title="l('edit_history')" :description="workspace.selected?.title">
            <template #body><div class="space-y-5"><div v-for="revision in workspace.selected?.history" :key="revision.id" class="space-y-1 border-l-2 border-primary pl-4"><p class="text-xs text-muted">{{ new Date(revision.at).toLocaleString(locale) }}</p><p class="text-sm">{{ revision.summary }}</p><p class="text-xs text-muted">{{ revision.author }}</p></div><p v-if="!workspace.selected?.history.length" class="text-sm text-muted">{{ l('no_history') }}</p></div></template>
        </UModal>
        <UModal v-model:open="state.previewOpen" :title="state.preview?.title || l('preview')" :description="state.preview?.description" :ui="{ content: 'max-w-4xl' }">
            <template #body>
                <div v-if="state.preview" class="space-y-5">
                    <div class="flex flex-wrap items-center gap-3 text-xs text-muted"><span>{{ state.preview.author }}</span><UBadge color="neutral" variant="soft">{{ l(state.preview.access) }}</UBadge><span>{{ state.preview.activities.join(' / ') }}</span></div>
                    <img v-if="state.preview.cover" :src="state.preview.cover" alt="" class="max-h-64 w-full object-contain" />
                    <MdPreview :model-value="state.preview.body" theme="dark" language="en-US" :no-mermaid="true" :no-katex="true" class="resource-document-preview" />
                </div>
            </template>
        </UModal>
    </div>
</template>

<style scoped>
.resource-workspace-shell { container-type: inline-size; }
.resource-workspace-grid { display: grid; grid-template-columns: minmax(0, 1fr); }
.workspace-folders { display: none; }
.workspace-folders.is-open { display: flex; }
.resource-document-preview { background: transparent; }
.is-library, .is-editor { --ui-bg: #1a171d; --ui-bg-elevated: #242027; --ui-border: #37313d; --ui-border-accented: #49404f; --ui-text: #e9e2f0; --ui-text-muted: #b4a8c5; }
.resource-workspace-shell :deep(button:not([role="switch"])), .resource-workspace-shell :deep(input), .resource-workspace-shell :deep(textarea), .resource-workspace-shell :deep(select), .resource-workspace-shell :deep(a[data-slot=base]) { border-radius: 0; }
@container (min-width: 740px) {
    .resource-workspace-grid { grid-template-columns: 200px minmax(0, 1fr); }
    .workspace-folders { display: flex; grid-row: span 2; border-bottom: 0; border-right: 1px solid var(--ui-border); }
    .workspace-mobile-toggle { display: none; }
    .workspace-inspector { grid-column: 2; }
    .is-editor > .workspace-folders { grid-row: 1 / span 3; }
    .workspace-editor-toolbar { grid-column: 2; }
}
@container (min-width: 1040px) {
    .resource-workspace-grid { grid-template-columns: 210px minmax(0, 1fr) 290px; grid-template-rows: minmax(0, 1fr); }
    .workspace-folders, .workspace-centre, .workspace-inspector { min-height: 0; overflow-y: auto; }
    .workspace-folders { grid-row: 1; }
    .workspace-inspector { grid-column: 3; border-top: 0; border-left: 1px solid var(--ui-border); }
    .is-library { grid-template-columns: minmax(200px, 16.7%) minmax(0, 1fr) minmax(310px, 24%); }
    .is-editor { grid-template-columns: minmax(200px, 16.7%) minmax(0, 1fr) minmax(350px, 27%); grid-template-rows: auto minmax(0, 1fr); }
    .is-editor > .workspace-folders { grid-row: 1 / span 2; }
    .is-editor > .workspace-editor-toolbar { grid-column: 2 / -1; grid-row: 1; }
    .is-editor > .workspace-centre { grid-column: 2; grid-row: 2; }
    .is-editor > .workspace-inspector { grid-row: 2; }
}
@container (min-width: 1360px) {
    .resource-workspace-grid { grid-template-columns: 230px minmax(0, 1fr) 330px; }
    .is-library { grid-template-columns: 16.7% minmax(0, 1fr) 24%; }
    .is-editor { grid-template-columns: 16.7% minmax(0, 1fr) minmax(350px, 27%); }
}
@media (min-width: 1024px) {
    .resource-workspace-shell { display: flex; flex: 1; flex-direction: column; min-height: 0; }
    .workspace-mobile-toggle { align-self: flex-start; flex-shrink: 0; }
    .resource-workspace-grid { flex: 1; min-height: 0; overflow-y: auto; }
    @container (min-width: 1040px) {
        .resource-workspace-grid { overflow: hidden; }
    }
}
</style>
