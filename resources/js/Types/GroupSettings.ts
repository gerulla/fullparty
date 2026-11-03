import type { GroupDashboardGroup, GroupFeatureSettings, GroupJoinMode, GroupRole, GroupType } from '@/Types/Groups'

export type GroupSettingsSection = 'general' | 'invites' | 'features' | 'discovery' | 'shortcuts' | 'audit_log' | 'discord' | 'ownership' | 'danger_zone'

export type GroupQuickCreateShortcut = {
    id: number | null
    time: string
    time_mode: 'server' | 'local'
    sort_order: number
}

export type GroupDiscoverySettingsGroup = Pick<GroupDashboardGroup,
    'slug' | 'datacenter' | 'region' | 'primary_focuses' | 'experience_expectation' | 'voice_expectation'
    | 'preferred_languages' | 'tags' | 'active_timezone' | 'active_days' | 'active_start_time' | 'active_end_time'
> & { permissions: Pick<GroupDashboardGroup['permissions'], 'can_manage_discovery'> }

export type GroupSettingsNavigationState = {
    activeSection: GroupSettingsSection
    sectionOpen: boolean
}

export type GroupSettingsInvite = {
    id: number
    token: string
    is_system: boolean
    uses: number
    max_uses: number | null
    expires_at: string | null
    created_by: string | null
    created_at: string | null
}

export type GroupSettingsGroup = GroupDiscoverySettingsGroup & {
    id: number
    name: string
    slug: string
    description: string | null
    profile_picture_url: string | null
    banner_image_url: string | null
    discord_invite_url: string | null
    datacenter: string
    group_type: GroupType
    join_mode: GroupJoinMode
    is_visible: boolean
    current_user_role: GroupRole | null
    features: GroupFeatureSettings
    owner: { id: number | null; name: string | null }
    members: { id: number; name: string; role: GroupRole }[]
    invites: GroupSettingsInvite[]
    quick_create_shortcuts: GroupQuickCreateShortcut[]
    permissions: {
        can_update_group_settings: boolean
        can_manage_invites: boolean
        can_transfer_ownership: boolean
        can_manage_group: boolean
    }
}
