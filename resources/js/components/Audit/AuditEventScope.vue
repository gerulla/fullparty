<script setup lang="ts">
import type { AuditLogRowRecord } from '@/Types/Audit'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ scope: NonNullable<AuditLogRowRecord['scope']>; showId?: boolean }>()
const { t, te } = useI18n()
const scopeLabel = computed(() => {
    const key = 'audit_log.scopes.' + (props.scope.type || 'unknown')
    return te(key) ? t(key) : props.scope.type
})
</script>

<template>
    <span class="inline-flex min-w-0 max-w-full flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-muted">
        <UBadge color="neutral" variant="soft" size="sm" class="rounded-full">{{ scopeLabel }}</UBadge>
        <span v-if="scope.label" class="min-w-0 break-words [overflow-wrap:anywhere]">{{ scope.label }}</span>
        <span v-if="scope.id != null && (showId || !scope.label)" class="tabular-nums">#{{ scope.id }}</span>
    </span>
</template>
