<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { localizedValue } from '@/utils/localizedValue'
import type { CharacterTransferSummary, TransferCategory, TransferChoice } from '../../Types/CharacterTransfer'
import { useDateTimeFormat } from '@/composables/useDateTimeFormat'

const props = defineProps<{
    summary: CharacterTransferSummary
    history: TransferChoice | null
    upcoming: TransferChoice | null
    busy: boolean
    error: string | null
    automaticTransfer?: boolean
}>()
const emit = defineEmits<{
    'update:history': [value: TransferChoice]
    'update:upcoming': [value: TransferChoice]
    complete: []
}>()
const { t, locale } = useI18n()
const { formatDateTime } = useDateTimeFormat()
const sections = computed(() => (['history', 'upcoming'] as TransferCategory[]).map(category => ({
    category, choice: props[category], runs: props.summary.runs.filter(run => run.category === category),
})))
const date = (value: string | null) => value
    ? formatDateTime(new Date(value), { dateStyle: 'medium', timeStyle: 'short' })
    : t('characters.transfer.no_date')
</script>

<template>
    <div class="space-y-6 p-5 sm:p-7">
        <header class="text-center">
            <UIcon name="i-lucide-arrow-right-left" class="mx-auto mb-3 block size-10 text-brand-700 dark:text-brand-300" aria-hidden="true" />
            <h1 tabindex="-1" class="text-2xl font-semibold text-highlighted outline-none">{{ t('characters.transfer.completion_title') }}</h1>
            <p class="mx-auto mt-2 max-w-lg text-sm leading-relaxed text-muted">{{ t('characters.transfer.completion_description') }}</p>
        </header>
        <UAlert v-if="automaticTransfer || summary.method === 'xivauth'" color="brand" variant="soft" icon="i-lucide-shield-check"
            :title="t('characters.transfer.automatic_title')"
            :description="t('characters.transfer.automatic_description')" class="rounded-lg" />
        <div class="space-y-4">
            <section v-for="section in sections" :key="section.category" class="rounded-xl border border-default bg-elevated p-4 sm:p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-semibold text-highlighted">{{ t(`characters.transfer.${section.category}`) }}</h2>
                    <UBadge color="neutral" variant="soft" class="rounded-md">{{ section.runs.length }}</UBadge>
                </div>
                <p class="mt-2 text-sm leading-relaxed text-muted">{{ t(`characters.transfer.${section.category}_help`) }}</p>
                <details v-if="section.runs.length" class="mt-4">
                    <summary class="w-fit cursor-pointer rounded-md text-sm text-brand-700 focus-visible:outline-2 dark:text-brand-300">{{ t('characters.transfer.view_runs', { count: section.runs.length }) }}</summary>
                    <ul class="mt-3 max-h-64 space-y-2 overflow-y-auto overscroll-contain pr-1">
                        <li v-for="run in section.runs" :key="run.id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-default p-3">
                            <div class="min-w-0 flex-1 text-sm">
                                <p class="break-words font-medium text-highlighted">{{ run.title || localizedValue(run.activity_type, locale) }}</p>
                                <p class="mt-1 text-muted">{{ localizedValue(run.activity_type, locale) }} · {{ run.group }}</p>
                                <time v-if="run.starts_at" :datetime="run.starts_at" class="text-xs text-muted">{{ date(run.starts_at) }}</time>
                                <p v-else class="text-xs text-muted">{{ date(null) }}</p>
                            </div>
                            <UButton v-if="run.url" :href="run.url" target="_blank" rel="noopener noreferrer" color="neutral" variant="ghost" trailing-icon="i-lucide-external-link" class="shrink-0 rounded-md">
                                {{ t('characters.transfer.view_more') }}
                            </UButton>
                        </li>
                    </ul>
                </details>
                <p v-else class="mt-3 text-sm text-muted">{{ t('characters.transfer.empty') }}</p>
                <div class="mt-4 grid gap-2 sm:grid-cols-2" role="group" :aria-label="t(`characters.transfer.${section.category}`)">
                    <UButton v-for="choice in (['keep', 'discard'] as const)" :key="choice"
                        :color="choice === 'keep' ? 'brand' : 'neutral'" :variant="section.choice === choice ? 'solid' : 'outline'"
                        :icon="choice === 'keep' ? 'i-lucide-check' : 'i-lucide-x'" :aria-pressed="section.choice === choice" :disabled="busy"
                        class="justify-center rounded-md" @click="section.category === 'history' ? emit('update:history', choice) : emit('update:upcoming', choice)">
                        {{ t(`characters.transfer.${choice}_${section.category}`) }}
                    </UButton>
                </div>
            </section>
        </div>
        <p class="text-sm leading-relaxed text-muted">{{ t('characters.transfer.privacy') }}</p>
        <UAlert v-if="error" color="error" variant="soft" :description="error" icon="i-lucide-circle-alert" class="rounded-lg" role="alert" />
        <div class="flex justify-end">
            <UButton color="brand" trailing-icon="i-lucide-arrow-right" class="rounded-md" :loading="busy" :disabled="!history || !upcoming || busy" @click="emit('complete')">
                {{ t('characters.transfer.finish') }}
            </UButton>
        </div>
    </div>
</template>
