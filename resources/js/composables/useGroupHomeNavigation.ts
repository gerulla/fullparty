import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { GroupHomeNavigationItem, GroupHomeNavigationLink, GroupHomeQuickLinkSection, GroupNavigationContext } from '@/Types/GroupNavigation'
import { useGroupDashboardNavigation } from '@/composables/useGroupDashboardNavigation'

export function useGroupHomeNavigation(group: () => GroupNavigationContext) {
    const page = usePage()
    const { t } = useI18n()
    const { sections } = useGroupDashboardNavigation(group)

    const destinations = computed(() => {
        const current = group()
        const permissions = current.permissions ?? {}
        const path = page.url.split(/[?#]/)[0].replace(/\/$/, '')
        const activeSection = new URLSearchParams(page.url.split('?')[1]?.split('#')[0] ?? '').get('section')
        const links = new Map<string, GroupHomeNavigationLink>(sections.value.flatMap(section => section.links).map(link => [link.key, {
            ...link,
            description: ['home', 'delubrum_reginae_savage', 'forked_tower_blood'].includes(link.key)
                ? '' : t(`groups.dashboard.navigation.descriptions.${link.key}`),
        }]))
        const add = (key: string, name: string, icon: string, label: string, section?: string) => {
            const href = route(name, {
                group: current.slug,
                locale: String(page.props.locale?.current ?? 'en'),
                ...(section ? { _query: { section } } : {}),
            }, false)
            const targetPath = href.split('?')[0]
            links.set(key, {
                key, label, icon, href,
                active: section
                    ? path === targetPath && activeSection === section
                    : (path === targetPath || path.startsWith(`${targetPath}/`))
                        && !(key === 'content_customization' && links.get('manage_resources')?.active)
                        && !(key === 'settings' && activeSection === 'audit_log'),
                description: t(`groups.dashboard.navigation.descriptions.${key}`),
            })
        }

        if (permissions.can_manage_activities) {
            add('create_run', 'groups.dashboard.activities.create', 'i-lucide-calendar-plus', t('groups.dashboard.navigation.create_run'))
        }
        if (permissions.can_manage_members) {
            add('content_customization', 'groups.dashboard.content.index', 'i-lucide-wrench', t('groups.dashboard.navigation.content_customization'))
            add('audit_log', 'groups.dashboard.settings', 'i-lucide-scroll-text', t('groups.index.navigation.audit_log'), 'audit_log')
        }
        if (permissions.can_manage_members || permissions.can_manage_activities || permissions.can_update_group_settings
            || permissions.can_manage_discovery || permissions.can_manage_group) {
            add('settings', 'groups.dashboard.settings', 'i-lucide-settings-2', t('groups.index.navigation.settings'))
        }
        const availability = links.get('availability')
        if (availability && current.features?.availability_minimum_role === 'moderator') {
            availability.badge = t('groups.dashboard.navigation.staff_only')
        }
        return links
    })

    const pick = (...keys: string[]) => keys.flatMap(key => {
        const link = destinations.value.get(key)
        return link ? [link] : []
    })

    const navigation = computed<GroupHomeNavigationItem[]>(() => {
        const direct = (key: string) => pick(key).map(link => ({ ...link }))
        const menu = (key: string, icon: string, links: GroupHomeNavigationLink[], label = t(`groups.dashboard.navigation.${key}`)) => links.length ? [{
            key, icon, label, links,
            active: links.some(link => link.active),
        }] : []
        return [
            ...direct('home').map(link => ({ ...link, icon: 'i-lucide-house' })),
            ...direct('activities').map(link => ({ ...link, label: t('groups.dashboard.navigation.run_calendar'), icon: 'i-lucide-calendar-days' })),
            ...direct('resources'),
            ...menu('members', 'i-lucide-users', pick('members', 'availability'), t('groups.dashboard.navigation.community')),
            ...menu('insights', 'i-lucide-chart-no-axes-combined', pick('statistics', 'leaderboard', 'legacy_leaderboard')),
            ...menu('manage', 'i-lucide-settings', pick('manage_resources', 'content_customization', 'membership_applications', 'application_form', 'audit_log', 'settings')),
        ]
    })

    const quickLinkSections = computed<GroupHomeQuickLinkSection[]>(() => [
        {
            key: 'runs', label: t('groups.dashboard.quick_links.runs'), icon: 'i-lucide-calendar-days',
            links: pick('create_run', 'activities').map(link => link.key === 'activities'
                ? { ...link, label: t('groups.dashboard.actions.view_runs') } : link),
        },
        {
            key: 'staff_workspace', label: t('groups.dashboard.quick_links.staff_workspace'), icon: 'i-lucide-layers',
            links: pick('manage_resources', 'content_customization', 'audit_log'),
        },
        {
            key: 'explore', label: t('groups.dashboard.quick_links.explore'), icon: 'i-lucide-compass',
            links: pick('statistics', 'leaderboard', 'resources'),
        },
    ].filter(section => section.links.length > 0))

    return { navigation, quickLinkSections }
}
