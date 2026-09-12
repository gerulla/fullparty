<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceWorkspaceController, WorkspaceResource } from '@/Types/ResourceWorkspace'

const props = defineProps<{ workspace: ResourceWorkspaceController; resource: WorkspaceResource }>()
const { t } = useI18n()
const atLimit = computed(() => !props.resource.isPinned && props.workspace.pinnedCount >= props.workspace.pinLimit)
const label = computed(() => t(`groups.resources.workspace.${props.resource.isPinned ? 'unpin_resource' : 'pin_resource'}`))
const hint = computed(() => t(`groups.resources.workspace.${atLimit.value ? 'pin_limit' : 'pin_hint'}`, { limit: props.workspace.pinLimit }))
</script>

<template>
    <UTooltip v-if="!resource.isHome && (resource.status !== 'archived' || resource.isPinned)" :text="hint">
        <UButton :icon="resource.isPinned ? 'i-lucide-pin-off' : 'i-lucide-pin'" :label="label" :aria-pressed="resource.isPinned" :color="resource.isPinned ? 'primary' : 'neutral'" variant="outline" size="sm" :disabled="workspace.busy || workspace.autosaving || workspace.state.conflict || atLimit" @click="workspace.togglePin(resource.id)" />
    </UTooltip>
</template>
