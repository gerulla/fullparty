<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceReaderSummary } from '@/Types/GroupResources'

const props = defineProps<{ resources: ResourceReaderSummary[]; href: (resource: ResourceReaderSummary) => string; editUrl: string }>()
defineEmits<{ configure: [] }>()
const { t } = useI18n()
const page = ref(1)
const size = 7
const rows = computed(() => props.resources.slice((page.value - 1) * size, page.value * size))
watch(() => props.resources, () => { page.value = 1 })
</script>

<template>
    <section v-if="resources.length" class="mt-5 border border-default">
        <header class="flex flex-wrap items-center gap-3 border-b border-default bg-elevated px-4 py-3">
            <h3 class="flex flex-1 items-center gap-2 text-sm font-medium"><UIcon name="i-lucide-backpack" class="size-4" />{{ t('groups.resources.holsters.title') }} <span class="text-muted">({{ resources.length }})</span></h3>
            <UButton color="neutral" variant="ghost" size="xs" icon="i-lucide-folder-input" :label="t('groups.resources.holsters.change_collection')" @click="$emit('configure')" />
            <UButton color="neutral" variant="outline" size="xs" icon="i-lucide-external-link" :label="t('groups.resources.holsters.manage')" :to="editUrl" target="_blank" />
        </header>
        <p class="border-b border-default px-4 py-3 text-xs text-muted">{{ t('groups.resources.holsters.managed_in_drs') }}</p>
        <a v-for="resource in rows" :key="resource.id" :href="href(resource)" target="_blank" rel="noopener noreferrer" class="flex items-center gap-3 border-b border-default px-4 py-3 hover:bg-elevated">
            <UIcon name="i-lucide-backpack" class="size-5 shrink-0 text-muted" />
            <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium">{{ resource.title }}</span><span v-if="resource.description" class="mt-1 block truncate text-xs text-muted">{{ resource.description }}</span></span>
            <UBadge color="success" variant="subtle" size="sm" :label="t('groups.resources.holsters.live')" />
            <UIcon name="i-lucide-external-link" class="size-4 shrink-0 text-muted" />
        </a>
        <div v-if="resources.length > size" class="flex justify-center p-3"><UPagination v-model:page="page" :total="resources.length" :items-per-page="size" size="xs" /></div>
    </section>
</template>
