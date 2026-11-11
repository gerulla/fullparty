const titleKeys: Record<string, string> = {
    'Activities/Index': 'groups.dashboard.navigation.run_calendar',
    'Activities/Create': 'groups.dashboard.navigation.create_run',
    'Activities/Edit': 'groups.index.navigation.edit_run',
    'Activities/Show': 'groups.index.navigation.manage_run',
    'Members/Index': 'groups.index.navigation.members',
    Availability: 'groups.index.navigation.availability',
    Statistics: 'groups.index.navigation.statistics',
    Leaderboard: 'groups.index.navigation.leaderboard',
    LegacyLeaderboard: 'groups.index.navigation.legacy_leaderboard',
    'Resources/Index': 'groups.index.navigation.resources',
    'Resources/Manage': 'groups.index.navigation.manage_resources',
    'Content/Index': 'groups.dashboard.navigation.content_customization',
    'Settings/Index': 'groups.index.navigation.settings',
    'Settings/Discovery': 'groups.index.navigation.discovery_settings',
    'Settings/Shortcuts': 'groups.index.navigation.shortcuts',
    'AuditLog/Index': 'groups.index.navigation.audit_log',
    DiscordIntegration: 'groups.index.navigation.discord_integration',
    'MembershipApplications/Index': 'groups.index.navigation.membership_applications',
    'MembershipApplicationForm/Edit': 'groups.index.navigation.application_form',
    GroupProfile: 'groups.index.navigation.info',
}

export function groupDashboardPageTitleKey(component: string, forkedTower = false): string {
    if (component === 'Dashboard/Groups/Content/Index' && forkedTower) {
        return 'groups.index.content.overview.forked_tower_title'
    }
    return titleKeys[component.replace(/^Dashboard\/Groups\//, '')] ?? 'groups.dashboard.navigation.overview'
}
