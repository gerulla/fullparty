<script setup lang="ts">
import type { AuditLogRowRecord } from '@/Types/Audit'
import AuditEventSummary from './AuditEventSummary.vue'
import { auditCategoryIcons, auditSeverityStyle } from '@/utils/auditPresentation'
import { useTimeDisplayMode } from '@/composables/useTimeDisplayMode'
import { createDateTimeFormatter } from '@/utils/dateTimeFormat'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ row: AuditLogRowRecord; selected?: boolean }>()
const emit = defineEmits<{ select: [row: AuditLogRowRecord] }>()
const { t, te, locale } = useI18n()
const { withDisplayTimeZone, isServerTime } = useTimeDisplayMode()
const severity = computed(() => auditSeverityStyle(props.row.severity))
const severityLabel = computed(() => te(`audit_log.severities.${props.row.severity}`)
    ? t(`audit_log.severities.${props.row.severity}`) : props.row.severity)
const timestamp = computed(() => createDateTimeFormatter(locale.value, withDisplayTimeZone({
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
})).format(new Date(props.row.created_at)))
</script>

<template>
    <button type="button" class="@container block w-full min-w-0 px-3 py-3 text-left transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand sm:px-4"
        :class="selected ? 'bg-brand/10 ring-1 ring-inset ring-brand/35' : 'hover:bg-elevated/60'"
        :aria-pressed="selected ?? false" @click="emit('select', row)">
        <span class="grid min-w-0 grid-cols-[1.25rem_minmax(0,1fr)] gap-x-3 gap-y-2 @xl:grid-cols-[1.25rem_minmax(0,1fr)_auto] @4xl:grid-cols-[1.25rem_minmax(0,1fr)_10rem_5rem]">
            <UIcon :name="row.icon || (row.category ? auditCategoryIcons[row.category] : 'i-lucide-scroll-text')"
                class="mt-0.5 size-5 shrink-0" :class="selected ? 'text-brand' : 'text-muted'" aria-hidden="true" />
            <span class="min-w-0 text-sm text-toned">
                <AuditEventSummary :row="row" />
                <UBadge v-if="row.category && !row.activity" color="neutral" variant="subtle" size="sm"
                    :icon="auditCategoryIcons[row.category]" class="ml-2 max-w-full rounded-full align-baseline whitespace-normal">
                    {{ t(`audit_log.categories.${row.category}`) }}
                </UBadge>
            </span>
            <span class="col-start-2 flex min-w-0 flex-wrap items-center gap-2 @4xl:col-start-auto @4xl:self-start">
                <UBadge :color="severity.color" :icon="severity.icon" variant="subtle" size="sm" class="max-w-full rounded-full whitespace-normal">
                    {{ severityLabel }}
                </UBadge>
                <time :datetime="row.created_at" class="ml-auto whitespace-nowrap text-xs tabular-nums text-muted @xl:hidden">
                    {{ timestamp }} {{ isServerTime ? 'ST' : 'LT' }}
                </time>
            </span>
            <time :datetime="row.created_at" class="col-start-3 row-start-1 hidden whitespace-nowrap pt-0.5 text-right text-xs tabular-nums text-muted @xl:block @4xl:col-start-auto @4xl:row-start-auto">
                {{ timestamp }} {{ isServerTime ? 'ST' : 'LT' }}
            </time>
        </span>
    </button>
</template>
