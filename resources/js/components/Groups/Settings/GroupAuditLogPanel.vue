<script setup lang="ts">
import type { AuditLogFilters, AuditLogFilterOptions, AuditLogRowRecord } from '@/Types/Audit'
import GroupAuditLogFilters from '@/components/Audit/GroupAuditLogFilters.vue'
import GroupAuditLogEntry from '@/components/Audit/GroupAuditLogEntry.vue'
import AuditEventDetails from '@/components/Audit/AuditEventDetails.vue'
import { useAuditLogFeed } from '@/composables/useAuditLogFeed'
import { useGroupAuditEvent } from '@/composables/useGroupAuditEvent'
import { useTimeDisplayMode } from '@/composables/useTimeDisplayMode'
import { useElementSize, useMediaQuery } from '@vueuse/core'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const props = defineProps<{
    group: { slug: string }
    auditLogs?: AuditLogRowRecord[]
    nextCursor?: string | null
    selectedFilters?: Partial<AuditLogFilters>
    filters?: AuditLogFilterOptions
}>()
const { t, locale } = useI18n()
const { displayTimeZone, withDisplayTimeZone } = useTimeDisplayMode()
const timezone = computed(() => displayTimeZone.value ?? Intl.DateTimeFormat().resolvedOptions().timeZone)
const hasInitialDateFilter = () => Boolean(props.selectedFilters?.afterDate || props.selectedFilters?.beforeDate)
const initialTimezone = props.auditLogs !== undefined && hasInitialDateFilter()
    ? (props.selectedFilters?.timezone || 'UTC')
    : timezone.value
const { filters, filterOptions, rows, hasMore, loading, failed, loadMore, retry } = useAuditLogFeed(
    () => route('groups.dashboard.audit-log.data', { group: props.group.slug, locale: locale.value }),
    () => props.auditLogs === undefined ? undefined : {
        auditLogs: props.auditLogs,
        nextCursor: props.nextCursor ?? null,
        selectedFilters: props.selectedFilters,
        filters: props.filters,
    },
    { timezone: initialTimezone },
)
watch([timezone, () => props.auditLogs], ([value, logs], previous) => {
    // Preloaded date-filtered rows use the server's zone; never reuse their cursor
    // after switching to the viewer's zone. Run after the feed accepts new props.
    const incomingDatedPayload = logs !== undefined && logs !== previous?.[1] && hasInitialDateFilter()
    const serverTimezone = props.selectedFilters?.timezone || 'UTC'
    if (filters.value.timezone !== value || (incomingDatedPayload && serverTimezone !== value)) {
        filters.value = { ...filters.value, timezone: value }
    }
}, { immediate: true, flush: 'post' })
const { selectedId, selectedRow, event, loading: detailLoading, failed: detailFailed, copied, select, close, reload, copyLink } =
    useGroupAuditEvent(() => props.group.slug, () => rows.value)
const panel = ref<HTMLElement | null>(null)
const { width } = useElementSize(panel)
const desktop = useMediaQuery('(min-width: 1280px)')
const dockDetails = computed(() => desktop.value && width.value >= 960)
const modalOpen = computed({ get: () => selectedId.value !== null && !dockDetails.value, set: open => { if (!open) close() } })

const days = computed(() => {
    const keyFormatter = new Intl.DateTimeFormat('en-CA', withDisplayTimeZone({ year: 'numeric', month: '2-digit', day: '2-digit' }))
    const labelFormatter = new Intl.DateTimeFormat(locale.value, withDisplayTimeZone({ day: 'numeric', month: 'short', year: 'numeric' }))
    const today = keyFormatter.format(new Date())
    const todayParts = keyFormatter.formatToParts(new Date())
    const part = (key: string) => Number(todayParts.find(part => part.type === key)?.value)
    // Compare calendar dates, so yesterday remains correct across DST transitions.
    const priorDay = new Date(Date.UTC(part('year'), part('month') - 1, part('day') - 1))
    const yesterday = new Intl.DateTimeFormat('en-CA', { timeZone: 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' }).format(priorDay)
    const groups = new Map<string, { key: string; label: string; relative: string | null; rows: AuditLogRowRecord[] }>()
    for (const row of rows.value) {
        const date = new Date(row.created_at)
        const key = keyFormatter.format(date)
        if (!groups.has(key)) groups.set(key, {
            key, label: labelFormatter.format(date),
            relative: key === today ? t('audit_log.list.today') : key === yesterday ? t('audit_log.list.yesterday') : null,
            rows: [],
        })
        groups.get(key)!.rows.push(row)
    }
    return [...groups.values()]
})
</script>

<template>
    <div ref="panel" class="@container w-full min-w-0 space-y-5">
        <div v-if="!filterOptions && loading" :aria-busy="true" :aria-label="t('audit_log.list.loading')" class="space-y-4">
            <div class="grid gap-3 @xl:grid-cols-3"><USkeleton v-for="index in 3" :key="index" class="h-10 w-full rounded-lg" /></div>
            <USkeleton class="h-9 w-2/3 rounded-lg" />
        </div>
        <GroupAuditLogFilters v-if="filterOptions" v-model="filters" :options="filterOptions" />

        <div class="grid min-w-0 items-start gap-5" :class="selectedId !== null && dockDetails ? 'grid-cols-[minmax(0,1fr)_24rem]' : 'grid-cols-1'">
            <div class="min-w-0 space-y-4" :aria-busy="loading">
                <section v-for="day in days" :key="day.key" class="min-w-0 overflow-hidden rounded-lg border border-default bg-elevated/10">
                    <h3 class="flex flex-wrap items-baseline gap-x-2 gap-y-1 border-b border-default bg-elevated/60 px-4 py-2.5 text-sm">
                        <span v-if="day.relative" class="font-semibold text-highlighted">{{ day.relative }} <span class="font-normal text-muted">·</span></span>
                        <span :class="day.relative ? 'text-toned' : 'font-semibold text-highlighted'">{{ day.label }}</span>
                        <span class="text-xs text-muted">{{ t('audit_log.list.day_count', { count: day.rows.length }) }}</span>
                    </h3>
                    <div class="divide-y divide-default">
                        <GroupAuditLogEntry v-for="row in day.rows" :key="row.id" :row="row" :selected="selectedId === row.id" @select="select($event.id)" />
                    </div>
                </section>
                <div v-if="rows.length === 0 && !loading && !failed" class="rounded-lg border border-default bg-elevated/10 px-4 py-12 text-center">
                    <UIcon name="i-lucide-search" class="mb-3 size-7 text-dimmed" aria-hidden="true" />
                    <p class="text-sm text-muted">{{ t('audit_log.list.empty') }}</p>
                </div>
                <div v-if="loading" role="status" :aria-label="t('audit_log.list.loading')" class="overflow-hidden rounded-lg border border-default">
                    <USkeleton v-if="!rows.length" class="h-10 w-full" />
                    <div v-for="index in (rows.length ? 2 : 5)" :key="index" class="flex items-center gap-3 border-t border-default p-4">
                        <USkeleton class="size-5 shrink-0" />
                        <USkeleton class="h-4 flex-1" />
                        <USkeleton class="h-5 w-16 rounded-full" />
                    </div>
                </div>
                <div v-if="failed" role="alert" class="flex flex-wrap items-center justify-center gap-3 rounded-lg border border-default p-5 text-sm text-error">
                    {{ t('audit_log.list.load_error') }}
                    <UButton icon="i-lucide-refresh-cw" color="neutral" variant="ghost" :label="t('audit_log.list.retry')" @click="retry" />
                </div>
                <div v-if="hasMore && !loading && !failed" class="flex justify-center pt-2">
                    <UButton color="brand" variant="outline" class="rounded-lg" icon="i-lucide-chevron-down" :label="t('audit_log.list.loading_more')" @click="loadMore" />
                </div>
                <p v-if="rows.length && !hasMore && !loading && !failed" class="py-2 text-center text-xs text-muted">{{ t('audit_log.list.end') }}</p>
            </div>

            <aside v-if="selectedId !== null && dockDetails" class="sticky top-[calc(var(--ui-header-height)+1rem)] min-w-0 max-h-[calc(100dvh-var(--ui-header-height)-2rem)] overflow-y-auto rounded-lg border border-default bg-elevated/25"
                :aria-label="t('audit_log.details.title')">
                <AuditEventDetails :row="selectedRow" :event="event" :loading="detailLoading" :failed="detailFailed" :copied="copied"
                    @close="close" @retry="reload" @copy-link="copyLink" />
            </aside>
        </div>

        <UModal v-if="!dockDetails" v-model:open="modalOpen" :title="t('audit_log.details.title')"
            :ui="{ content: 'w-[calc(100vw-2rem)] max-w-2xl rounded-xl overflow-y-auto' }">
            <template #content>
                <AuditEventDetails :row="selectedRow" :event="event" :loading="detailLoading" :failed="detailFailed" :copied="copied"
                    @close="close" @retry="reload" @copy-link="copyLink" />
            </template>
        </UModal>
    </div>
</template>
