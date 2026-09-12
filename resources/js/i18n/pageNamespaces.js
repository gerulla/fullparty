const common = ['general', 'meta']
const dashboard = [...common, 'applications', 'auth', 'characters', 'dashboard', 'groups/dashboard', 'groups/index', 'navigation', 'notifications', 'settings']

// Include modal and shared-component dictionaries, not just the page's visible labels.
const features = {
    'Admin/ActivityTypes': ['admin/activity_types'],
    'Admin/ActivityTypesCreate': ['admin/activity_types'],
    'Admin/ActivityTypesEdit': ['admin/activity_types'],
    'Admin/AuditLog': ['audit_log'],
    'Admin/CharacterData': ['admin/character_classes', 'admin/character_definitions', 'admin/phantom_jobs'],
    'Admin/DiscordGuildIntegrations': ['admin/discord_guild_links'],
    'Admin/FeaturedGroups': ['admin/featured_groups'],
    'Admin/FflogsPlayground': ['admin/fflogs_playground'],
    'Admin/Integrations': ['admin/integrations'],
    'Admin/Quotas': ['admin/quotas'],
    'Admin/SystemData': ['admin/bozja_data', 'admin/raid_positions', 'admin/system_data'],
    'Admin/SystemNotifications': ['admin/system_notifications'],
    'Dashboard/Account/MyApplications': ['calendar', 'groups/activities', 'party_finder'],
    'Dashboard/Account/MyCharacters': [],
    'Dashboard/Account/Notifications': [],
    'Dashboard/Dashboard': ['groups/activities'],
    'Dashboard/Groups/Activities/Create': ['groups/activities'],
    'Dashboard/Groups/Activities/Edit': ['groups/activities'],
    'Dashboard/Groups/Activities/Index': ['calendar', 'groups/activities', 'groups/shortcuts', 'runs'],
    'Dashboard/Groups/Activities/Show': ['audit_log', 'groups/activities', 'groups/members', 'party_finder'],
    'Dashboard/Groups/AuditLog/Index': ['audit_log', 'groups/access'],
    'Dashboard/Groups/Availability': ['groups/availability'],
    'Dashboard/Groups/CommunityDashboard': ['groups/activities', 'groups/common', 'groups/notifications'],
    'Dashboard/Groups/Content/DelubrumReginaeSavage': ['rich_text'],
    'Dashboard/Groups/Content/ForkedTowerBlood': [],
    'Dashboard/Groups/DiscordIntegration': ['groups/access', 'groups/discord'],
    'Dashboard/Groups/Index': ['groups/common', 'groups/notifications'],
    'Dashboard/Groups/Leaderboard': ['groups/leaderboard'],
    'Dashboard/Groups/LegacyLeaderboard': ['groups/legacy_leaderboard'],
    'Dashboard/Groups/Members/Index': ['audit_log', 'groups/access', 'groups/common', 'groups/members'],
    'Dashboard/Groups/MembershipApplicationForm/Edit': ['groups/access', 'groups/membership_applications'],
    'Dashboard/Groups/MembershipApplications/Index': ['groups/access', 'groups/membership_applications'],
    'Dashboard/Groups/MembershipRequests/Index': ['groups/membership_applications'],
    'Dashboard/Groups/Runs/Index': [],
    'Dashboard/Groups/Resources/Index': ['groups/resources'],
    'Dashboard/Groups/Resources/Manage': ['groups/resources', 'rich_text'],
    'Dashboard/Groups/Settings/Discovery': ['groups/access', 'groups/common', 'groups/settings'],
    'Dashboard/Groups/Settings/Index': ['groups/access', 'groups/common', 'groups/settings'],
    'Dashboard/Groups/Settings/Shortcuts': ['groups/shortcuts'],
    'Dashboard/Groups/StaticDashboard': ['groups/common', 'groups/notifications'],
    'Dashboard/Groups/Statistics': ['groups/statistics'],
    'Dashboard/Runs/Index': ['groups/activities', 'runs'],
    'Dashboard/Runs/MyRuns': ['calendar', 'groups/activities', 'groups/shortcuts', 'my_runs', 'runs'],
    'Dashboard/Settings/Index': [],
    'Groups/Activities/Application': ['groups/activities'],
    'Groups/Activities/ApplicationConfirmation': ['calendar', 'groups/activities'],
    'Groups/Activities/NonApplicationOverview': ['calendar', 'groups/activities'],
    'Groups/Activities/Overview': ['calendar', 'groups/activities'],
    'Groups/MembershipApplications/Create': ['groups/membership_applications'],
}

const standalone = {
    'Resources/Show': ['groups/resources'],
    Home: ['auth', 'groups/activities', 'landing'],
    'Legal/CookiesPolicy': [],
    'Legal/PrivacyPolicy': [],
    'Groups/Invite': ['groups/index', 'groups/invite'],
    'auth/ForgotPassword': ['auth'],
    'auth/LinkSocial': ['auth'],
    'auth/Login': ['auth'],
    'auth/Register': ['auth'],
    'auth/ResetPassword': ['auth'],
    'auth/VerifyEmail': ['auth'],
    'auth/XivPlugin/Authorize': ['xivplugin'],
    'auth/XivPlugin/DeviceCode': ['xivplugin'],
}

export function pageNamespaces(name, application = 'main') {
    if (application === 'calculator' && name === 'Home') return ['auth', 'calculator', 'navigation']
    if (application === 'planner' && name === 'Home') return ['auth', 'general', 'navigation', 'planner']
    if (application === 'main') {
        const namespaces = Object.hasOwn(standalone, name) ? [...common, ...standalone[name]]
            : Object.hasOwn(features, name) ? [...new Set([...dashboard, ...features[name]])] : null
        if (namespaces) {
            if (namespaces.includes('groups/activities') || namespaces.includes('groups/resources')) namespaces.push('holsters')
            return namespaces
        }
    }
    throw new Error(`Translation namespaces are not configured for ${application}:${name}`)
}
