<script setup lang="ts">
import { computed, provide, ref } from 'vue'
import { resourceContentKey } from '@/Types/ResourceContent'
import { resourceContentExtensions } from './resourceContentExtensions'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { ResourceReaderPage } from '../../../Types/GroupResources'
import { useResourceReader } from '@/composables/useResourceReader'
import { resourceOutline } from '@/utils/resourceOutline'
import { useResourceContents } from '@/composables/useResourceContents'
import RichTextReader from '@/components/Shared/RichText/RichTextReader.vue'
import ResourceReaderArticle from './ResourceReaderArticle.vue'
import ResourceReaderRow from './ResourceReaderRow.vue'
import ResourceReaderSidebar from './ResourceReaderSidebar.vue'
import ResourceReaderHistory from './ResourceReaderHistory.vue'
import ResourceReaderCommands from './ResourceReaderCommands.vue'
import ResourceTableOfContents from './ResourceTableOfContents.vue'

const props = withDefaults(defineProps<ResourceReaderPage & { publicView?: boolean }>(), { publicView: false })
const { t } = useI18n()
const hub = useResourceReader(() => props, props.publicView)
const { navigation, draft, loading, selectedCollection, collectionPath, filtered, article, showHome, childCollections, sections, activityItems } = hub
const title = computed(() => selectedCollection.value?.name || (filtered.value ? t('groups.resources.reader.search_results') : props.library.customization.title))
const sidebar = computed(() => ({
    collections: hub.tree.value,
    selectedId: selectedCollection.value?.id ?? article.value?.collection_id ?? null,
    homeUrl: navigation.home.value,
    collectionUrl: navigation.collection,
    resourceUrl: navigation.resource,
    recent: props.reader.recent_resources,
    related: article.value?.related_resources,
    collectionName: props.collections.find(collection => collection.id === article.value?.collection_id)?.name,
    pinned: props.reader.pinned_resources,
    atHome: !selectedCollection.value && !article.value && !filtered.value,
}))
const hasRows = computed(() => sections.value.some(section => section.resources.length))
const readingResource = computed(() => article.value || (showHome.value ? props.resource : null))
const contentExtensions = resourceContentExtensions()
provide(resourceContentKey, { resources: computed(() => readingResource.value?.linked_resources ?? []), href: navigation.resource })
const outline = computed(() => readingResource.value ? resourceOutline(readingResource.value.body) : null)
const contents = computed(() => outline.value ? [
    ...outline.value.sections,
    ...(readingResource.value?.commands?.length ? [{ id: 'resource-discord-commands', title: t('groups.resources.reader.discord_commands'), depth: 0 }] : []),
    { id: 'resource-history', title: t('groups.resources.reader.history'), depth: 0 },
] : [])
const contentRoot = ref<HTMLElement | null>(null)
const { activeId } = useResourceContents(() => contentRoot.value, () => contents.value)
</script>

<template>
    <section class="resource-knowledge-hub min-w-0 bg-default text-default" :aria-label="t('groups.resources.reader.title')" :aria-busy="loading">
        <header class="grid items-start gap-5 border-b border-default pb-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <div class="min-w-0">
                <nav v-if="article || selectedCollection" class="mb-3 flex flex-wrap items-center gap-1.5 text-xs text-muted" :aria-label="t('groups.resources.reader.breadcrumbs')">
                    <Link :href="navigation.home.value" class="hover:text-primary">{{ t('groups.resources.reader.home') }}</Link>
                    <template v-for="collection in collectionPath" :key="collection.id"><UIcon name="i-lucide-chevron-right" class="size-3" /><Link :href="navigation.collection(collection.slug)" class="break-words hover:text-primary">{{ collection.name }}</Link></template>
                </nav>
                <Link v-if="article" :href="navigation.home.value" class="text-lg font-medium text-highlighted hover:text-primary">{{ library.customization.title }}</Link>
                <template v-else>
                    <h1 class="break-words text-2xl font-semibold tracking-tight text-highlighted sm:text-3xl">{{ title }}</h1>
                    <p v-if="selectedCollection || filtered" class="mt-2 text-sm text-muted">{{ t('groups.resources.reader.resource_count', resources.total) }}</p>
                    <p v-else class="mt-3 max-w-2xl whitespace-pre-line break-words text-sm leading-relaxed text-muted">{{ library.customization.introduction }}</p>
                </template>
            </div>
            <form class="min-w-0 space-y-2" role="search" @submit.prevent="hub.visit()">
                <div class="flex gap-1.5">
                    <UInput v-model="draft.q" icon="i-lucide-search" :placeholder="t('groups.resources.reader.search_placeholder')" :aria-label="t('groups.resources.reader.search_placeholder')" :maxlength="200" class="min-w-0 flex-1" />
                    <UButton type="submit" icon="i-lucide-arrow-right" color="neutral" variant="outline" :loading="loading" :aria-label="t('groups.resources.reader.search')" />
                </div>
                <div class="flex items-center gap-2">
                    <USelect v-if="reader.activities.length" :model-value="draft.activity" :items="activityItems" :aria-label="t('groups.resources.reader.all_activities')" size="sm" class="min-w-0 flex-1" @update:model-value="hub.activity(String($event))" />
                    <UButton v-if="filtered" color="neutral" variant="ghost" size="xs" icon="i-lucide-x" :label="t('groups.resources.reader.clear_filters')" @click="hub.clear" />
                </div>
                <UButton v-if="filters.tag" color="primary" variant="soft" size="xs" trailing-icon="i-lucide-x" :label="`#${filters.tag}`" :aria-label="t('groups.resources.reader.remove_tag', { tag: filters.tag })" @click="hub.visit(1, null)" />
            </form>
        </header>
        <details class="mt-5 border-b border-default pb-4 lg:hidden">
            <summary class="flex cursor-pointer items-center gap-2 text-sm font-medium text-muted"><UIcon name="i-lucide-panel-right" class="size-4" />{{ t('groups.resources.reader.browse_collections') }}<UIcon name="i-lucide-chevron-down" class="ml-auto size-4" /></summary>
            <ResourceReaderSidebar v-bind="sidebar" class="pt-5" />
        </details>
        <div class="grid min-w-0 gap-8 pt-6 lg:grid-cols-[minmax(0,1fr)_16rem]" :class="readingResource ? 'xl:grid-cols-[10rem_minmax(0,1fr)_14rem]' : 'xl:gap-10'">
            <aside v-if="readingResource" class="hidden min-w-0 xl:block"><ResourceTableOfContents :sections="contents" :active-id="activeId" class="sticky top-6 max-h-[calc(100vh-3rem)] overflow-y-auto" /></aside>
            <main ref="contentRoot" class="resource-reader-content min-w-0">
                <details v-if="readingResource" class="mb-6 border-b border-default pb-4 xl:hidden">
                    <summary class="flex cursor-pointer items-center gap-2 text-sm font-medium text-muted"><UIcon name="i-lucide-list" class="size-4" />{{ t('groups.resources.reader.contents') }}<UIcon name="i-lucide-chevron-down" class="ml-auto size-4" /></summary>
                    <ResourceTableOfContents :sections="contents" :active-id="activeId" :show-title="false" class="pt-4" />
                </details>
                <template v-if="article">
                    <ResourceReaderArticle :resource="article" :document="outline?.document" :activities="reader.activities" @tag="hub.visit(1, $event)" />
                    <ResourceReaderCommands :commands="article.commands ?? []" />
                    <ResourceReaderHistory :resource="article" :history-url="navigation.history(article)" />
                    <div class="mt-8 border-t border-default pt-5"><Link :href="navigation.home.value" class="inline-flex items-center gap-2 text-sm text-primary hover:underline"><UIcon name="i-lucide-arrow-left" class="size-4" />{{ t('groups.resources.reader.back_to_library') }}</Link></div>
                </template>
                <template v-else>
                    <section v-if="showHome && resource" class="resource-home mb-8 border-b border-default pb-6">
                        <h2 class="mb-4 break-words text-xl font-semibold text-highlighted">{{ resource.title }}</h2>
                        <RichTextReader :document="outline?.document ?? resource.body" :additional-extensions="contentExtensions" />
                        <ResourceReaderCommands :commands="resource.commands ?? []" />
                        <ResourceReaderHistory :resource="resource" :history-url="navigation.history(resource)" />
                    </section>
                    <section v-if="childCollections.length" class="mb-8">
                        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted">{{ t('groups.resources.reader.child_collections') }}</h2>
                        <div class="grid gap-x-6 sm:grid-cols-2"><Link v-for="collection in childCollections" :key="collection.id" :href="navigation.collection(collection.slug)" class="flex items-center gap-2 border-b border-default py-3 text-sm font-medium text-highlighted hover:text-primary"><UIcon :name="collection.icon || 'i-lucide-folder'" class="size-4 shrink-0 text-primary" /><span class="min-w-0 flex-1 break-words">{{ collection.name }}</span><UIcon name="i-lucide-chevron-right" class="size-4 shrink-0" /></Link></div>
                    </section>
                    <section v-for="(section, index) in sections" :key="section.collection?.id ?? 'root'" :class="index ? 'mt-8' : ''">
                        <div v-if="section.collection || (!filtered && !selectedCollection && section.resources.length)" class="mb-1 flex items-center justify-between gap-3">
                            <h2 class="flex min-w-0 items-center gap-2 text-lg font-semibold text-highlighted"><UIcon :name="section.collection?.icon || 'i-lucide-files'" class="size-4 shrink-0 text-primary" /><Link v-if="section.collection" :href="navigation.collection(section.collection.slug)" class="break-words hover:text-primary">{{ section.collection.name }}</Link><span v-else>{{ t('groups.resources.reader.root_resources') }}</span></h2>
                            <Link v-if="section.collection" :href="navigation.collection(section.collection.slug)" class="shrink-0 text-xs text-muted hover:text-primary">{{ t('groups.resources.reader.view_collection') }}</Link>
                        </div>
                        <ResourceReaderRow v-for="item in section.resources" :key="item.id" :resource="item" :href="navigation.resource(item)" />
                    </section>
                    <div v-if="!hasRows && !childCollections.length" class="py-12 text-center">
                        <UIcon :name="filtered ? 'i-lucide-search-x' : 'i-lucide-book-open'" class="mb-3 size-7 text-dimmed" />
                        <h2 class="font-medium text-highlighted">{{ t(`groups.resources.reader.${filtered ? 'no_results' : 'empty_title'}`) }}</h2>
                        <p class="mt-2 text-sm text-muted">{{ t(`groups.resources.reader.${filtered ? 'no_results_description' : 'empty_description'}`) }}</p>
                    </div>
                    <div v-if="resources.last_page > 1" class="mt-6 flex justify-center border-t border-default pt-5"><UPagination :page="resources.current_page" :total="resources.total" :items-per-page="resources.per_page" :sibling-count="1" :disabled="loading" @update:page="hub.visit($event)" /></div>
                </template>
            </main>
            <aside class="hidden min-w-0 border-l border-default pl-6 lg:block xl:pl-8"><ResourceReaderSidebar v-bind="sidebar" /></aside>
        </div>
    </section>
</template>

<style scoped>
.resource-reader-content :deep([id]) { scroll-margin-top: 2rem; }
.resource-reader-content :deep([id][tabindex="-1"]:focus) { outline: none; }
.resource-knowledge-hub :deep(.resource-reader-article .rich-text-content),
.resource-knowledge-hub :deep(.resource-home .rich-text-content) { font-size: 15px; line-height: 1.8; }
.resource-knowledge-hub :deep(.rich-text-content h2) { font-size: 23px; margin-top: 28px; }
.resource-knowledge-hub :deep(.rich-text-content h3) { font-size: 19px; }
.resource-knowledge-hub :deep(.rich-text-content blockquote) { background: color-mix(in srgb, var(--ui-primary) 7%, var(--ui-bg)); padding: 12px 16px; }
.resource-knowledge-hub :deep(.rich-text-content blockquote p:last-child) { margin-bottom: 0; }
.resource-knowledge-hub :deep(.rich-text-content ul[data-type="taskList"] > li) { display: flex; align-items: baseline; gap: 10px; margin: 8px 0; }
.resource-knowledge-hub :deep(.rich-text-content ul[data-type="taskList"] > li > label) { flex-shrink: 0; }
.resource-knowledge-hub :deep(.rich-text-content ul[data-type="taskList"] > li > div) { flex: 1; min-width: 0; }
</style>
