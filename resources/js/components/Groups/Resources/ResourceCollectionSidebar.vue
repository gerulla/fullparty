<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ContextMenuItem } from '@nuxt/ui'
import type { ResourceWorkspaceController, WorkspaceCollection } from '@/Types/ResourceWorkspace'
import { workspaceHasUnpublishedChanges, buildWorkspaceTree } from '@/utils/resourceWorkspace'
import { formatBytes } from '@/utils/formatBytes'
import ResourceCollectionNameInput from './ResourceCollectionNameInput.vue'
import { useResourceTreeDrag } from '@/composables/useResourceTreeDrag'
const props = defineProps<{ workspace: ResourceWorkspaceController }>()
const drag = useResourceTreeDrag(props.workspace)
const { t, locale } = useI18n()
const l = (key: string) => t(`groups.resources.workspace.${key}`)
const storage = computed(() => props.workspace.library?.storage)
const storagePercent = computed(() => storage.value?.quota_bytes ? Math.min(100, storage.value.used_bytes / storage.value.quota_bytes * 100) : 0)
const storageText = computed(() => storage.value ? `${formatBytes(storage.value.used_bytes, locale.value)} / ${formatBytes(storage.value.quota_bytes, locale.value)}` : '')
const isPublic = computed(() => props.workspace.library?.visibility === 'public')
const collapsed = ref<string[]>([])
const actions = computed(() => props.workspace.collectionActions)
const temporaryId = '__new-collection__'
const nameInputs = ref<{ focus: () => void }[]>([])
const menuDisabled = computed(() => props.workspace.busy || !!actions.value.state.editing)
const tree = computed(() => {
    const edit = actions.value.state.editing
    const collections = edit && !edit.id ? [...props.workspace.state.collections, { id: temporaryId, name: edit.name, parentId: edit.parentId, icon: 'i-lucide-folder', order: Infinity }] : props.workspace.state.collections
    const resources = props.workspace.state.resources.filter(resource => resource.status !== 'archived').map(resource => props.workspace.state.mode === 'editor' && resource.id === props.workspace.state.selectedId && props.workspace.state.draft
        ? { ...resource, embeds: props.workspace.state.draft.embeds } : resource)
    return buildWorkspaceTree(collections, resources, collapsed.value)
})
function expandAncestors(id: string | null | undefined) {
    const ancestors = new Set<string>()
    let current = id
    while (current && !ancestors.has(current)) {
        ancestors.add(current)
        current = props.workspace.state.collections.find(item => item.id === current)?.parentId
    }
    collapsed.value = collapsed.value.filter(item => !ancestors.has(item))
}
watch(() => props.workspace.selected?.collectionId, expandAncestors, { immediate: true })
watch(() => actions.value.state.editing, edit => { if (edit) expandAncestors(edit.parentId) })
watch(() => props.workspace.state.draft?.embeds.length, () => {
    if (props.workspace.state.selectedId) collapsed.value = collapsed.value.filter(id => id !== `resource:${props.workspace.state.selectedId}`)
})
const shortcuts = computed(() => [
    { id: 'all', label: l('all_resources'), icon: 'i-lucide-shapes', count: props.workspace.state.resources.filter(item => item.status !== 'archived').length },
    { id: 'uploads', label: t('groups.resources.uploads.title'), icon: 'i-lucide-images', count: null },
    { id: 'drafts', label: l('drafts'), icon: 'i-lucide-folder', count: props.workspace.state.resources.filter(item => workspaceHasUnpublishedChanges(item)).length },
    { id: 'archived', label: l('archive'), icon: 'i-lucide-archive', count: props.workspace.state.resources.filter(item => item.status === 'archived').length },
])
const menu = computed(() => [
    { label: l('new_collection'), icon: 'i-lucide-folder-plus', onSelect: () => actions.value.create(null) },
    { label: l('expand_all'), icon: 'i-lucide-chevrons-down-up', onSelect: () => { collapsed.value = [] } },
    { label: l('collapse_all'), icon: 'i-lucide-chevrons-up-down', onSelect: () => { collapsed.value = [...props.workspace.state.collections.map(item => item.id), ...props.workspace.state.resources.map(item => `resource:${item.id}`)] } },
])
const emptyMenu = computed(() => [{ label: l('new_collection'), icon: 'i-lucide-folder-plus', onSelect: () => actions.value.create(null) }])
const menuContent = { onCloseAutoFocus: (event: Event) => {
    if (!actions.value.state.editing) return
    event.preventDefault()
    void nextTick(() => nameInputs.value[0]?.focus())
} }
const folderMenus = computed(() => {
    const groups = new Map<string | null, WorkspaceCollection[]>()
    for (const collection of props.workspace.state.collections) {
        const siblings = groups.get(collection.parentId) ?? []
        siblings.push(collection)
        groups.set(collection.parentId, siblings)
    }
    const menus = new Map<string, { dots: ContextMenuItem[][]; context: ContextMenuItem[][] }>()
    for (const siblings of groups.values()) {
        siblings.sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
        siblings.forEach(({ id }, index) => {
            const dots: ContextMenuItem[][] = [
                [{ label: l('new_resource'), icon: 'i-lucide-file-plus', onSelect: () => props.workspace.createResource(id) }],
                [{ label: l('rename'), icon: 'i-lucide-pencil', onSelect: () => actions.value.rename(id) }],
                [{ label: l('move_up'), icon: 'i-lucide-arrow-up', disabled: index === 0, onSelect: () => { void actions.value.reorder(id, -1) } },
                    { label: l('move_down'), icon: 'i-lucide-arrow-down', disabled: index === siblings.length - 1, onSelect: () => { void actions.value.reorder(id, 1) } }],
                [{ label: l('delete'), icon: 'i-lucide-trash-2', color: 'error', onSelect: () => { void actions.value.remove(id) } }],
            ]
            menus.set(id, { dots, context: [[{ label: l('new_collection'), icon: 'i-lucide-folder-plus', onSelect: () => actions.value.create(id) }], ...dots] })
        })
    }
    return menus
})
const noFolderMenu: ContextMenuItem[][] = []
function folderMenu(id: string, withCreate = false) {
    const menu = folderMenus.value.get(id)
    return (withCreate ? menu?.context : menu?.dots) ?? noFolderMenu
}
function editing(id: string) { return !!actions.value.state.editing && (actions.value.state.editing.id ?? temporaryId) === id }
function toggle(id: string) { collapsed.value = collapsed.value.includes(id) ? collapsed.value.filter(item => item !== id) : [...collapsed.value, id] }
</script>

<template>
    <aside class="collection-sidebar">
        <header class="collection-heading"><h2>{{ l('collections') }}</h2><UTooltip :text="l('new_collection')"><UButton icon="i-lucide-folder-plus" :aria-label="l('new_collection')" color="neutral" variant="ghost" size="xs" :disabled="menuDisabled" @click="actions.create(null)" /></UTooltip><UDropdownMenu :items="menu" :content="menuContent" :disabled="menuDisabled"><UButton icon="i-lucide-ellipsis-vertical" :aria-label="l('collections')" color="neutral" variant="ghost" size="xs" :disabled="menuDisabled" /></UDropdownMenu></header>
        <div class="collection-navigation">
            <nav class="library-shortcuts" :aria-label="l('library')"><button v-for="item in shortcuts" :key="item.id" class="collection-shortcut" :class="{ active: workspace.state.scope === item.id }" :aria-current="workspace.state.scope === item.id ? 'page' : undefined" @click="workspace.browse(item.id)"><UIcon :name="item.icon" :class="{ 'collection-folder-icon': item.icon === 'i-lucide-folder' }" /><span class="shortcut-label">{{ item.label }}</span><span class="folder-count">{{ item.count }}</span></button></nav>
            <div class="collection-separator" />
            <p v-if="actions.state.error" role="alert" class="px-4 pb-3 text-xs text-error break-words">{{ actions.state.error }}</p>
            <UContextMenu :items="emptyMenu" :disabled="menuDisabled" :content="menuContent">
            <nav class="collection-tree" :class="drag.classes()" :aria-label="l('collections')" @dragover.self="drag.over($event)" @drop.self="drag.drop($event)">
                <template v-for="item in tree" :key="item.kind === 'collection' ? `collection-${item.collection.id}` : item.kind === 'resource' ? `resource-${item.resource.id}` : `embed-${item.resource.id}-${item.index}`">
                    <UContextMenu v-if="item.kind === 'collection'" :items="folderMenu(item.collection.id, true)" :disabled="menuDisabled || item.collection.id === temporaryId" :content="menuContent">
                    <div class="collection-row" :class="[{ active: workspace.state.scope === item.collection.id && !workspace.selected, 'current-collection': workspace.state.scope === item.collection.id }, drag.classes(item)]" :style="{ paddingLeft: `${6 + item.depth * 18}px` }" :draggable="drag.canDrag(item)" @dragstart.stop="drag.start($event, item)" @dragend="drag.end()" @dragover.stop="drag.over($event, item)" @drop.stop="drag.drop($event, item)" @contextmenu.stop>
                        <button v-if="item.hasChildren" class="folder-chevron" :aria-label="item.collection.name" :aria-expanded="!collapsed.includes(item.collection.id)" @click="toggle(item.collection.id)"><UIcon :name="collapsed.includes(item.collection.id) ? 'i-lucide-chevron-right' : 'i-lucide-chevron-down'" /></button><span v-else class="folder-chevron" />
                        <div v-if="editing(item.collection.id)" class="folder-link"><UIcon name="i-lucide-folder" class="collection-folder-icon" /><ResourceCollectionNameInput ref="nameInputs" :actions="actions" /></div>
                        <button v-else class="folder-link" :title="item.collection.name" :aria-current="workspace.state.scope === item.collection.id ? 'page' : undefined" @click="workspace.browse(item.collection.id)" @keydown.f2.prevent="actions.rename(item.collection.id)"><UIcon name="i-lucide-folder" class="collection-folder-icon" /><span class="folder-name">{{ item.collection.name }}</span><span class="folder-count">{{ item.count }}</span></button>
                        <UDropdownMenu v-if="!editing(item.collection.id)" :items="folderMenu(item.collection.id)" :disabled="menuDisabled" :content="menuContent"><UButton icon="i-lucide-ellipsis" :aria-label="`${l('edit_collection')}: ${item.collection.name}`" color="neutral" variant="ghost" size="xs" class="folder-actions" :disabled="menuDisabled" /></UDropdownMenu>
                    </div>
                    </UContextMenu>
                    <div v-else-if="item.kind === 'resource'" class="collection-row resource-file-row" :class="[{ active: workspace.state.selectedId === item.resource.id }, drag.classes(item)]" :style="{ paddingLeft: `${6 + item.depth * 18}px` }" :draggable="drag.canDrag(item)" @dragstart.stop="drag.start($event, item)" @dragend="drag.end()" @dragover.stop="drag.over($event, item)" @drop.stop="drag.drop($event, item)" @contextmenu.stop>
                        <button v-if="item.resource.embeds.length" class="folder-chevron" :aria-label="item.resource.title" :aria-expanded="!collapsed.includes(`resource:${item.resource.id}`)" @click="toggle(`resource:${item.resource.id}`)"><UIcon :name="collapsed.includes(`resource:${item.resource.id}`) ? 'i-lucide-chevron-right' : 'i-lucide-chevron-down'" /></button><span v-else class="folder-chevron" />
                        <button class="folder-link resource-file-link" :title="item.resource.title" :aria-current="workspace.state.selectedId === item.resource.id ? 'page' : undefined" @click="workspace.browseResource(item.resource.id)" @dblclick="workspace.edit(item.resource.id)"><UIcon :name="item.resource.isHome ? 'i-lucide-house' : 'i-lucide-file'" /><span class="folder-name">{{ item.resource.title }}</span></button>
                    </div>
                    <div v-else class="collection-row resource-file-row" :class="{ active: workspace.state.selectedId === item.resource.id && workspace.state.inspectorTab === 'discord' && workspace.state.embedIndex === item.index }" :style="{ paddingLeft: `${23 + item.depth * 18}px` }" @contextmenu.stop @dragover.stop @drop.stop>
                        <button class="folder-link resource-file-link" :title="item.embed.command ? `/info ${item.embed.command}` : l('new_embed')" @click="workspace.openEmbed(item.resource.id, item.index)"><UIcon name="ic:baseline-discord" /><span class="folder-name">{{ item.embed.command || item.embed.title || l('new_embed') }}</span><UIcon v-if="!item.embed.enabled" name="i-lucide-circle-pause" class="size-3 shrink-0 text-muted" :aria-label="l('disabled')" /></button>
                    </div>
                </template>
            </nav>
            </UContextMenu>
        </div>
        <footer class="collection-footer">
            <div class="storage-summary"><div><span>{{ l('storage') }}</span><span>{{ storageText }}</span></div><UProgress :model-value="storagePercent" size="lg" :ui="{ base: 'rounded-none', indicator: 'rounded-none' }" :aria-label="l('storage')" /></div>
            <div class="public-library-summary">
                <div>
                    <UIcon :name="isPublic ? 'i-lucide-globe' : 'i-lucide-lock-keyhole'" class="shrink-0" />
                    <span class="public-library-label">{{ isPublic ? l('public_library') : t('groups.resources.library.group_only') }}</span>
                    <UTooltip v-if="isPublic && workspace.library?.public_url" :text="l('view_public_library')">
                        <UButton icon="i-lucide-external-link" color="neutral" variant="ghost" size="xs" class="shrink-0" :aria-label="l('view_public_library')" :to="workspace.library.public_url" target="_blank" rel="noopener noreferrer" />
                    </UTooltip>
                    <slot name="library-actions" />
                </div>
                <p v-if="isPublic">{{ l('library_public_status') }}</p>
            </div>
        </footer>
    </aside>
</template>

<style scoped>
.drop-inside { outline: 1px solid var(--ui-primary); outline-offset: -1px; background: var(--ui-bg-elevated); }.drop-before { box-shadow: inset 0 2px var(--ui-primary); }
.collection-sidebar { display: flex; flex-direction: column; min-width: 0; background: transparent; }.collection-heading { display: flex; align-items: center; gap: 4px; min-height: 56px; padding: 12px 12px 8px 22px; }.collection-heading h2 { flex: 1; font-size: 16px; font-weight: 600; }
.collection-heading, .collection-footer { flex-shrink: 0; }.collection-navigation { flex: 1; min-height: 0; overflow-y: auto; }.public-library-label { flex: 1; min-width: 0; }
.library-shortcuts { padding: 0 10px; }.collection-shortcut { display: flex; align-items: center; gap: 12px; width: 100%; min-height: 34px; padding: 6px 12px; text-align: left; font-size: 13px; border-radius: 3px; }.shortcut-label { flex: 1; min-width: 0; }.collection-shortcut > span:first-child { flex: none; width: 16px; height: 16px; }
.collection-separator { border-top: 1px solid var(--ui-border); margin: 16px 20px 13px; }.collection-tree { padding: 0 10px 20px; }.collection-row { position: relative; display: flex; align-items: center; min-height: 34px; padding-right: 8px; border-radius: 3px; }.collection-row:hover, .collection-shortcut:hover { background: var(--ui-bg-elevated); }.collection-row.active, .collection-shortcut.active { background: color-mix(in srgb, var(--color-brand-400) 30%, var(--ui-bg)); color: var(--ui-text-highlighted); }
.folder-chevron { display: flex; align-items: center; justify-content: center; width: 17px; height: 30px; flex: none; }.folder-chevron > span { width: 12px; height: 12px; }.folder-link { display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1; text-align: left; padding: 7px 0; font-size: 13px; }.folder-link > span:first-child { width: 17px; height: 17px; flex: none; }.folder-name { flex: 1; min-width: 0; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }.folder-count { font-size: 12px; color: var(--ui-text-muted); font-variant-numeric: tabular-nums; }
.folder-actions { position: absolute; right: 3px; opacity: 0; background: var(--ui-bg-elevated); }.collection-row:hover .folder-actions, .collection-row:focus-within .folder-actions { opacity: 1; }.collection-row:hover .folder-count, .collection-row:focus-within .folder-count { visibility: hidden; }
.collection-folder-icon { color: #d5b168; transform: scale(1.2); }.current-collection { color: var(--ui-text-highlighted); }.resource-file-row { border-radius: 0; }.resource-file-row.active { box-shadow: inset 2px 0 0 var(--ui-primary); }.resource-file-link > span:first-child { color: var(--ui-text-muted); }.resource-file-row.active .resource-file-link { font-weight: 500; }.resource-file-row.active .resource-file-link > span:first-child { color: var(--ui-text-highlighted); }
.collection-footer { margin-top: auto; border-top: 1px solid var(--ui-border); }.storage-summary { padding: 13px 22px; }.storage-summary > div { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 8px; font-size: 12px; color: var(--ui-text-muted); }.public-library-summary { border-top: 1px solid var(--ui-border); padding: 14px 22px 30px; }.public-library-summary > div { display: flex; gap: 10px; align-items: center; font-size: 13px; }.public-library-summary > div > span:first-child { width: 18px; height: 18px; }.public-library-summary p { margin-top: 7px; font-size: 12px; color: var(--ui-text-muted); }
.collection-navigation { display: flex; flex-direction: column; }.library-shortcuts, .collection-separator { flex-shrink: 0; }.collection-tree { flex: 1; min-height: 120px; }
</style>
