import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { GroupNavigationContext, GroupNavigationLink, GroupNavigationSection } from '@/Types/GroupNavigation'

export function useGroupDashboardNavigation(group: () => GroupNavigationContext) {
    const page = usePage()
    const { t } = useI18n()

    const sections = computed<GroupNavigationSection[]>(() => {
        const current = group()
        const permissions = current.permissions ?? {}
        const features = current.features ?? {}
        const member = Boolean(current.current_user_role || permissions.can_view_members)
        const resources = member && Boolean(features.resource_hub_enabled)
        const availability = member && features.availability_scheduler_enabled
            && (features.availability_minimum_role !== 'moderator'
                || ['owner', 'admin', 'moderator'].includes(current.current_user_role ?? '')
                || permissions.can_manage_members)
        const settings = permissions.can_update_group_settings || permissions.can_manage_discovery || permissions.can_manage_group
        const path = page.url.split(/[?#]/)[0].replace(/\/$/, '')
        const link = (key: string, name: string, icon: string, exact = false): GroupNavigationLink => {
            const params = { group: current.slug, locale: String(page.props.locale?.current ?? 'en') }
            const href = route(name, params, false)
            return {
                label: t(`groups.index.navigation.${key}`), icon, href,
                active: path === href || (!exact && path.startsWith(`${href}/`)),
            }
        }

        return [
            {
                key: 'general', label: t('groups.index.navigation.general'),
                links: [
                    link('home', 'groups.dashboard', 'i-lucide-layout-dashboard', true),
                    link('activities', 'groups.dashboard.activities.index', 'i-lucide-calendar-range'),
                    ...(permissions.can_view_members ? [link('members', 'groups.dashboard.members', 'i-lucide-users')] : []),
                    ...(resources ? [link('resources', 'groups.dashboard.resources.index', 'i-lucide-book-open-text', true)] : []),
                    ...(availability ? [link('availability', 'groups.dashboard.availability', 'i-lucide-calendar-clock')] : []),
                ],
            },
            {
                key: 'data', label: t('groups.index.navigation.data'),
                links: member ? [
                    ...((features.statistics_enabled ?? true) ? [link('statistics', 'groups.dashboard.statistics', 'i-lucide-chart-no-axes-combined')] : []),
                    ...((features.leaderboard_enabled ?? true) ? [
                        link('leaderboard', 'groups.dashboard.leaderboard', 'i-lucide-trophy'),
                        ...(current.slug === 'ftel' ? [link('legacy_leaderboard', 'groups.dashboard.legacy-leaderboard', 'i-lucide-archive')] : []),
                    ] : []),
                ] : [],
            },
            {
                key: 'content', label: t('groups.index.navigation.content'),
                links: permissions.can_manage_members ? [
                    ...(resources ? [link('manage_resources', 'groups.dashboard.resources.manage', 'i-lucide-folder-open')] : []),
                    link('delubrum_reginae_savage', 'groups.dashboard.content.delubrum-reginae-savage', 'i-lucide-castle'),
                    link('forked_tower_blood', 'groups.dashboard.content.forked-tower-blood', 'i-lucide-droplets'),
                ] : [],
            },
            {
                key: 'moderation', label: t('groups.index.navigation.moderation'),
                links: [
                    ...(permissions.can_review_membership_applications ? [link('membership_applications', 'groups.dashboard.membership-applications.index', 'i-lucide-clipboard-check')] : []),
                    ...(permissions.can_manage_discovery ? [link('discovery_settings', 'groups.dashboard.discovery-settings', 'i-lucide-radar')] : []),
                    ...(permissions.can_manage_members ? [link('audit_log', 'groups.dashboard.audit-log', 'i-lucide-scroll-text')] : []),
                ],
            },
            {
                key: 'configuration', label: t('groups.index.navigation.configuration'),
                links: [
                    ...(permissions.can_manage_membership_application_form ? [link('application_form', 'groups.dashboard.membership-application-form.edit', 'i-lucide-list-checks')] : []),
                    ...(permissions.can_manage_group ? [link('discord_integration', 'groups.dashboard.discord-integration', 'ic:baseline-discord')] : []),
                    ...(settings ? [
                        link('shortcuts', 'groups.dashboard.shortcuts.index', 'i-lucide-mouse-pointer-click'),
                        link('settings', 'groups.dashboard.settings', 'i-lucide-settings-2', true),
                    ] : []),
                ],
            },
        ].filter(section => section.links.length > 0)
    })

    return { sections }
}
