import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTimeDisplayMode } from '@/composables/useTimeDisplayMode'
import { createDateTimeFormatter } from '@/utils/dateTimeFormat'
import type { DateTimeValue, DisplayDateTimeOptions } from '@/Types/DateTime'

export function useDateTimeFormat() {
    const { locale } = useI18n({ useScope: 'global' })
    const { timeDisplayMode, displayTimeZone, displayTimeZoneLabel, withDisplayTimeZone } = useTimeDisplayMode()
    const timeDisplayLabel = computed(() => timeDisplayMode.value === 'server' ? 'ST' : 'LT')

    function parseTimestamp(value: DateTimeValue): Date | null {
        if (value === null || value === undefined || value === '') return null
        const date = value instanceof Date ? value : new Date(value)
        return Number.isNaN(date.getTime()) ? null : date
    }

    // Read locale and timezone on every call so templates and computed labels
    // track preference changes, including formatters used in deferred content.
    function formatDateTime(
        value: DateTimeValue,
        options: DisplayDateTimeOptions = { dateStyle: 'medium', timeStyle: 'short' },
        fallback = '—',
    ): string {
        const date = parseTimestamp(value)
        if (!date) return fallback
        return createDateTimeFormatter(locale.value, withDisplayTimeZone(options)).format(date)
    }

    function formatDateTimeParts(value: DateTimeValue, options: DisplayDateTimeOptions = {}): Intl.DateTimeFormatPart[] {
        const date = parseTimestamp(value)
        if (!date) return []
        return createDateTimeFormatter(locale.value, withDisplayTimeZone(options)).formatToParts(date)
    }

    return { formatDateTime, formatDateTimeParts, displayTimeZone, displayTimeZoneLabel, timeDisplayLabel }
}
