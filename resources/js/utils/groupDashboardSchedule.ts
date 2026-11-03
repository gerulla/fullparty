import { toDisplayDateKey } from '@/utils/activityCalendar'
import type { GroupDashboardActivity, GroupDashboardCalendarDay } from '@/Types/Groups'

const sortByStart = (left: GroupDashboardActivity, right: GroupDashboardActivity) =>
    Date.parse(left.starts_at!) - Date.parse(right.starts_at!) || left.id - right.id

export function upcomingGroupRuns(upcomingActivities: GroupDashboardActivity[], now: Date): GroupDashboardActivity[] {
    return upcomingActivities
        .filter(activity => activity.starts_at && Date.parse(activity.starts_at) >= now.getTime())
        .sort(sortByStart)
}

export function nextGroupRun(upcomingActivities: GroupDashboardActivity[], now: Date): GroupDashboardActivity | null {
    return upcomingGroupRuns(upcomingActivities, now)[0] ?? null
}

export function groupDashboardDays(
    activities: GroupDashboardActivity[], now: Date, timeZone?: string,
): GroupDashboardCalendarDay[] {
    const todayKey = toDisplayDateKey(now, timeZone)
    // Use UTC for civil-date arithmetic so DST cannot skip or repeat a calendar day.
    const start = new Date(`${todayKey}T00:00:00Z`)
    const days = Array.from({ length: 7 }, (_, offset) => {
        const date = new Date(start)
        date.setUTCDate(date.getUTCDate() + offset)
        return { key: date.toISOString().slice(0, 10), date, isToday: offset === 0, activities: [] } as GroupDashboardCalendarDay
    })
    const daysByKey = new Map(days.map(day => [day.key, day]))
    for (const activity of activities) {
        if (!activity.starts_at) continue
        const startsAt = new Date(activity.starts_at)
        if (Number.isNaN(startsAt.getTime())) continue
        daysByKey.get(toDisplayDateKey(startsAt, timeZone))?.activities.push(activity)
    }
    for (const day of days) day.activities.sort(sortByStart)
    return days
}
