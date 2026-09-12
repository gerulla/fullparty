import type { ActivitySlot } from '@/Types/ActivityRoster'

export type AllianceProgressStatus = 'cleared' | 'at_target' | 'below_target' | 'no_progress'

export type AllianceProgressRecord = {
    status: AllianceProgressStatus
    fflogs_unavailable: boolean
}

export type AllianceProgressContext = {
    enabled: boolean
    groupSlug: string
    activityId: number
    targetProgPointKey?: string | null
    slots: ActivitySlot[]
}

export type AllianceProgressResponse = {
    characters: Record<number, AllianceProgressRecord>
}
