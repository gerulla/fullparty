import type { LocalizedText } from './Common'
import type { RunParticipationStatus } from './RunParticipation'

export type RunCardType = 'default' | 'date'

export type RunCardData = {
    id: number
    title: string | null
    status: string
    starts_at: string | null
    duration_hours?: number | null
    href: string
    is_public: boolean
    participation_status: RunParticipationStatus | null
    host_name?: string | null
    target_prog_point_key?: string | null
    target_prog_point_label?: LocalizedText
    application_count: number
    applicant_avatars?: (string | null)[]
    group: {
        name: string
        profile_picture_url: string | null
        dashboard_href?: string | null
    }
    activity_type: {
        name: LocalizedText
        small_image_url: string | null
        banner_image_url: string | null
    }
}
