<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { AuditLogFilters, AuditLogFilterOptions, AuditLogRowRecord } from '@/Types/Audit'
import type { GroupRole } from '@/Types/Groups'
import AccessBadge from '@/components/Groups/AccessBadge.vue'
import PageHeader from '@/components/PageHeader.vue'
import GroupAuditLogPanel from '@/components/Groups/Settings/GroupAuditLogPanel.vue'

defineProps<{
    group: { slug: string; current_user_role: GroupRole | null }
    auditLogs: AuditLogRowRecord[]
    nextCursor: string | null
    selectedFilters?: Partial<AuditLogFilters>
    filters: AuditLogFilterOptions
}>()
const { t } = useI18n()
</script>

<template>
    <div class="w-full">
        <PageHeader :title="t('audit_log.group.title')" :subtitle="t('audit_log.group.subtitle')">
            <AccessBadge :role="group.current_user_role" fallback-role="moderator" />
        </PageHeader>
        <GroupAuditLogPanel class="mt-4" :group="group" :audit-logs="auditLogs" :next-cursor="nextCursor"
            :selected-filters="selectedFilters" :filters="filters" />
    </div>
</template>
