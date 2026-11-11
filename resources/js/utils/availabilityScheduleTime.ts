import { parseDate } from '@internationalized/date'
import { dateTimeInputToInstant } from './dateTimeFormat'
import type { AvailabilityTimelineEntry, DayKey, WeekSchedule } from '@/Types/Availability'

export const availabilityDays: DayKey[] = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']

function daysBetween(start: string, end: string): number {
    return Math.floor((parseDate(end).toDate('UTC').getTime() - parseDate(start).toDate('UTC').getTime()) / 86_400_000)
}

export function availabilityCycleStart(startsOn: string, weeks: number, repeats: boolean, today: string): string {
    const start = parseDate(startsOn)
    const elapsedDays = daysBetween(startsOn, today)
    const elapsedCycles = repeats ? Math.max(0, Math.floor(elapsedDays / (weeks * 7))) : 0
    return start.add({ days: elapsedCycles * weeks * 7 }).toString()
}

/** Weeks are anchored to starts_on, which need not be a Monday. */
export function availabilityDayDate(cycleStart: string, week: number, weekday: number): string {
    const start = parseDate(cycleStart).add({ weeks: week })
    const startWeekday = start.toDate('UTC').getUTCDay() || 7
    return start.add({ days: (weekday - startWeekday + 7) % 7 }).toString()
}

export function availabilityMinuteInstant(date: string, minute: number, timeZone: string): Date | null {
    const day = parseDate(date).add({ days: Math.floor(minute / 1440) })
    const clock = minute % 1440
    const time = `${String(Math.floor(clock / 60)).padStart(2, '0')}:${String(clock % 60).padStart(2, '0')}`
    return dateTimeInputToInstant(`${day}T${time}`, timeZone)
}

export function availabilityWindowInstants(date: string, start: string, end: string, timeZone: string): { start: Date, end: Date } | null {
    const endDate = parseDate(date).add({ days: end <= start ? 1 : 0 }).toString()
    const startsAt = dateTimeInputToInstant(`${date}T${start}`, timeZone)
    const endsAt = dateTimeInputToInstant(`${endDate}T${end}`, timeZone)
    return startsAt && endsAt ? { start: startsAt, end: endsAt } : null
}

/** Retain an exception that started yesterday while its overnight hours still apply. */
export function isPendingAvailabilityException(date: string, start: string | null, end: string | null, nowInZone: string): boolean {
    const [today, time] = nowInZone.split('T')
    if (date >= today) return true
    return start !== null && end !== null && end < start && time < end
        && date === parseDate(today).subtract({ days: 1 }).toString()
}

/** Split overnight blocks onto their actual dates, including cycle boundaries. */
export function availabilityTimelineDay(weeks: WeekSchedule[], startsOn: string, date: string, repeats: boolean): AvailabilityTimelineEntry[] {
    const entries: AvailabilityTimelineEntry[] = []
    for (const continuation of [false, true]) {
        const sourceDate = parseDate(date).subtract({ days: continuation ? 1 : 0 }).toString()
        const elapsed = daysBetween(startsOn, sourceDate)
        if (elapsed < 0 || (!repeats && elapsed >= weeks.length * 7)) continue
        const sourceWeek = Math.floor(elapsed / 7) % weeks.length
        const sourceDay = availabilityDays[(parseDate(sourceDate).toDate('UTC').getUTCDay() + 6) % 7]
        const day = weeks[sourceWeek]?.[sourceDay]
        if (!day?.enabled) continue
        for (const block of day.blocks) {
            const minute = (value: string) => { const [hour, minute] = value.split(':').map(Number); return hour * 60 + minute }
            const start = minute(block.starts_at)
            const end = minute(block.ends_at)
            const overnight = end < start
            if (continuation && (!overnight || end === 0)) continue
            entries.push({
                block, sourceWeek, sourceDay, sourceDate, continuation,
                startMinute: continuation ? 0 : start,
                endMinute: continuation ? end : overnight ? 1440 : end,
            })
        }
    }
    return entries
}
