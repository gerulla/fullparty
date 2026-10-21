import type { ActivityStatus } from './ActivityCore'
import type { LocalizedText } from './Common'
import type { CharacterIdentity } from './Characters'

export type V2HomeCharacter = CharacterIdentity

export type V2HomeUser = {
    id: number
    name: string
    avatar_url: string | null
    characters: V2HomeCharacter[]
}

export type V2HomeGroupSummary = {
    id: number
    name: string
    slug: string
    profile_picture_url: string | null
}

export type V2HomeGroup = V2HomeGroupSummary & {
    rank: 'member' | 'mod' | 'admin' | 'owner'
    datacenter: string | null
    group_type: string
    banner_image_url: string | null
    can_manage: boolean
    last_activity_at: string | null
    href: string
    runs_href: string
    settings_href: string | null
}

export type V2HomeRun = {
    id: number
    title: string | null
    description: string | null
    status: ActivityStatus
    starts_at: string
    duration_hours: number | null
    datacenter: string | null
    run_style: string | null
    intensity: string | null
    min_item_level: number | null
    beginner_friendly: boolean
    needs_application: boolean
    group: V2HomeGroupSummary
    activity_type: {
        id: number | null
        version_id: number
        name: LocalizedText
        difficulty: string | null
        small_image_url: string | null
        banner_image_url: string | null
    }
    href: string
}

export type V2HomeRunCard = V2HomeRun & {
    application_count: number
    applicant_avatars: (string | null)[]
    is_public: boolean
    allow_guest_applications: boolean
    target_prog_point_key: string | null
    target_prog_point_label: LocalizedText
    has_existing_application: boolean
    can_apply: boolean
    application_href: string | null
}

export type V2HomeGroupRuns = {
    group: V2HomeGroupSummary
    runs_href: string
    runs: V2HomeRunCard[]
}

export type V2HomeApplicationStatus = 'pending' | 'approved' | 'on_bench' | 'declined' | 'cancelled' | 'withdrawn'
export type V2HomeParticipationStatus = V2HomeApplicationStatus | 'assigned' | 'checked_in' | 'late' | 'missing' | 'filled_in'

export type V2HomeApplication = {
    id: number
    status: V2HomeApplicationStatus
    display_status: V2HomeParticipationStatus
    submitted_at: string | null
    selected_character_id: number | null
    character: {
        name: string | null
        world: string | null
        avatar_url: string | null
    }
    run: V2HomeRun
    href: string | null
    can_view_run?: boolean
}

// Deferred props are absent until their individual Inertia requests finish.
export type V2HomeProps = {
    user?: V2HomeUser
    applications?: V2HomeApplication[]
    applicationHistory?: V2HomeApplication[]
    groups?: V2HomeGroup[]
    recommendedRuns?: V2HomeRunCard[]
    groupRuns?: V2HomeGroupRuns[]
}
