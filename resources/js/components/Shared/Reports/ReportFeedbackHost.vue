<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useReportFeedback } from '@/composables/useReportFeedback'
const { t } = useI18n()
const { next, count, busy, failed, acknowledge } = useReportFeedback()
</script>

<template>
        <UModal :open="Boolean(next)" :title="t(next?.audience === 'owner' ? 'reports.owner_notice_title' : 'reports.feedback_title')"
            :description="t(next?.audience === 'owner' ? 'reports.owner_notice_description' : 'reports.feedback_description')"
            :dismissible="false" :close="false">
            <template #body>
                <div v-if="next" class="space-y-4">
                    <p class="font-semibold break-words">{{ next.item_title }}</p>
                    <p class="whitespace-pre-wrap break-words">{{ t((next.audience === 'owner' ? 'reports.owner_notice.' : 'reports.feedback.') + next.template, { item: next.item_title, message: next.message ?? '' }) }}</p>
                    <p v-if="count > 1" class="text-sm text-muted">{{ t('reports.feedback_remaining', { count }) }}</p>
                    <UAlert v-if="failed" color="error" :description="t('reports.errors.acknowledge')" />
                </div>
            </template>
            <template #footer>
                <UButton class="ml-auto" :loading="busy" @click="acknowledge">{{ t('reports.close') }}</UButton>
            </template>
        </UModal>
</template>
