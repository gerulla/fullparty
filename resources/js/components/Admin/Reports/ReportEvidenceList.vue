<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { CaseDetail } from '@/Types/Reports'
import ReportProfileCard from './ReportProfileCard.vue'
import ReportContentPreview from './ReportContentPreview.vue'
defineProps<{ reports: CaseDetail['case']['reports'] }>()
const { t, locale } = useI18n()
const date = (value: string) => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
</script>

<template>
    <section id="report-evidence" class="space-y-4 scroll-mt-5">
        <div><h2 class="text-lg font-semibold">{{ t('reports.admin.reporters_evidence') }} ({{ reports.length }})</h2><p class="text-sm text-muted mt-1">{{ t('reports.admin.snapshot_help') }}</p></div>
        <UCard v-for="report in reports" :id="'report-' + report.id" :key="report.id" class="scroll-mt-5" :ui="{ body: 'space-y-5' }">
            <div class="grid gap-5 md:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                <ReportProfileCard :profile="report.reporter" :guest-reference="report.guest_reference" :label="t('reports.admin.reported_by')" />
                <div class="space-y-3 border-t border-default pt-4 md:border-t-0 md:border-l md:pt-0 md:pl-5">
                    <p class="text-xs text-muted">{{ t('reports.admin.report_number', { id: report.id }) }} · {{ date(report.created_at) }}</p>
                    <p class="font-semibold flex items-center gap-2"><UIcon name="i-lucide-flag" class="text-warning shrink-0" />{{ t('reports.reasons.' + report.reason) }}</p>
                    <p v-if="report.details" class="whitespace-pre-wrap break-words text-sm">{{ report.details }}</p>
                </div>
            </div>
            <img v-if="report.evidence_url" :src="report.evidence_url" :alt="t('reports.admin.evidence')" class="max-h-96 max-w-full object-contain border border-default" />
            <UCollapsible>
                <UButton color="neutral" variant="outline" trailing-icon="i-lucide-chevron-down" icon="i-lucide-camera">{{ t('reports.admin.saved_content') }}</UButton>
                <template #content>
                    <div class="mt-4 border border-default bg-elevated/30 p-4"><ReportContentPreview :preview="report.preview" show-title /></div>
                    <details class="mt-3"><summary class="text-xs text-muted cursor-pointer">{{ t('reports.admin.full_snapshot') }}</summary><pre class="mt-2 text-xs whitespace-pre-wrap break-words max-h-96 overflow-auto bg-elevated p-3">{{ JSON.stringify(report.snapshot, null, 2) }}</pre></details>
                </template>
            </UCollapsible>
        </UCard>
    </section>
</template>
