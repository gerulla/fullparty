export type GroupPayload = {
    id: number
    name: string
    slug: string
    current_user_role: string | null
    permissions: {
        can_manage_group: boolean
    }
    discord_link_token_expires_at: string | null
}

export type DiscordGuildIntegration = {
    id: number
    discord_guild_id: string
    name: string | null
    icon_url: string | null
    permissions: string | null
    guild_installed_at: string | null
    updated_at: string | null
}

export type LinkToken = {
    token: string
    expires_at: string
}

export type ActivityTypeOption = {
    id: number
    name: string
    difficulty: string | null
}

export type SnapshotOption = {
    id: string | number
    label?: string | null
    name?: string | null
    color?: number | null
    colors?: {
        primary_color?: number | null
        primary_hex?: string | null
        secondary_color?: number | null
        secondary_hex?: string | null
        tertiary_color?: number | null
        tertiary_hex?: string | null
        primaryColor?: number | null
        primaryHex?: string | null
        secondaryColor?: number | null
        secondaryHex?: string | null
        tertiaryColor?: number | null
        tertiaryHex?: string | null
    } | null
    usable?: boolean | null
    disabled_reason?: string | null
    type?: string | null
    type_name?: string | null
    viewable_by_bot?: boolean | null
    sendable_by_bot?: boolean | null
    position?: number | null
    managed?: boolean | null
}

export type SnapshotAvailableOptions = {
    bot_log_channels?: SnapshotOption[]
    run_announcement_channels?: SnapshotOption[]
    run_role_template_roles?: SnapshotOption[]
    bot_moderator_roles?: SnapshotOption[]
    roles?: SnapshotOption[]
    channels?: SnapshotOption[]
}

export type SelectOption = {
    label: string
    value: string
    disabled?: boolean
    description?: string
}

export type RoleSelectOption = SelectOption & {
    roleTextStyle?: Record<string, string> | null
}

export type RoleTemplateOverride = {
    activity_id: number | string
    activity_name?: string | null
    role_id: string | number | null
    created_at?: string | null
    updated_at?: string | null
}

export type RoleTemplateOverrideForm = {
    activity_id: string
    activity_name: string
    role_id: string
}

export type DiscordSettingId = string | number | null

export type DiscordGuildSettings = {
    bot_log_channel_id: DiscordSettingId
    member_facing_channel_id: DiscordSettingId
    run_announcement_channel_id: DiscordSettingId
    template_role_id: DiscordSettingId
    run_role_template_id: DiscordSettingId
    run_role_template_role_id: DiscordSettingId
    moderation_role_id: DiscordSettingId
    bot_moderator_role_id: DiscordSettingId
    name_sync_enabled: boolean | string | number | null
    enable_name_sync: boolean | string | number | null
    nickname_sync_enabled: boolean | string | number | null
    sync_discord_names_to_ff14: boolean | string | number | null
    run_role_template_overrides: RoleTemplateOverride[] | null
}

export type DiscordGuildSnapshot = {
    name?: string | null
    guild_name?: string | null
    icon_url?: string | null
    member_count?: number | null
    bot_permissions?: string | string[] | null
    roles?: SnapshotOption[]
    channels?: SnapshotOption[]
    available_options?: SnapshotAvailableOptions | null
    settings?: Partial<DiscordGuildSettings> | null
}

export type MembershipCoverage = {
    app_linked_member_count: number
    unlinked_member_count: number
    member_count: number
    stats_available: boolean
    membership_cache?: {
        refresh_status?: string | null
        stale?: boolean | null
        last_full_refresh_at?: string | null
        next_refresh_after?: string | null
        cache_age_seconds?: number | null
        last_error?: string | null
        refresh_queued?: boolean | null
    } | null
}

export type GroupDiscordSettingsData = {
    group: GroupPayload
    integration: DiscordGuildIntegration | null
    inviteUrl: string
    activityTypes: ActivityTypeOption[]
    snapshot: DiscordGuildSnapshot | null
    membershipCoverage?: MembershipCoverage | null
}

