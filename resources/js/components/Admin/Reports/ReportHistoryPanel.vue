<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { CaseDetail } from '@/Types/Reports'
defineProps<{ reviewCase: CaseDetail['case'] }>()
const { t, locale } = useI18n()
const date = (value: string) => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
</script>

<template>
    <UCard v-if="reviewCase.actions.length || reviewCase.feedback.length" :ui="{ body: 'space-y-6' }">
        <section v-if="reviewCase.actions.length" class="space-y-4">
            <h2 class="font-semibold">{{ t('reports.admin.history') }}</h2>
            <div v-for="entry in reviewCase.actions" :key="entry.id" class="border-l-2 border-primary/40 pl-4 text-sm space-y-1">
                <p class="font-medium">{{ t('reports.actions.' + entry.action) }} · {{ entry.admin ?? t('reports.admin.deleted_user') }}</p><p class="text-xs text-muted">{{ date(entry.created_at) }}</p>
                <p v-if="entry.reason" class="whitespace-pre-wrap break-words">{{ entry.reason }}</p>
            </div>
        </section>
        <section v-if="reviewCase.feedback.length" class="space-y-3">
            <h2 class="font-semibold">{{ t('reports.admin.feedback_sent') }}</h2>
            <div v-for="entry in reviewCase.feedback" :key="entry.id" class="border border-default p-3 space-y-2 text-sm">
                <UBadge color="neutral" variant="subtle">{{ t('reports.admin.audience_' + entry.audience) }}</UBadge>
                <p class="whitespace-pre-wrap break-words">{{ t((entry.audience === 'owner' ? 'reports.owner_notice.' : 'reports.feedback.') + entry.template, { item: entry.item_title, message: entry.message ?? '' }) }}</p>
                <p class="text-xs text-muted">{{ entry.recipient_count ? t('reports.admin.acknowledgements', { count: entry.acknowledged_count, total: entry.recipient_count }) : t('reports.admin.no_recipients') }} · {{ date(entry.created_at) }}</p>
            </div>
        </section>
    </UCard>
</template>
