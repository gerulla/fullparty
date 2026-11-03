import type { GroupDiscordSettingsData, GroupPayload } from '@/Types/GroupDiscord'

export function groupDiscordPreview(group: Pick<GroupPayload, 'id' | 'name' | 'slug'>): GroupDiscordSettingsData {
    const channels = [
        { id: 'preview-log', name: 'fullparty-bot-log' },
        { id: 'preview-runs', name: 'upcoming-runs-and-announcements' },
        { id: 'preview-general', name: 'general' },
    ]
    const roles = [
        { id: 'preview-staff', name: 'Raid Organisers & Moderators', color: 0xf59e0b },
        { id: 'preview-raider', name: 'FullParty Raider', color: 0xa855f7 },
        { id: 'preview-tower', name: 'Forked Tower Expedition Team', color: 0x22c55e },
    ]
    return {
        group: { ...group, current_user_role: 'owner', permissions: { can_manage_group: true }, discord_link_token_expires_at: null },
        integration: {
            id: -1, discord_guild_id: '123456789012345678', name: group.name,
            icon_url: null, permissions: null, guild_installed_at: null, updated_at: null,
        },
        inviteUrl: '',
        activityTypes: [
            { id: -101, name: 'The Forked Tower: Blood', difficulty: null },
            { id: -102, name: 'Delubrum Reginae (Savage)', difficulty: 'Savage' },
            { id: -103, name: 'The Minstrel’s Ballad: Tsukuyomi’s Pain', difficulty: 'Extreme' },
        ],
        snapshot: {
            guild_name: group.name, member_count: 128, channels, roles,
            bot_permissions: ['Manage Roles', 'Manage Nicknames', 'Send Messages'],
            settings: {
                bot_log_channel_id: 'preview-log', member_facing_channel_id: 'preview-runs',
                template_role_id: 'preview-raider', moderation_role_id: 'preview-staff', name_sync_enabled: true,
                run_role_template_overrides: [{ activity_id: -101, activity_name: 'The Forked Tower: Blood', role_id: 'preview-tower' }],
            },
        },
        membershipCoverage: { app_linked_member_count: 96, unlinked_member_count: 32, member_count: 128, stats_available: true },
    }
}
