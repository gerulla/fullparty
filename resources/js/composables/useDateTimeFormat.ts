import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTimeDisplayMode } from '@/composables/useTimeDisplayMode'
import { createDateTimeFormatter, parseTimestamp, dateTimeInputInZone, dateTimeInputToInstant, formatCalendarDate, formatWallClock } from '@/utils/dateTimeFormat'
import type { DateTimeValue, DisplayDateTimeOptions } from '@/Types/DateTime'

export function useDateTimeFormat() {
    const { locale } = useI18n({ useScope: 'global' })
    const { timeDisplayMode, displayTimeZone, displayTimeZoneLabel, withDisplayTimeZone } = useTimeDisplayMode()
    const timeDisplayLabel = computed(() => timeDisplayMode.value === 'server' ? 'ST' : 'LT')

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

    function formatTimeWithMode(value: DateTimeValue, fallback = '—'): string {
        const date = parseTimestamp(value)
        if (!date) return fallback
        const time = formatDateTime(date, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
        return `${time} ${timeDisplayLabel.value}`
    }

    const toDateTimeInput = (value: DateTimeValue) => dateTimeInputInZone(value, displayTimeZone.value)
    const fromDateTimeInput = (value: string, reference?: DateTimeValue) => dateTimeInputToInstant(value, displayTimeZone.value, reference)
    const formatDateOnly = (value: string, options?: DisplayDateTimeOptions) => formatCalendarDate(locale.value, value, options)
    const formatWallTime = (value: string) => formatWallClock(locale.value, value)

    return { formatDateTime, formatDateTimeParts, formatTimeWithMode, toDateTimeInput, fromDateTimeInput, formatDateOnly, formatWallTime, displayTimeZone, displayTimeZoneLabel, timeDisplayLabel }
}
