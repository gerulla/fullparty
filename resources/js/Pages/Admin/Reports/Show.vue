<script setup lang="ts">
import { computed, toRef } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import ReportContentPreview from '@/components/Admin/Reports/ReportContentPreview.vue'
import ReportDecisionPanel from '@/components/Admin/Reports/ReportDecisionPanel.vue'
import ReportEvidenceList from '@/components/Admin/Reports/ReportEvidenceList.vue'
import ReportGroupCard from '@/components/Admin/Reports/ReportGroupCard.vue'
import ReportHistoryPanel from '@/components/Admin/Reports/ReportHistoryPanel.vue'
import ReportProfileCard from '@/components/Admin/Reports/ReportProfileCard.vue'
import { useModerationCase } from '@/composables/useModerationCase'
import { useConfirmationModal } from '@/composables/useConfirmationModal'
import { localizedValue } from '@/utils/localizedValue'
import type { CaseDetail, ModerationAction } from '@/Types/Reports'

const props = defineProps<{ detail: CaseDetail }>()
const { t, locale } = useI18n()
const page = usePage()
const adminId = computed(() => (page.props.auth as { user: { id: number } }).user.id)
const review = useModerationCase(toRef(props, 'detail'))
const { detail, busy, error } = review
const confirmation = useConfirmationModal()
const context = computed(() => detail.value.context)
const reporterName = (report: CaseDetail['case']['reports'][number]) => report.reporter?.name ?? (report.guest_reference ? t('reports.admin.guest_reporter') + ' · ' + report.guest_reference : t('reports.admin.deleted_user'))
const date = (value: string) => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
async function apply(action: ModerationAction, reason: string) {
    if (action !== 'claim' || detail.value.case.assigned_to) {
        if (!await confirmation.open({
            title: t('reports.actions.' + action), description: t('reports.admin.confirm_action'),
            warningText: ['ban', 'unban'].includes(action) ? t('reports.admin.confirm_account', { name: detail.value.subject_user?.name ?? '' }) : detail.value.case.title,
            confirmLabel: t('reports.admin.apply'), severity: 'warning',
        })) return
    }
    await review.action(action, reason)
}
</script>

<template>
    <Head :title="t('reports.admin.review_title', { id: detail.case.id }) + ' · ' + detail.case.title" />
    <div class="space-y-6 p-4 md:p-6 max-w-[1600px] mx-auto">
        <header class="space-y-4">
            <Link :href="route('admin.reports.index')" class="inline-flex items-center gap-2 text-sm text-muted hover:text-highlighted"><UIcon name="i-lucide-arrow-left" />{{ t('reports.admin.back') }}</Link>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="flex flex-wrap gap-2 items-center"><span class="text-xs font-semibold uppercase tracking-wide text-muted">{{ t('reports.admin.review_title', { id: detail.case.id }) }}</span><UBadge variant="subtle">{{ t('reports.statuses.' + detail.case.status) }}</UBadge></div>
                    <h1 class="text-2xl md:text-3xl font-bold break-words">{{ detail.case.title }}</h1>
                    <p class="text-sm text-muted">{{ t('reports.types.' + detail.case.target_type) }} #{{ detail.case.target_id }} · {{ t('reports.admin.report_count', { count: detail.case.reports_count }) }} · {{ date(detail.case.created_at) }}</p>
                </div>
                <UButton icon="i-lucide-refresh-cw" color="neutral" variant="outline" :loading="busy" @click="review.reload">{{ t('reports.admin.reload') }}</UButton>
            </div>
            <p class="text-xs text-muted flex gap-2 items-center"><UIcon name="i-lucide-lock-keyhole" class="shrink-0" />{{ t('reports.admin.review_description') }}</p>
            <div class="flex flex-wrap gap-3">
                <a v-for="report in detail.case.reports.slice(0, 3)" :key="report.id" :href="'#report-' + report.id" class="flex items-center gap-3 border border-default px-3 py-2 hover:bg-elevated min-w-0">
                    <UAvatar :src="report.reporter?.avatar_url ?? undefined" :alt="reporterName(report)" size="sm" />
                    <div class="min-w-0"><p class="text-xs text-muted">{{ t('reports.admin.reported_by') }} <strong class="text-highlighted">{{ reporterName(report) }}</strong></p><p class="text-sm font-medium">{{ t('reports.reasons.' + report.reason) }}</p></div>
                </a>
                <a v-if="detail.case.reports.length > 3" href="#report-evidence" class="self-center text-sm text-primary underline">{{ t('reports.admin.reporters_evidence') }} ({{ detail.case.reports.length }})</a>
            </div>
        </header>
        <UAlert v-if="error" color="error" :description="t(error)" />
        <UAlert v-if="!detail.target_exists" icon="i-lucide-info" :description="t('reports.admin.target_missing')" />
        <UAlert v-if="detail.case.status === 'resolved'" icon="i-lucide-history" :description="t('reports.admin.reversible')" />
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <main class="min-w-0 space-y-6">
                <section class="space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-semibold">{{ t('reports.admin.reported_content') }}</h2><UButton v-if="context.url" :href="context.url" target="_blank" color="neutral" variant="outline" size="sm" trailing-icon="i-lucide-external-link">{{ t('reports.admin.open_original') }}</UButton></div>
                    <UAlert v-if="detail.is_hidden" color="warning" icon="i-lucide-eye-off" :description="t('reports.admin.hidden_preview')" />
                    <ReportGroupCard v-if="detail.case.target_type === 'group' && context.group" :group="context.group" />
                    <UCard v-else-if="context.profile"><ReportProfileCard :profile="context.profile" :label="t('reports.admin.reported_profile')" /></UCard>
                    <UCard v-else-if="context.preview" :ui="{ body: 'space-y-5' }">
                        <div class="flex flex-wrap gap-2 text-xs text-muted"><UBadge color="neutral" variant="subtle">{{ t('reports.admin.current_content') }}</UBadge><span v-if="context.collection" class="inline-flex items-center gap-1"><UIcon name="i-lucide-folder" />{{ context.collection }}</span><span v-if="context.updated_at">{{ t('reports.admin.updated', { date: date(context.updated_at) }) }}</span></div>
                        <p v-if="detail.case.target_type === 'run' && context.run?.starts_at" class="text-sm text-muted">{{ date(context.run.starts_at) }}</p>
                        <img v-if="context.cover_url" :src="context.cover_url" alt="" class="w-full max-h-80 object-contain bg-elevated" />
                        <ReportContentPreview :preview="context.preview" show-title />
                        <div v-if="context.items.length" class="grid sm:grid-cols-2 gap-2"><div v-for="(item, index) in context.items" :key="index" class="flex items-center gap-2 border border-default p-2 text-sm"><img v-if="item.icon_url" :src="item.icon_url" alt="" class="size-8" /><span>{{ item.quantity }} × {{ localizedValue(item.name, locale) }}</span></div></div>
                        <div v-if="detail.case.target_type === 'upload'" class="space-y-3"><figure v-for="image in context.images" :key="image.id"><img :src="image.url" :alt="image.alt_text || image.original_name" class="max-h-[32rem] max-w-full object-contain" /><figcaption class="mt-2 text-xs text-muted">{{ image.original_name }} · {{ image.width }} × {{ image.height }}</figcaption></figure></div>
                        <details><summary class="text-xs text-muted cursor-pointer">{{ t('reports.admin.current_details') }}</summary><pre class="mt-2 text-xs whitespace-pre-wrap break-words max-h-96 overflow-auto bg-elevated p-3">{{ JSON.stringify(detail.current_content, null, 2) }}</pre></details>
                    </UCard>
                </section>
                <ReportEvidenceList :reports="detail.case.reports" />
                <ReportHistoryPanel :review-case="detail.case" />
            </main>
            <aside class="min-w-0 space-y-5">
                <UCard :ui="{ body: 'space-y-4' }"><ReportDecisionPanel :detail="detail" :busy="busy" :admin-id="adminId" @action="apply" @feedback="review.feedback" /><UAlert v-if="detail.target_exists && !detail.can_hide" icon="i-lucide-info" :description="t('reports.admin.context_target')" /></UCard>
                <UCard v-if="!context.profile" :ui="{ body: 'space-y-4' }"><ReportProfileCard v-if="detail.subject_user" :profile="detail.subject_user" :label="t('reports.admin.responsible_account')" /><p v-else class="text-sm text-muted">{{ t('reports.admin.no_responsible_account') }}</p><p class="text-xs text-muted">{{ t('reports.admin.responsible_help') }}</p></UCard>
                <UCard v-if="context.owner && context.owner.id !== detail.subject_user?.id"><ReportProfileCard :profile="context.owner" :label="t('reports.admin.resource_owner')" /></UCard>
                <UCard v-if="context.member"><ReportProfileCard :profile="context.member" :label="t('reports.admin.note_about')" /></UCard>
                <ReportGroupCard v-if="context.group && detail.case.target_type !== 'group'" :group="context.group" />
                <UCard v-if="context.run && detail.case.target_type !== 'run'" :ui="{ body: 'space-y-3' }"><h2 class="font-semibold">{{ t('reports.admin.associated_run') }}</h2><p class="font-medium break-words">{{ context.run.title }}</p><p v-if="context.run.starts_at" class="text-xs text-muted">{{ date(context.run.starts_at) }}</p><p v-if="context.run.description" class="text-sm whitespace-pre-wrap break-words">{{ context.run.description }}</p><UButton v-if="context.run.url" :href="context.run.url" target="_blank" color="neutral" variant="outline" size="sm" trailing-icon="i-lucide-external-link">{{ t('reports.admin.open_original') }}</UButton></UCard>
            </aside>
        </div>
    </div>
</template>
