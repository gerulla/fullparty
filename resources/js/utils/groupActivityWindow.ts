import type { GroupActiveTimePreset, GroupActivityTimeRange, GroupCreateActivityWindow } from '@/Types/Groups'

export const groupWeekdays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const
export const groupActivityTimeRanges: Record<Exclude<GroupActiveTimePreset, 'custom'>, GroupActivityTimeRange> = {
    all_day: { start: '00:00', end: '23:59' },
    morning: { start: '08:00', end: '12:00' },
    evening: { start: '18:00', end: '22:00' },
    late_night: { start: '22:00', end: '03:00' },
}

export function createGroupActivityWindow(): GroupCreateActivityWindow {
    return {
        active_timezone: '',
        active_days: [...groupWeekdays],
        active_start_time: '00:00',
        active_end_time: '23:59',
        daysPreset: 'every_day',
        customDays: [],
        timePreset: 'all_day',
        customTime: { start: '11:00', end: '18:00' },
    }
}

export function groupActivityTimePreset(start: string, end: string): GroupActiveTimePreset {
    return (Object.keys(groupActivityTimeRanges) as Exclude<GroupActiveTimePreset, 'custom'>[])
        .find(key => groupActivityTimeRanges[key].start === start && groupActivityTimeRanges[key].end === end) ?? 'custom'
}

export function timeOfDayMinutes(value: string): number {
    if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(value)) return NaN
    const [hours, minutes] = value.split(':').map(Number)
    return hours * 60 + minutes
}

export function minutesToTimeOfDay(value: number): string {
    const minutes = Math.max(0, Math.min(1439, Math.round(value)))
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`
}

export function activityWindowSegments(start: number, end: number): { left: number; width: number }[] {
    if (!Number.isFinite(start) || !Number.isFinite(end) || start === end) return []
    const from = start / 1439 * 100
    const to = end / 1439 * 100
    return start < end ? [{ left: from, width: to - from }]
        : [{ left: 0, width: to }, { left: from, width: 100 - from }]
}
