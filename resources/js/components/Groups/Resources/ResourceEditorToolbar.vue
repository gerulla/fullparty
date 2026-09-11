<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceWorkspaceController } from '@/Types/ResourceWorkspace'
import { workspaceCollectionPath } from '@/utils/resourceWorkspace'

const props = defineProps<{ workspace: ResourceWorkspaceController }>()
const { t, locale } = useI18n()
const l = (key: string) => t(`groups.resources.workspace.${key}`)
const path = computed(() => workspaceCollectionPath(props.workspace.state.collections, props.workspace.state.draft?.collectionId ?? null) || l('unfiled'))
const savedTime = computed(() => props.workspace.selected ? new Date(props.workspace.selected.updatedAt).toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit', hour12: false }) : '')
</script>

<template>
    <header class="studio-toolbar">
        <div class="studio-breadcrumb">
            <UTooltip :text="l('back_to_library')"><UButton icon="i-lucide-arrow-left" :aria-label="l('back_to_library')" color="neutral" variant="ghost" size="xs" :disabled="workspace.busy" @click="workspace.back()" /></UTooltip>
            <span class="studio-path" :title="path">{{ path }}</span><span class="text-dimmed">/</span><strong :title="workspace.state.draft?.title">{{ workspace.state.draft?.title || l('untitled') }}</strong>
        </div>
        <div class="studio-save-state">
            <UBadge color="neutral" variant="soft" class="rounded-none" size="sm"><span class="size-1.5 bg-primary" />{{ l('editing_draft') }}</UBadge>
            <span role="status" :class="workspace.state.autosaveError || workspace.state.conflict ? 'text-error' : workspace.dirty ? 'text-warning' : 'text-muted'"><UIcon :name="workspace.autosaving ? 'i-lucide-loader-circle' : workspace.dirty ? 'i-lucide-circle' : 'i-lucide-check'" :class="{ 'animate-spin': workspace.autosaving }" />{{ workspace.autosaving ? l('autosaving') : workspace.dirty ? l('unsaved_changes') : t('groups.resources.workspace.saved_at', { time: savedTime }) }}</span>
        </div>
        <div class="studio-save-actions">
            <UButton icon="i-lucide-save" color="neutral" variant="outline" size="sm" :label="l('save_version')" :loading="workspace.busy" :disabled="workspace.autosaving || workspace.state.conflict" @click="workspace.save()" />
            <UButton v-if="workspace.canPublish" icon="i-lucide-check" size="sm" :label="l('publish')" :disabled="workspace.busy || workspace.autosaving || workspace.state.conflict" @click="workspace.save(true)" />
        </div>
    </header>
</template>

<style scoped>
.studio-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; min-width: 0; min-height: 56px; padding: 10px 14px; border-bottom: 1px solid var(--ui-border); background: transparent; }
.studio-breadcrumb { display: flex; align-items: center; gap: 8px; min-width: 180px; flex: 1; font-size: 12px; color: var(--ui-text-muted); }
.studio-breadcrumb strong { min-width: 0; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--ui-text-highlighted); font-weight: 600; }
.studio-path { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.studio-save-state, .studio-save-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.studio-save-state > span:last-child { display: flex; align-items: center; gap: 5px; font-size: 11px; }
.studio-save-state > span:last-child > span { width: 12px; height: 12px; }
@media (max-width: 639px) { .studio-breadcrumb { flex-basis: 100%; }.studio-save-actions { order: 1; flex-basis: 100%; }.studio-save-actions > button { flex: 1; justify-content: center; } }
</style>
