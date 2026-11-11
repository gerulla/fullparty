export type Cadence = 'weekly' | 'biweekly' | 'monthly'
export type DayKey = 'monday' | 'tuesday' | 'wednesday' | 'thursday' | 'friday' | 'saturday' | 'sunday'
export type AvailabilityStatus = 'available' | 'tentative'
export type TimeBlock = { id: number, starts_at: string, ends_at: string, status: AvailabilityStatus }
export type DaySchedule = { enabled: boolean, blocks: TimeBlock[] }
export type WeekSchedule = Record<DayKey, DaySchedule>
export type AvailabilityException = { id: number, date: string, has_time_range: boolean, starts_at: string, ends_at: string }
export type AvailabilityTimelineEntry = {
    block: TimeBlock
    sourceWeek: number
    sourceDay: DayKey
    sourceDate: string
    startMinute: number
    endMinute: number
    continuation: boolean
}
