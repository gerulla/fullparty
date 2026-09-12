import type { LocalizedText } from './Common'

export type ApplicantBossProgress = { kills: number; progress_percent: number }

export type ApplicantRecord = {
    milestones: Array<{
        key: string
        label: LocalizedText
        onsite: ApplicantBossProgress | null
        fflogs: ApplicantBossProgress | null
    }>
    onsite_available: boolean
    fflogs_status: 'available' | 'not_configured' | 'identity_unavailable' | 'error'
}

export type ApplicantRecordContext = {
    open: boolean
    shouldFetch: boolean
    groupSlug: string
    activityId: number
    applicationId: number
}
