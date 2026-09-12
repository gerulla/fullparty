<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ResourceReaderDocument } from '@/Types/GroupResources'
import { useResourceReaderHistory } from '@/composables/useResourceReaderHistory'
import { readerDateTime } from '@/utils/resourceReader'

const props = defineProps<{ resource: ResourceReaderDocument; historyUrl: string }>()
const { t, locale } = useI18n()
const { entries, hasMore, loading, failed, loadMore } = useResourceReaderHistory(() => props.resource, () => props.historyUrl)
</script>

<template>
    <section id="resource-history" tabindex="-1" class="mt-8 border-t border-default pt-6" :aria-label="t('groups.resources.reader.edit_history')" :aria-busy="loading">
        <h2 class="mb-5 flex items-center gap-2 text-base font-semibold text-highlighted"><UIcon name="i-lucide-history" class="size-4 text-muted" />{{ t('groups.resources.reader.edit_history') }}</h2>
        <ol v-if="entries.length" class="space-y-5">
            <li v-for="entry in entries" :key="entry.id" class="flex items-start gap-3">
                <UAvatar :src="entry.editor.avatar_url || undefined" :alt="entry.editor.name || t('groups.resources.reader.contributor')" size="xs" class="mt-0.5 shrink-0" />
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1"><span class="text-sm font-medium text-highlighted">{{ entry.editor.name || t('groups.resources.reader.contributor') }}</span><time v-if="entry.created_at" :datetime="entry.created_at" class="text-xs text-muted">{{ readerDateTime(entry.created_at, locale) }}</time></div>
                    <p v-if="entry.summary" class="mt-1 whitespace-pre-line break-words text-sm leading-relaxed text-muted">{{ entry.summary }}</p>
                </div>
            </li>
        </ol>
        <p v-else class="text-sm text-muted">{{ t('groups.resources.reader.no_history') }}</p>
        <p v-if="failed" role="alert" class="mt-4 text-sm text-error">{{ t('groups.resources.reader.history_failed') }}</p>
        <UButton v-if="hasMore" class="mt-5" color="neutral" variant="outline" size="sm" :label="t('groups.resources.reader.view_more')" :loading="loading" @click="loadMore" />
    </section>
</template>
