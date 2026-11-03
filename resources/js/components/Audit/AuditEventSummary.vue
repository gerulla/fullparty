<script setup lang="ts">
import type { AuditLogRowRecord } from '@/Types/Audit'
import AuditEventValue from './AuditEventValue.vue'
import { useI18n } from 'vue-i18n'

defineProps<{ row: AuditLogRowRecord }>()
const { t, te } = useI18n()
</script>

<template>
    <span class="break-words leading-relaxed [overflow-wrap:anywhere]">
        <template v-if="row.summary_parts?.length">
            <template v-for="(part, index) in row.summary_parts" :key="index">
                <UBadge v-if="part.kind === 'activity' || part.kind === 'resource'" color="brand" variant="subtle" size="sm"
                    :icon="part.kind === 'resource' ? 'i-lucide-book-open' : undefined"
                    class="max-w-full rounded-md align-baseline whitespace-normal font-medium [overflow-wrap:anywhere]">{{ part.text }}</UBadge>
                <span v-else :class="{
                    'font-semibold text-highlighted': part.kind === 'actor',
                    'font-semibold text-brand': part.kind === 'subject',
                    'font-medium text-highlighted': part.kind === 'value',
                }"><AuditEventValue v-if="part.display_value" :value="part.display_value" /><template v-else>{{ part.text }}</template></span>
            </template>
        </template>
        <template v-else>
            <span class="font-semibold text-highlighted">{{ row.actor.name }}</span>
            {{ te(`audit_log.activity.${row.action}`) ? t(`audit_log.activity.${row.action}`) : (row.summary || row.title || row.action.replaceAll('.', ' ')) }}
        </template>
    </span>
</template>
