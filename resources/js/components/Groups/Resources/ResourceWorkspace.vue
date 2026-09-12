<script setup lang="ts">
import { computed, defineAsyncComponent, provide, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useResourceWorkspace } from '@/composables/useResourceWorkspace'
import { useResourceHolsters } from '@/composables/useResourceHolsters'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import ResourceHolsterModal from './ResourceHolsterModal.vue'
import type { ResourceCollectionData, ResourceDetailData, ResourceLibrary, ResourceWorkspaceData } from '@/Types/GroupResources'
import ConfirmationModal from '@/components/Shared/Modals/ConfirmationModal.vue'
import ResourceCollectionSidebar from './ResourceCollectionSidebar.vue'
import ResourceLibraryBrowser from './ResourceLibraryBrowser.vue'
import ResourceDocumentEditor from './ResourceDocumentEditor.vue'
import ResourceDiscordEditor from './ResourceDiscordEditor.vue'
import ResourceEditorToolbar from './ResourceEditorToolbar.vue'
import ResourceWorkspaceInspector from './ResourceWorkspaceInspector.vue'
import ResourceLibraryInspector from './ResourceLibraryInspector.vue'
import ResourceSaveModal from './ResourceSaveModal.vue'
import ResourceUploadsBrowser from './ResourceUploadsBrowser.vue'
import ResourceImageInspector from './ResourceImageInspector.vue'
import { resourceImageLibraryKey, useResourceImages } from '@/composables/useResourceImages'

const props = defineProps<{ groupSlug: string; collections: ResourceCollectionData[]; data: ResourceWorkspaceData; resource?: ResourceDetailData; library?: ResourceLibrary }>()
const emit = defineEmits<{ libraryChanged: [] }>()
const workspace = useResourceWorkspace(props, () => emit('libraryChanged'))
const { state } = workspace
const holsters = useResourceHolsters(() => props.groupSlug, () => props.data.holsters, () => router.reload({ only: ['workspace', 'collections'], onSuccess: () => workspace.refresh() }))
const holsterSettings = computed(() => state.collections.some(item => item.id === String(holsters.state.data.collection_id))
    ? holsters.state.data : { ...holsters.state.data, collection_id: null, resources: [] })
const holsterEditUrl = computed(() => route('groups.dashboard.content.delubrum-reginae-savage', { group: props.groupSlug }))
function openHolsters() { holsters.state.data = holsterSettings.value; holsters.open() }
const editingEmbed = computed(() => state.editorPane === 'embed' ? state.draft?.embeds[state.embedIndex] : null)
const imageLibrary = { groupSlug: () => props.groupSlug, changed: () => emit('libraryChanged') }
provide(resourceImageLibraryKey, { ...imageLibrary, resourceId: () => state.mode === 'editor' ? state.selectedId : null })
const images = useResourceImages(imageLibrary)
const { t, locale } = useI18n()
const l = (key: string) => t(`groups.resources.workspace.${key}`)
const showCollections = ref(false)
watch(() => workspace.collectionActions.state.editing, edit => { if (edit) showCollections.value = true })
const folders = computed(() => [{ value: 'root', label: l('root') }, ...state.collections.map(item => ({ value: item.id, label: item.name }))])
const ResourceCollectionIconModal = defineAsyncComponent(() => import('./ResourceCollectionIconModal.vue'))
const iconCollection = computed(() => state.collections.find(item => item.id === workspace.collectionActions.state.iconCollectionId))
</script>

<template>
    <div class="resource-workspace-shell">
        <UButton class="workspace-mobile-toggle mb-3" icon="i-lucide-panel-left" color="neutral" variant="outline" :label="l('collections')" :aria-expanded="showCollections" @click="showCollections = !showCollections" />
        <UAlert v-if="state.error && !state.createResourceDialog && !state.saveDialog && !state.confirmation.open" color="error" variant="soft" icon="i-lucide-circle-alert" :title="state.error" class="mb-3" close @update:open="state.error = ''" />
        <div v-if="state.mode === 'editor' && (state.autosaveError || state.conflict)" class="mb-3 flex flex-wrap items-center gap-3 border border-error p-3" role="status">
            <p class="min-w-0 flex-1 text-sm text-error">{{ l(state.conflict ? 'autosave_conflict' : 'autosave_failed') }}</p>
            <UButton icon="i-lucide-refresh-cw" color="neutral" variant="outline" :label="l('retry_save')" :loading="workspace.autosaving" :disabled="!workspace.canRetrySave" @click="workspace.retrySave()" />
            <UButton icon="i-lucide-file-clock" color="neutral" variant="outline" :label="l('reload_latest')" @click="workspace.reloadEditor()" />
        </div>
        <div class="resource-workspace-grid min-h-[760px] overflow-hidden bg-transparent" :class="{ 'is-library': state.mode !== 'editor', 'is-editor': state.mode === 'editor' }">
            <ResourceCollectionSidebar :workspace="workspace" :holsters="holsterSettings" :inert="workspace.busy || images.state.busy" class="workspace-folders border-b border-default" :class="{ 'is-open': showCollections }" @configure-holsters="openHolsters">
                <template #library-actions><slot name="library-actions" /></template>
            </ResourceCollectionSidebar>
            <ResourceEditorToolbar v-if="state.mode === 'editor'" :workspace="workspace" class="workspace-editor-toolbar" />
            <ResourceLibraryBrowser v-if="state.mode === 'library'" :workspace="workspace" :inert="workspace.busy" class="workspace-centre" />
            <ResourceUploadsBrowser v-else-if="state.mode === 'uploads'" :images="images" class="workspace-centre" />
            <template v-else>
                <ResourceDocumentEditor v-show="!editingEmbed" :key="`editor-${state.selectedId ?? 'new'}`" :workspace="workspace" :holster-edit-url="holsterEditUrl" :inert="workspace.busy" class="workspace-centre" />
                <ResourceDiscordEditor v-if="editingEmbed && state.draft" :key="`embed-${state.selectedId}-${state.embedIndex}`" :document="state.draft" :embed="editingEmbed" :preview-embed="workspace.embedPreview(state.draft, editingEmbed)" :command-error="workspace.commandError(state.embedIndex)" :field-error="workspace.fieldError" :embed-index="state.embedIndex" :public-resource="workspace.library?.visibility === 'public'" :resource-url="workspace.viewUrl()" :inert="workspace.busy" class="workspace-centre" @back="workspace.showResourceEditor()" @save="workspace.save()" />
            </template>
            <div v-if="workspace.loadingResource" class="workspace-inspector flex items-center justify-center border-l border-default"><UIcon name="i-lucide-loader-circle" class="size-5 animate-spin" :aria-label="t('general.loading')" /></div>
            <ResourceLibraryInspector v-else-if="state.mode === 'library'" :workspace="workspace" :inert="workspace.busy" class="workspace-inspector border-t border-default" />
            <ResourceImageInspector v-else-if="state.mode === 'uploads'" :images="images" class="workspace-inspector border-t border-default" />
            <ResourceWorkspaceInspector v-else :key="`inspector-${state.selectedId ?? 'new'}`" :workspace="workspace" :inert="workspace.busy" class="workspace-inspector border-t border-default" />
        </div>
        <UModal v-model:open="state.createResourceDialog" :title="l('new_resource')" :dismissible="!workspace.creatingResource">
            <template #body>
                <form class="space-y-5" @submit.prevent="workspace.confirmCreateResource()">
                    <UAlert v-if="state.error" :title="state.error" color="error" variant="soft" />
                    <UFormField :label="l('collection')" required><USelect v-model="state.createCollectionId" :items="folders.filter(item => item.value !== 'root')" :disabled="workspace.creatingResource" class="w-full" /></UFormField>
                    <div class="flex justify-end gap-2"><UButton color="neutral" variant="outline" :label="l('cancel')" :disabled="workspace.creatingResource" @click="state.createResourceDialog = false" /><UButton type="submit" color="neutral" icon="i-lucide-plus" :label="l('new_resource')" :loading="workspace.creatingResource" /></div>
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
        <ResourceCollectionIconModal v-if="iconCollection" :key="iconCollection.id" :name="iconCollection.name" :icon="iconCollection.icon" :busy="workspace.collectionActions.state.busy" :error="workspace.collectionActions.state.error" @close="workspace.collectionActions.closeIconPicker()" @select="workspace.collectionActions.saveIcon" />
        <ResourceHolsterModal v-model:open="holsters.state.open" v-model:collection-id="holsters.state.collectionId" :collections="state.collections" :count="holsters.state.data.active_count" :busy="holsters.state.busy" :error="holsters.state.error" @save="holsters.save" />
        <ResourceSaveModal :workspace="workspace" />
        <ConfirmationModal v-model:open="state.confirmation.open" :title="state.confirmation.title" :description="state.error" :warning-text="state.confirmation.description" :confirm-label="state.confirmation.label" :severity="state.confirmation.severity ?? 'warning'" :confirm-loading="workspace.busy" :on-confirm="workspace.confirm" @close="state.confirmation.open = false" />
        <UModal v-model:open="state.historyOpen" :title="l('edit_history')" :description="workspace.selected?.title">
            <template #body><div class="space-y-5"><div v-for="revision in workspace.selected?.history" :key="revision.id" class="space-y-1 border-l-2 border-primary pl-4"><p class="text-xs text-muted">{{ new Date(revision.at).toLocaleString(locale) }}</p><p class="text-sm">{{ revision.summary }}</p><p class="text-xs text-muted">{{ revision.author }}</p></div><p v-if="!workspace.selected?.history.length" class="text-sm text-muted">{{ l('no_history') }}</p></div></template>
        </UModal>
    </div>
</template>

<style scoped>
.resource-workspace-shell { container-type: inline-size; }
.resource-workspace-grid { display: grid; grid-template-columns: minmax(0, 1fr); }
.workspace-folders { display: none; }
.workspace-folders.is-open { display: flex; }
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
