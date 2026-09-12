<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { WorkspaceCollection } from '@/Types/ResourceWorkspace'

const props = defineProps<{ open: boolean; collectionId: string; collections: WorkspaceCollection[]; count: number; busy: boolean; error: string }>()
defineEmits<{ 'update:open': [value: boolean]; 'update:collectionId': [value: string]; save: [] }>()
const { t } = useI18n()
const items = computed(() => [{ value: 'disabled', label: t('groups.resources.holsters.not_listed') }, ...props.collections.map(collection => {
    const names = [collection.name]
    const seen = new Set([collection.id])
    let parent = props.collections.find(item => item.id === collection.parentId)
    while (parent && !seen.has(parent.id)) {
        seen.add(parent.id); names.unshift(parent.name)
        parent = props.collections.find(item => item.id === parent?.parentId)
    }
    return { value: collection.id, label: names.join(' / ') }
})])
</script>

<template>
    <UModal :open="open" :title="t('groups.resources.holsters.title')" :description="t('groups.resources.holsters.description')" :dismissible="!busy" @update:open="$emit('update:open', $event)">
        <template #body>
            <form class="space-y-5" @submit.prevent="$emit('save')">
                <UAlert v-if="error" color="error" variant="soft" :title="error" />
                <p class="text-sm text-muted">{{ t('groups.resources.holsters.sync_description') }}</p>
                <UBadge color="neutral" variant="subtle" icon="i-lucide-backpack" :label="t('groups.resources.holsters.active_count', { count })" />
                <UFormField :label="t('groups.resources.workspace.collection')">
                    <USelect :model-value="collectionId" :items="items" :disabled="busy" class="w-full" @update:model-value="$emit('update:collectionId', String($event))" />
                </UFormField>
                <p v-if="!collections.length" class="text-sm text-muted">{{ t('groups.resources.holsters.no_collections') }}</p>
                <div class="flex justify-end gap-2">
                    <UButton color="neutral" variant="outline" :label="t('groups.resources.workspace.cancel')" :disabled="busy" @click="$emit('update:open', false)" />
                    <UButton type="submit" icon="i-lucide-check" :label="t('groups.resources.workspace.save')" :loading="busy" />
                </div>
            </form>
        </template>
    </UModal>
</template>
