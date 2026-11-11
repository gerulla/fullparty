import { computed, toValue } from 'vue'
import type { MaybeRefOrGetter } from 'vue'
import { useNow } from '@vueuse/core'
import { parseDate } from '@internationalized/date'
import { useI18n } from 'vue-i18n'
import { useDateTimeFormat } from '@/composables/useDateTimeFormat'
import { dateTimeInputInZone } from '@/utils/dateTimeFormat'
import { availabilityCycleStart, availabilityDayDate, availabilityMinuteInstant, availabilityWindowInstants } from '@/utils/availabilityScheduleTime'

export function useAvailabilityScheduleTime(options: {
    startsOn: MaybeRefOrGetter<string>
    timeZone: MaybeRefOrGetter<string>
    weeks: MaybeRefOrGetter<number>
    repeats: MaybeRefOrGetter<boolean>
}) {
    const { t } = useI18n()
    const { formatDateTime, formatDateOnly, timeDisplayLabel, displayTimeZoneLabel } = useDateTimeFormat()
    const now = useNow({ interval: 60_000 })
    const nowInScheduleZone = computed(() => dateTimeInputInZone(now.value, toValue(options.timeZone)))
    const minimumExceptionDate = computed(() => parseDate(nowInScheduleZone.value.slice(0, 10)))
    const cycleStart = computed(() => availabilityCycleStart(
        toValue(options.startsOn), toValue(options.weeks), toValue(options.repeats), minimumExceptionDate.value.toString(),
    ))
    const dayDate = (week: number, weekday: number) => availabilityDayDate(cycleStart.value, week, weekday)
    const weekRange = (week: number) => {
        const start = parseDate(cycleStart.value).add({ weeks: week })
        const format = (date: string) => formatDateOnly(date, { day: 'numeric', month: 'short', year: 'numeric' })
        return `${format(start.toString())} – ${format(start.add({ days: 6 }).toString())}`
    }
    const formatInstant = (date: Date) => formatDateTime(date, {
        weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZoneName: 'short',
    })
    const windowPreview = (date: string, start: string, end: string) => {
        const window = availabilityWindowInstants(date, start, end, toValue(options.timeZone))
        return window
            ? `${timeDisplayLabel.value}: ${formatInstant(window.start)} – ${formatInstant(window.end)}`
            : t('groups.availability.schedule.dst_preview')
    }
    const blockPreview = (week: number, weekday: number, start: string, end: string) => windowPreview(dayDate(week, weekday), start, end)
    const minutePreview = (week: number, weekday: number, minute: number) => {
        const instant = availabilityMinuteInstant(dayDate(week, weekday), minute, toValue(options.timeZone))
        return instant ? `${timeDisplayLabel.value}: ${formatInstant(instant)}` : t('groups.availability.schedule.dst_preview')
    }

    return { nowInScheduleZone, minimumExceptionDate, weekRange, dayDate, blockPreview, minutePreview, windowPreview, timeDisplayLabel, displayTimeZoneLabel }
}
