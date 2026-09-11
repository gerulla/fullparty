<script setup lang="ts">
import { workspaceHasUnpublishedChanges } from '@/utils/resourceWorkspace'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceWorkspaceController, WorkspaceResource } from '@/Types/ResourceWorkspace'
import { resourceDragType } from '@/composables/useResourceTreeDrag'

const props = defineProps<{ workspace: ResourceWorkspaceController }>()
const { t, locale } = useI18n()
const l = (key: string) => t(`groups.resources.workspace.${key}`)
const sort = ref<'order' | 'title' | 'updatedAt'>('order')
const direction = ref(1)
const page = ref(1)
const pageSize = 7
const draggedId = ref<string | null>(null)
const dropTarget = ref<string | null>(null)
const statusItems = computed(() => ['all', 'published', 'draft'].map(value => ({ value, label: l(value === 'all' ? 'all_statuses' : value) })))
const accessItems = computed(() => ['all', 'everyone', 'moderators', 'admins'].map(value => ({ value, label: l(value === 'all' ? 'all_access' : value) })))
const activityItems = computed(() => [{ value: 'all', label: l('all_activities') }, ...props.workspace.activities.map(value => ({ value, label: value }))])
const sorted = computed(() => [...props.workspace.visibleResources].sort((a, b) => Number(!!b.isHome) - Number(!!a.isHome) || (sort.value === 'order' ? a.order - b.order : a[sort.value].localeCompare(b[sort.value], locale.value) * direction.value)))
const pageCount = computed(() => Math.max(1, Math.ceil(sorted.value.length / pageSize)))
const rows = computed(() => sorted.value.slice((page.value - 1) * pageSize, page.value * pageSize))
const checked = computed(() => props.workspace.state.checked)
const pageChecked = computed(() => rows.value.filter(item => checked.value.includes(item.id)).length)
const publishableIds = computed(() => checked.value.filter(id => props.workspace.state.resources.some(item => item.id === id && workspaceHasUnpublishedChanges(item))))
const movableIds = computed(() => checked.value.filter(id => props.workspace.state.resources.some(item => item.id === id && !item.isHome)))
const range = computed(() => ({ start: sorted.value.length ? (page.value - 1) * pageSize + 1 : 0, end: Math.min(page.value * pageSize, sorted.value.length), total: sorted.value.length }))
watch(() => [props.workspace.state.scope, props.workspace.state.query, props.workspace.state.status, props.workspace.state.access, props.workspace.state.activity], () => { page.value = 1 })
watch(pageCount, value => { page.value = Math.min(page.value, value) })
watch(() => props.workspace.state.selectedId, id => {
    const index = sorted.value.findIndex(item => item.id === id)
    if (index >= 0) page.value = Math.floor(index / pageSize) + 1
})
function toggle(id: string) { props.workspace.state.checked = checked.value.includes(id) ? checked.value.filter(item => item !== id) : [...checked.value, id] }
function togglePage() {
    const ids = rows.value.map(item => item.id)
    props.workspace.state.checked = pageChecked.value === rows.value.length ? checked.value.filter(id => !ids.includes(id)) : [...new Set([...checked.value, ...ids])]
}
function changeSort(column: 'title' | 'updatedAt') { direction.value = sort.value === column ? -direction.value : 1; sort.value = column; page.value = 1 }
function date(value: string) { return new Date(value).toLocaleString(locale.value, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }) }
function menu(resource: WorkspaceResource) {
    const siblings = props.workspace.state.resources.filter(item => item.collectionId === resource.collectionId && !item.isHome).sort((a, b) => a.order - b.order)
    const index = siblings.findIndex(item => item.id === resource.id)
    return [
        { label: l('edit'), icon: 'i-lucide-pencil', disabled: resource.status === 'archived', onSelect: () => props.workspace.edit(resource.id) },
        { label: l('view'), icon: 'i-lucide-external-link', to: props.workspace.viewUrl(resource), target: '_blank', rel: 'noopener noreferrer', disabled: !props.workspace.viewUrl(resource) },
        { label: l('move'), icon: 'i-lucide-folder-input', disabled: resource.isHome, onSelect: () => props.workspace.openMove([resource.id]) },
        { label: l('move_up'), icon: 'i-lucide-arrow-up', disabled: resource.isHome || index <= 0, onSelect: () => { sort.value = 'order'; props.workspace.reorder(resource.id, -1) } },
        { label: l('move_down'), icon: 'i-lucide-arrow-down', disabled: resource.isHome || index === siblings.length - 1, onSelect: () => { sort.value = 'order'; props.workspace.reorder(resource.id, 1) } },
        { label: l(resource.status === 'archived' ? 'restore_draft' : 'archive'), icon: resource.status === 'archived' ? 'i-lucide-archive-restore' : 'i-lucide-archive', disabled: resource.isHome, onSelect: () => resource.status === 'archived' ? props.workspace.unarchive(resource.id) : props.workspace.archive(resource.id) },
        { label: l('delete'), icon: 'i-lucide-trash-2', color: 'error' as const, disabled: resource.isHome, onSelect: () => props.workspace.remove(resource.id) },
    ]
}
function startDrag(event: DragEvent, id: string) {
    const resource = props.workspace.state.resources.find(item => item.id === id)
    if (!resource || resource.isHome) { event.preventDefault(); return }
    draggedId.value = id
    if (event.dataTransfer) { event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData(resourceDragType, JSON.stringify({ kind: 'resource', id })) }
}
function drop(id: string) { if (draggedId.value) { sort.value = 'order'; props.workspace.reorderBefore(draggedId.value, id) } draggedId.value = null; dropTarget.value = null }
</script>

<template>
    <section class="library-browser" :aria-label="l('library')">
        <header class="library-toolbar">
            <UInput v-model="workspace.state.query" icon="i-lucide-search" :placeholder="l('search')" :aria-label="l('search')" class="library-search" />
            <USelect v-model="workspace.state.status" :items="statusItems" :aria-label="l('status')" class="library-filter" />
            <USelect v-model="workspace.state.activity" :items="activityItems" :aria-label="l('activities')" class="library-filter" />
            <USelect v-model="workspace.state.access" :items="accessItems" :aria-label="l('access')" class="library-filter" />
            <UButton icon="i-lucide-folder-plus" color="neutral" variant="outline" :label="l('collection')" :disabled="!!workspace.collectionActions.state.editing" class="library-new-folder" @click="workspace.collectionActions.create(workspace.state.collections.some(item => item.id === workspace.state.scope) ? workspace.state.scope : null)" />
            <UButton icon="i-lucide-plus" color="neutral" variant="solid" :label="l('resource')" :loading="workspace.creatingResource" @click="workspace.createResource()" />
        </header>
        <div class="library-table-scroll">
            <table class="library-table">
                <caption class="sr-only">{{ l('all_resources') }}</caption>
                <colgroup><col class="selection-col" /><col class="grip-col" /><col /><col class="access-col" /><col class="command-col" /><col class="status-col" /><col class="edited-col" /><col class="actions-col" /></colgroup>
                <thead><tr>
                    <th><UCheckbox :model-value="pageChecked === rows.length && rows.length ? true : pageChecked ? 'indeterminate' : false" :aria-label="l('select_all')" @update:model-value="togglePage" /></th>
                    <th><span class="sr-only">{{ l('reorder') }}</span></th>
                    <th :aria-sort="sort === 'title' ? direction === 1 ? 'ascending' : 'descending' : 'none'"><button class="sort-heading title-heading" @click="changeSort('title')">{{ l('title') }}<UIcon :name="sort === 'title' ? direction === 1 ? 'i-lucide-arrow-up' : 'i-lucide-arrow-down' : 'i-lucide-chevrons-up-down'" /></button></th>
                    <th>{{ l('access') }}</th><th>{{ l('command') }}</th><th>{{ l('status') }}</th>
                    <th :aria-sort="sort === 'updatedAt' ? direction === 1 ? 'ascending' : 'descending' : 'none'"><button class="sort-heading" @click="changeSort('updatedAt')">{{ l('edited') }}<UIcon :name="sort === 'updatedAt' ? direction === 1 ? 'i-lucide-arrow-up' : 'i-lucide-arrow-down' : 'i-lucide-chevrons-up-down'" /></button></th>
                    <th><UTooltip :text="l('manual_order')"><UButton icon="i-lucide-list-ordered" color="neutral" variant="ghost" size="xs" :aria-label="l('manual_order')" @click="sort = 'order'" /></UTooltip></th>
                </tr></thead>
                <tbody>
                    <tr v-for="resource in rows" :key="resource.id" :class="{ 'is-selected': workspace.state.selectedId === resource.id, 'is-drop-target': dropTarget === resource.id }" @click="workspace.select(resource.id)" @dragover.prevent="dropTarget = resource.id" @dragleave="dropTarget = null" @drop.prevent="drop(resource.id)">
                        <td @click.stop><UCheckbox :model-value="checked.includes(resource.id)" :aria-label="`${l('select')}: ${resource.title}`" @update:model-value="toggle(resource.id)" /></td>
                        <td @click.stop><UTooltip :text="l('reorder')"><UDropdownMenu :items="menu(resource).slice(3, 5)"><UButton icon="i-lucide-grip-vertical" color="neutral" variant="ghost" size="xs" :aria-label="`${l('reorder')}: ${resource.title}`" draggable="true" class="resource-drag-handle" @dragstart="startDrag($event, resource.id)" @dragend="draggedId = null; dropTarget = null" /></UDropdownMenu></UTooltip></td>
                        <td><button class="resource-title-cell" @click.stop="workspace.select(resource.id)" @dblclick="workspace.edit(resource.id)"><img v-if="resource.cover" :src="resource.cover" alt="" class="resource-thumbnail" /><span v-else class="resource-thumbnail resource-thumbnail-empty"><UIcon name="i-lucide-file-text" /></span><span class="resource-title-copy"><strong>{{ resource.title }}</strong><span>{{ resource.description }}</span></span></button></td>
                        <td><span class="resource-access"><UIcon :name="resource.access === 'everyone' ? 'i-lucide-globe' : resource.access === 'moderators' ? 'i-lucide-lock-keyhole' : 'i-lucide-shield-check'" />{{ l(resource.access) }}</span></td>
                        <td><span v-for="(embed, index) in resource.embeds" :key="index" class="resource-command" :class="{ 'text-dimmed': !embed.enabled }" :title="`/info ${embed.command}`">/info {{ embed.command }}</span><span v-if="!resource.embeds.length" class="text-dimmed">{{ l('none') }}</span></td>
                        <td><UBadge :color="resource.status === 'published' ? 'success' : 'neutral'" variant="subtle" class="library-status" size="sm">{{ l(resource.status) }}</UBadge></td>
                        <td class="resource-edited">{{ date(resource.updatedAt) }}</td>
                        <td @click.stop><UDropdownMenu :items="menu(resource)"><UButton icon="i-lucide-ellipsis" color="neutral" variant="ghost" size="xs" :aria-label="`${l('actions')}: ${resource.title}`" /></UDropdownMenu></td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="8"><div class="library-empty"><UIcon name="i-lucide-files" /><p>{{ l('no_resources') }}</p><UButton icon="i-lucide-plus" color="neutral" variant="solid" :label="l('new_resource')" :loading="workspace.creatingResource" @click="workspace.createResource()" /></div></td></tr>
                </tbody>
            </table>
        </div>
        <footer class="library-bulk-bar">
            <span class="selection-summary">{{ checked.length ? t('groups.resources.workspace.selected_count', { count: checked.length }) : t('groups.resources.workspace.resource_count', { count: sorted.length }) }}</span>
            <div class="library-bulk-actions"><UButton icon="i-lucide-folder-input" color="neutral" variant="outline" :label="l('move_to')" :disabled="!movableIds.length" @click="workspace.openMove(movableIds)" /><UButton icon="i-lucide-rocket" color="neutral" variant="outline" :label="l('publish')" v-if="publishableIds.length" :disabled="workspace.busy" @click="workspace.publish(publishableIds)" /><UTooltip :text="l('clear_selection')"><UButton icon="i-lucide-list-x" color="neutral" variant="outline" :aria-label="l('clear_selection')" :disabled="!checked.length" @click="workspace.state.checked = []" /></UTooltip></div>
            <div class="library-pagination"><span>{{ t('groups.resources.workspace.result_range', range) }}</span><UPagination v-model:page="page" :items-per-page="pageSize" :total="sorted.length" :sibling-count="0" :show-edges="false" size="xs" /></div>
        </footer>
    </section>
</template>

<style scoped>
.library-browser { display: flex; flex-direction: column; min-width: 0; padding: 12px 10px 22px; container-type: inline-size; background: transparent; }
.library-toolbar { display: grid; grid-template-columns: minmax(130px, 1fr) 120px 122px 108px auto auto; align-items: center; gap: 10px; flex: none; margin: 0 6px 16px; }
.library-toolbar :deep(button), .library-toolbar :deep(input) { font-size: 12px; font-weight: 400; }
.library-search, .library-filter { width: 100%; min-width: 0; }
.library-table-scroll { min-height: 0; overflow: auto; border: 1px solid var(--ui-border); border-radius: 3px; }
.library-table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 12px; }
.selection-col { width: 34px; }.grip-col { width: 28px; }.access-col { width: 118px; }.command-col { width: 134px; }.status-col { width: 106px; }.edited-col { width: 116px; }.actions-col { width: 40px; }
.library-table th { height: 40px; text-align: left; font-weight: 400; background: var(--ui-bg-elevated); position: sticky; top: 0; z-index: 1; }
.library-table th, .library-table td { padding: 0 9px; border-bottom: 1px solid var(--ui-border); }
.library-table td:first-child, .library-table th:first-child { padding-left: 12px; padding-right: 0; }
.library-table td:nth-child(2), .library-table th:nth-child(2) { padding: 0; }
.library-table tr:last-child td { border-bottom: 0; }
.library-table tbody tr { height: 73px; cursor: pointer; transition: background-color .12s; }
.library-table tbody tr:hover { background: color-mix(in srgb, var(--ui-bg-elevated) 65%, transparent); }
.library-table tbody tr.is-selected { background: color-mix(in srgb, var(--color-brand-400) 25%, var(--ui-bg)); }
.library-table tbody tr.is-drop-target { box-shadow: inset 0 2px var(--ui-primary); }
.resource-title-cell { display: flex; align-items: center; gap: 14px; min-width: 0; width: 100%; text-align: left; }
.resource-thumbnail { width: 52px; height: 52px; border: 1px solid var(--ui-border-accented); border-radius: 3px; flex: none; object-fit: cover; }
.resource-thumbnail-empty { display: flex; align-items: center; justify-content: center; background: var(--ui-bg-elevated); color: var(--ui-text-muted); }.resource-thumbnail-empty > span { width: 22px; height: 22px; }
.resource-title-copy { display: flex; min-width: 0; flex-direction: column; gap: 5px; }.resource-title-copy strong { font-size: 13px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.resource-title-copy > span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--ui-text-muted); }
.resource-access { display: flex; align-items: center; gap: 9px; white-space: nowrap; }.resource-access > span { width: 17px; height: 17px; flex: none; }.resource-command { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.resource-edited { color: var(--ui-text-muted); white-space: nowrap; font-variant-numeric: tabular-nums; font-size: 11px; }
.resource-drag-handle { cursor: grab; color: var(--ui-text-muted); }.resource-drag-handle:active { cursor: grabbing; }.sort-heading { display: flex; align-items: center; gap: 6px; }.sort-heading > span { width: 13px; height: 13px; }.title-heading { padding-left: 66px; }.library-status { padding: 4px 10px; border-radius: 3px; font-size: 11px; font-weight: 400; }
.library-bulk-bar { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; min-height: 62px; flex: none; margin-top: auto; padding: 10px 12px; border: 1px solid var(--ui-border); border-radius: 3px; background: var(--ui-bg-elevated); }.selection-summary { font-size: 13px; font-weight: 500; margin-right: 10px; }.library-bulk-actions { display: flex; gap: 10px; }.library-pagination { display: flex; align-items: center; gap: 12px; margin-left: auto; font-size: 12px; white-space: nowrap; }
.library-empty { display: flex; min-height: 260px; flex-direction: column; align-items: center; justify-content: center; gap: 16px; color: var(--ui-text-muted); }.library-empty > span { width: 28px; height: 28px; }
@media (min-width: 1024px) { .library-table-scroll { min-height: 120px; } }
@container (max-width: 820px) { .library-toolbar { grid-template-columns: minmax(120px, 1fr) 116px 116px 104px auto auto; gap: 6px; }.access-col { width: 100px; }.command-col { width: 114px; }.status-col { width: 90px; }.edited-col { width: 104px; }.resource-access { gap: 5px; } }
@container (max-width: 720px) { .library-toolbar { grid-template-columns: repeat(3, minmax(0, 1fr)); }.library-search { grid-column: 1 / -1; }.library-table { min-width: 680px; }.edited-col { width: 0; }.library-table th:nth-child(7), .library-table td:nth-child(7) { display: none; }.library-pagination { gap: 6px; }.library-bulk-bar { margin-top: 20px !important; } }
@container (max-width: 430px) { .library-toolbar { grid-template-columns: repeat(2, minmax(0, 1fr)); }.library-bulk-actions { gap: 6px; }.library-pagination { width: 100%; justify-content: space-between; margin: 0; } }
</style>
