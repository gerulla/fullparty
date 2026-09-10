<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceWorkspaceController } from '@/Types/ResourceWorkspace'
import { buildWorkspaceTree } from '@/utils/resourceWorkspace'
const props = defineProps<{ workspace: ResourceWorkspaceController }>()
const { t } = useI18n()
const l = (key: string) => t(`groups.resources.workspace.${key}`)
const collapsed = ref<string[]>(['ba', 'tower', 'essentials'])
const tree = computed(() => buildWorkspaceTree(props.workspace.state.collections, props.workspace.state.resources, collapsed.value))
watch(() => props.workspace.selected?.collectionId, id => {
    const ancestors = new Set<string>()
    let current = id
    while (current && !ancestors.has(current)) {
        ancestors.add(current)
        current = props.workspace.state.collections.find(item => item.id === current)?.parentId
    }
    collapsed.value = collapsed.value.filter(item => !ancestors.has(item))
}, { immediate: true })
const shortcuts = computed(() => [
    { id: 'all', label: l('all_resources'), icon: 'i-lucide-shapes', count: props.workspace.state.resources.length },
    { id: 'drafts', label: l('drafts'), icon: 'i-lucide-folder', count: props.workspace.state.resources.filter(item => item.status === 'draft').length },
    { id: 'pending', label: l('pending_publication'), icon: '', count: props.workspace.state.resources.filter(item => item.status === 'pending').length },
])
const menu = computed(() => [
    { label: l('new_collection'), icon: 'i-lucide-folder-plus', onSelect: () => props.workspace.openCollection() },
    { label: l('edit_collection'), icon: 'i-lucide-pencil', disabled: !props.workspace.state.collections.some(item => item.id === props.workspace.state.scope), onSelect: () => props.workspace.openCollection(props.workspace.state.scope) },
    { label: l('expand_all'), icon: 'i-lucide-chevrons-down-up', onSelect: () => { collapsed.value = [] } },
    { label: l('collapse_all'), icon: 'i-lucide-chevrons-up-down', onSelect: () => { collapsed.value = props.workspace.state.collections.map(item => item.id) } },
])
function toggle(id: string) { collapsed.value = collapsed.value.includes(id) ? collapsed.value.filter(item => item !== id) : [...collapsed.value, id] }
</script>

<template>
    <aside class="collection-sidebar">
        <header class="collection-heading"><h2>{{ l('collections') }}</h2><UTooltip :text="l('new_collection')"><UButton icon="i-lucide-folder-plus" :aria-label="l('new_collection')" color="neutral" variant="ghost" size="xs" @click="workspace.openCollection()" /></UTooltip><UDropdownMenu :items="menu"><UButton icon="i-lucide-ellipsis-vertical" :aria-label="l('collections')" color="neutral" variant="ghost" size="xs" /></UDropdownMenu></header>
        <div class="collection-navigation">
            <nav class="library-shortcuts" :aria-label="l('library')"><button v-for="item in shortcuts" :key="item.id" class="collection-shortcut" :class="{ active: workspace.state.scope === item.id }" :aria-current="workspace.state.scope === item.id ? 'page' : undefined" @click="workspace.browse(item.id)"><UIcon v-if="item.icon" :name="item.icon" :class="{ 'collection-folder-icon': item.icon === 'i-lucide-folder' }" /><span v-else class="pending-dot" /><span class="shortcut-label">{{ item.label }}</span><span class="folder-count">{{ item.count }}</span></button></nav>
            <div class="collection-separator" />
            <nav class="collection-tree" :aria-label="l('collections')">
                <template v-for="item in tree" :key="item.kind === 'collection' ? `collection-${item.collection.id}` : `resource-${item.resource.id}`">
                    <div v-if="item.kind === 'collection'" class="collection-row" :class="{ active: workspace.state.scope === item.collection.id && !workspace.selected, 'current-collection': workspace.state.scope === item.collection.id }" :style="{ paddingLeft: `${6 + item.depth * 18}px` }">
                        <button v-if="item.hasChildren" class="folder-chevron" :aria-label="item.collection.name" :aria-expanded="!collapsed.includes(item.collection.id)" @click="toggle(item.collection.id)"><UIcon :name="collapsed.includes(item.collection.id) ? 'i-lucide-chevron-right' : 'i-lucide-chevron-down'" /></button><span v-else class="folder-chevron" />
                        <button class="folder-link" :title="item.collection.name" :aria-current="workspace.state.scope === item.collection.id ? 'page' : undefined" @click="workspace.browse(item.collection.id)" @dblclick="workspace.openCollection(item.collection.id)"><UIcon name="i-lucide-folder" class="collection-folder-icon" /><span class="folder-name">{{ item.collection.name }}</span><span class="folder-count">{{ item.count }}</span></button>
                        <UTooltip :text="l('edit_collection')"><UButton icon="i-lucide-ellipsis" :aria-label="`${l('edit_collection')}: ${item.collection.name}`" color="neutral" variant="ghost" size="xs" class="folder-actions" @click="workspace.openCollection(item.collection.id)" /></UTooltip>
                    </div>
                    <div v-else class="collection-row resource-file-row" :class="{ active: workspace.state.selectedId === item.resource.id }" :style="{ paddingLeft: `${23 + item.depth * 18}px` }">
                        <button class="folder-link resource-file-link" :title="item.resource.title" :aria-current="workspace.state.selectedId === item.resource.id ? 'page' : undefined" @click="workspace.browseResource(item.resource.id)" @dblclick="workspace.edit(item.resource.id)"><UIcon name="i-lucide-file" /><span class="folder-name">{{ item.resource.title }}</span></button>
                    </div>
                </template>
            </nav>
        </div>
        <footer class="collection-footer">
            <div class="storage-summary"><div><span>{{ l('storage') }}</span><span>248 MB / 1 GB</span></div><UProgress :model-value="24.8" size="lg" :ui="{ base: 'rounded-none', indicator: 'rounded-none' }" :aria-label="l('storage')" /></div>
            <div class="public-library-summary">
                <div><UIcon name="i-lucide-globe" /><span class="public-library-label">{{ l('public_library') }}</span><slot name="library-actions" /></div>
                <p>{{ l('library_public_status') }}</p>
            </div>
        </footer>
    </aside>
</template>

<style scoped>
.collection-sidebar { display: flex; flex-direction: column; min-width: 0; background: transparent; }.collection-heading { display: flex; align-items: center; gap: 4px; min-height: 56px; padding: 12px 12px 8px 22px; }.collection-heading h2 { flex: 1; font-size: 16px; font-weight: 600; }
.collection-heading, .collection-footer { flex-shrink: 0; }.collection-navigation { flex: 1; min-height: 0; overflow-y: auto; }.public-library-label { flex: 1; min-width: 0; }
.library-shortcuts { padding: 0 10px; }.collection-shortcut { display: flex; align-items: center; gap: 12px; width: 100%; min-height: 34px; padding: 6px 12px; text-align: left; font-size: 13px; border-radius: 3px; }.shortcut-label { flex: 1; min-width: 0; }.collection-shortcut > span:first-child { flex: none; width: 16px; height: 16px; }.collection-shortcut > .pending-dot { width: 14px; height: 14px; border-radius: 50%; background: #edaf48; margin: 0 1px; }
.collection-separator { border-top: 1px solid var(--ui-border); margin: 16px 20px 13px; }.collection-tree { padding: 0 10px 20px; }.collection-row { position: relative; display: flex; align-items: center; min-height: 34px; padding-right: 8px; border-radius: 3px; }.collection-row:hover, .collection-shortcut:hover { background: var(--ui-bg-elevated); }.collection-row.active, .collection-shortcut.active { background: color-mix(in srgb, var(--color-brand-400) 30%, var(--ui-bg)); color: var(--ui-text-highlighted); }
.folder-chevron { display: flex; align-items: center; justify-content: center; width: 17px; height: 30px; flex: none; }.folder-chevron > span { width: 12px; height: 12px; }.folder-link { display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1; text-align: left; padding: 7px 0; font-size: 13px; }.folder-link > span:first-child { width: 17px; height: 17px; flex: none; }.folder-name { flex: 1; min-width: 0; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }.folder-count { font-size: 12px; color: var(--ui-text-muted); font-variant-numeric: tabular-nums; }
.folder-actions { position: absolute; right: 3px; opacity: 0; background: var(--ui-bg-elevated); }.collection-row:hover .folder-actions, .collection-row:focus-within .folder-actions { opacity: 1; }.collection-row:hover .folder-count, .collection-row:focus-within .folder-count { visibility: hidden; }
.collection-folder-icon { color: #d5b168; transform: scale(1.2); }.current-collection { color: var(--ui-text-highlighted); }.resource-file-row { border-radius: 0; }.resource-file-row.active { box-shadow: inset 2px 0 0 var(--ui-primary); }.resource-file-link > span:first-child { color: var(--ui-text-muted); }.resource-file-row.active .resource-file-link { font-weight: 500; }.resource-file-row.active .resource-file-link > span:first-child { color: var(--ui-text-highlighted); }
.collection-footer { margin-top: auto; border-top: 1px solid var(--ui-border); }.storage-summary { padding: 13px 22px; }.storage-summary > div { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 8px; font-size: 12px; color: var(--ui-text-muted); }.public-library-summary { border-top: 1px solid var(--ui-border); padding: 14px 22px 30px; }.public-library-summary > div { display: flex; gap: 10px; align-items: center; font-size: 13px; }.public-library-summary > div > span:first-child { width: 18px; height: 18px; }.public-library-summary p { margin-top: 7px; font-size: 12px; color: var(--ui-text-muted); }
</style>
