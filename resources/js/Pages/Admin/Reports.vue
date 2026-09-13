<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import PageHeader from '@/components/PageHeader.vue'
import type { ReportCasePage, ReportStatus, ReportTargetType } from '@/Types/Reports'

const props = defineProps<{ cases: ReportCasePage; filters: { status?: ReportStatus; type?: ReportTargetType; q?: string }; counts: Record<string, number>; targetTypes: ReportTargetType[] }>()
const { t } = useI18n()
const status = ref(props.filters.status ?? 'all')
const type = ref(props.filters.type ?? 'all')
const q = ref(props.filters.q ?? '')
const statuses = computed(() => [{ value: 'all', label: t('reports.admin.all') }, ...['new', 'in_review', 'awaiting_feedback', 'resolved'].map(value => ({ value, label: t('reports.statuses.' + value) + ' (' + (props.counts[value] ?? 0) + ')' }))])
const types = computed(() => [{ value: 'all', label: t('reports.admin.all_types') }, ...props.targetTypes.map(value => ({ value, label: t('reports.types.' + value) }))])
function filter(pageNumber = 1) {
    router.get(route('admin.reports.index'), { status: status.value === 'all' ? undefined : status.value, type: type.value === 'all' ? undefined : type.value, q: q.value || undefined, page: pageNumber }, { preserveState: true, preserveScroll: true })
}
</script>

<template>
    <Head :title="t('reports.admin.title')" />
    <div class="space-y-5 p-4 md:p-6">
        <PageHeader :title="t('reports.admin.title')" :subtitle="t('reports.admin.description')" />
        <form class="flex flex-wrap gap-3 items-end" @submit.prevent="filter()">
            <UFormField :label="t('reports.admin.status')"><USelect v-model="status" :items="statuses" class="min-w-52" /></UFormField>
            <UFormField :label="t('reports.admin.type')"><USelect v-model="type" :items="types" class="min-w-40" /></UFormField>
            <UFormField :label="t('reports.admin.search')"><UInput v-model="q" :maxlength="100" icon="i-lucide-search" /></UFormField>
            <UButton type="submit">{{ t('reports.admin.filter') }}</UButton>
        </form>
        <div class="border border-default divide-y divide-default">
            <p v-if="!cases.data.length" class="p-8 text-muted text-center">{{ t('reports.admin.empty') }}</p>
            <Link v-for="entry in cases.data" :key="entry.id" class="w-full text-left p-4 flex flex-wrap justify-between gap-3 hover:bg-elevated focus-visible:outline-2 focus-visible:outline-primary" :href="route('admin.reports.show', entry.id)">
                <div class="min-w-0"><p class="font-semibold break-words">#{{ entry.id }} · {{ entry.title }}</p><p class="text-xs text-muted mt-1">{{ t('reports.types.' + entry.target_type) }} · {{ t('reports.admin.report_count', { count: entry.reports_count }) }} · {{ entry.assignee?.name ?? t('reports.admin.unassigned') }}</p></div>
                <UBadge color="neutral" variant="subtle" class="self-center">{{ t('reports.statuses.' + entry.status) }}</UBadge>
            </Link>
        </div>
        <UPagination :page="cases.meta.current_page" :total="cases.meta.total" :items-per-page="cases.meta.per_page" @update:page="filter" />
    </div>
</template>
