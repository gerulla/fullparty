<script setup lang="ts">
import type { AuditLogFilterOptions, AuditLogFilters, AuditLogRowRecord } from '@/Types/Audit'
import AdminPageHeader from '@/components/Admin/AdminPageHeader.vue'
import AuditLogPanel from '@/components/Audit/AuditLogPanel.vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
    auditLogs: AuditLogRowRecord[]
    nextCursor: string | null
    selectedFilters?: Partial<AuditLogFilters>
    filters: AuditLogFilterOptions
}>()
const { t } = useI18n()
</script>

<template>
    <div class="min-w-0 space-y-6">
        <AdminPageHeader :title="t('audit_log.admin.title')" :subtitle="t('audit_log.admin.subtitle')">
            <UBadge variant="subtle" color="primary" icon="i-lucide-shield-check" :label="t('audit_log.admin.access')" />
        </AdminPageHeader>
        <div class="admin-panel p-4 sm:p-5">
            <AuditLogPanel v-bind="props" />
        </div>
    </div>
</template>
