<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import RichTextReader from '@/components/Shared/RichText/RichTextReader.vue'
import type { ChangelogArticle, ChangelogSummary } from '@/Types/Changelog'

defineProps<{ open: boolean; loading: boolean; error: string; article: ChangelogArticle | null; history: ChangelogSummary[]; historyOpen: boolean; historyLoading: boolean; hasMore: boolean }>()
const emit = defineEmits<{ close: []; load: [id?: number]; older: [] }>()
const { t, locale } = useI18n()
const date = (value: string) => new Intl.DateTimeFormat(locale.value, { dateStyle: 'long' }).format(new Date(value))
const range = (entry: ChangelogSummary) => entry.version_from ? t('changelog.range', { from: entry.version_from, to: entry.version_to }) : t('changelog.version', { version: entry.version_to })
</script>

<template>
    <UModal :open="open" :title="t('changelog.title')" :ui="{ content: 'sm:max-w-4xl', body: 'max-h-[75dvh] overflow-y-auto' }" @update:open="!$event && emit('close')">
        <template #body>
            <div class="space-y-6">
                <UAlert v-if="error" :title="error" color="error" variant="soft" />
                <div v-if="loading" class="flex justify-center p-12" role="status"><UIcon name="i-lucide-loader-circle" class="size-6 animate-spin" /><span class="sr-only">{{ t('changelog.loading') }}</span></div>
                <article v-else-if="article" class="space-y-5">
                    <header class="space-y-2 border-b border-default pb-5">
                        <p class="text-xs font-medium uppercase tracking-wide text-primary">{{ range(article) }}</p>
                        <h2 class="text-2xl font-semibold">{{ article.title }}</h2>
                        <time v-if="article.published_at" :datetime="article.published_at" class="text-sm text-muted">{{ date(article.published_at) }}</time>
                    </header>
                    <RichTextReader :document="article.body" />
                </article>
                <p v-else-if="!error" class="py-8 text-center text-muted">{{ t('changelog.empty') }}</p>
                <UButton v-if="error && !loading" color="neutral" variant="outline" :label="t('changelog.retry')" @click="emit('load')" />
                <section v-if="historyOpen" class="border-t border-default pt-5">
                    <h3 class="mb-3 font-semibold">{{ t('changelog.older') }}</h3>
                    <div class="divide-y divide-default">
                        <button v-for="entry in history" :key="entry.id" type="button" class="flex w-full items-center justify-between gap-3 px-2 py-3 text-left hover:bg-elevated focus-visible:outline-2 focus-visible:outline-primary" :aria-current="entry.id === article?.id ? 'true' : undefined" @click="emit('load', entry.id)">
                            <span>{{ entry.title }}<span class="block text-xs text-muted">{{ range(entry) }}</span></span>
                            <UIcon name="i-lucide-chevron-right" class="size-4 shrink-0" />
                        </button>
                    </div>
                    <UButton v-if="hasMore || historyLoading" class="mt-3" :loading="historyLoading" color="neutral" variant="outline" :label="t('changelog.load_more')" @click="emit('older')" />
                </section>
            </div>
        </template>
        <template #footer>
            <div class="flex w-full justify-between gap-3">
                <UButton color="neutral" variant="ghost" :label="t('changelog.older')" :loading="historyLoading" @click="emit('older')" />
                <UButton color="neutral" variant="outline" :label="t('changelog.close')" @click="emit('close')" />
            </div>
        </template>
    </UModal>
</template>
