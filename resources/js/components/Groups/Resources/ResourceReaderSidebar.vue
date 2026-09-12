<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import ResourceReaderRow from './ResourceReaderRow.vue'
import type { ResourceReaderCollection, ResourceReaderSummary } from '@/Types/GroupResources'

defineProps<{
    collections: (ResourceReaderCollection & { depth: number; total: number })[]
    selectedId: number | null
    homeUrl: string
    collectionUrl: (slug: string) => string
    resourceUrl: (resource: ResourceReaderSummary) => string
    recent: ResourceReaderSummary[]
    related?: ResourceReaderSummary[]
    collectionName?: string
    pinned: ResourceReaderSummary[]
    atHome: boolean
}>()
const { t } = useI18n()
</script>

<template>
    <div class="min-w-0 space-y-8">
        <nav :aria-label="t('groups.resources.reader.browse_collections')">
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted">{{ t('groups.resources.reader.browse_collections') }}</h2>
            <Link :href="homeUrl" class="mb-1 flex items-center gap-2 px-2 py-2 text-sm hover:bg-elevated" :class="atHome ? 'bg-primary/10 text-primary' : 'text-muted'" :aria-current="atHome ? 'page' : undefined"><UIcon name="i-lucide-house" class="size-4 shrink-0" />{{ t('groups.resources.reader.home') }}</Link>
            <Link v-for="collection in collections" :key="collection.id" :href="collectionUrl(collection.slug)" class="flex items-start gap-2 py-2 pr-2 text-sm hover:bg-elevated" :class="collection.id === selectedId ? 'bg-primary/10 text-primary' : 'text-muted'" :style="{ paddingLeft: `${8 + Math.min(collection.depth, 4) * 12}px` }" :aria-current="collection.id === selectedId ? 'page' : undefined">
                <UIcon :name="collection.icon || 'i-lucide-folder'" class="mt-0.5 size-4 shrink-0" /><span class="min-w-0 flex-1 break-words">{{ collection.name }}</span><span class="text-xs tabular-nums text-dimmed">{{ collection.total }}</span>
            </Link>
        </nav>
        <section v-if="related?.length">
            <h2 class="break-words text-xs font-semibold uppercase tracking-wider text-muted">{{ collectionName ? t('groups.resources.reader.more_from', { collection: collectionName }) : t('groups.resources.reader.related') }}</h2>
            <ResourceReaderRow v-for="item in related" :key="item.id" :resource="item" :href="resourceUrl(item)" compact />
        </section>
        <section v-else-if="recent.length">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-muted">{{ t('groups.resources.reader.recent') }}</h2>
            <ResourceReaderRow v-for="item in recent" :key="item.id" :resource="item" :href="resourceUrl(item)" compact />
        </section>
        <section>
            <h2 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted"><UIcon name="i-lucide-pin" class="size-3.5" />{{ t('groups.resources.reader.pinned') }}</h2>
            <ResourceReaderRow v-for="item in pinned" :key="item.id" :resource="item" :href="resourceUrl(item)" compact />
            <p v-if="!pinned.length" class="mt-3 text-xs text-muted">{{ t('groups.resources.reader.no_pinned') }}</p>
        </section>
    </div>
</template>
