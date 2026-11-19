<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ReportStatus } from '@/Types/Reports'

defineProps<{ status: ReportStatus }>()
const { t } = useI18n()
const colors = {
    new: 'info',
    in_review: 'primary',
    awaiting_feedback: 'warning',
    resolved: 'success',
} as const satisfies Record<ReportStatus, string>
</script>

<template>
    <UBadge :color="colors[status]" variant="soft">{{ t('reports.statuses.' + status) }}</UBadge>
</template>
