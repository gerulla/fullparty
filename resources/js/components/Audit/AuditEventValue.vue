<script setup lang="ts">
import type { AuditDisplayValue } from '@/Types/Audit'
import { useTimeDisplayMode } from '@/composables/useTimeDisplayMode'
import { createDateTimeFormatter } from '@/utils/dateTimeFormat'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ value: AuditDisplayValue }>()
const { t, locale } = useI18n()
const { withDisplayTimeZone, isServerTime } = useTimeDisplayMode()
const formattedDate = computed(() => {
    if (props.value.type !== 'datetime') return ''
    const date = new Date(props.value.value)
    if (Number.isNaN(date.getTime())) return props.value.value
    return `${createDateTimeFormatter(locale.value, withDisplayTimeZone({
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    })).format(date)} ${isServerTime.value ? 'ST' : 'LT'}`
})
const formattedDuration = computed(() => props.value.type === 'duration'
    ? new Intl.NumberFormat(locale.value, { style: 'unit', unit: 'hour', unitDisplay: 'short', maximumFractionDigits: 2 }).format(props.value.value)
    : '')
</script>

<template>
    <span v-if="value.type === 'empty'" class="italic text-muted">{{ t('audit_log.defaults.empty') }}</span>
    <time v-else-if="value.type === 'datetime'" :datetime="value.value" class="break-words">{{ formattedDate }}</time>
    <span v-else-if="value.type === 'duration'" class="tabular-nums">{{ formattedDuration }}</span>
    <span v-else-if="value.type === 'boolean'" class="inline-flex items-center gap-1.5">
        <UIcon :name="value.value ? 'i-lucide-check' : 'i-lucide-minus'" class="size-3.5 shrink-0 text-muted" aria-hidden="true" />
        {{ t(value.value ? 'audit_log.defaults.true' : 'audit_log.defaults.false') }}
    </span>
    <ul v-else-if="value.type === 'list'" class="min-w-0 space-y-1">
        <li v-for="(item, index) in value.value" :key="index" class="break-words [overflow-wrap:anywhere]">
            <AuditEventValue :value="item" />
        </li>
    </ul>
    <dl v-else-if="value.type === 'object'" class="min-w-0 space-y-2">
        <div v-for="(field, index) in value.value" :key="index" class="min-w-0">
            <dt class="text-xs font-medium text-muted">{{ field.label }}</dt>
            <dd class="mt-0.5 break-words [overflow-wrap:anywhere]"><AuditEventValue :value="field.value" /></dd>
        </div>
    </dl>
    <span v-else class="whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ value.value }}</span>
</template>
