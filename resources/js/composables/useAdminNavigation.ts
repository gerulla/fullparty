import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { AdminNavigationLink, AdminNavigationSection } from '@/Types/AdminNavigation'

export function useAdminNavigation() {
    const page = usePage()
    const { t } = useI18n()
    const link = (key: string, name: string, icon: string, patterns: string[] = [name]): AdminNavigationLink => {
        // Recompute active links on Inertia navigation and preserve the selected locale.
        page.url
        return {
            label: t(key), icon,
            to: route(name, { locale: String(page.props.locale?.current ?? 'en') }),
            active: patterns.some(pattern => Boolean(route().current(pattern))),
        }
    }
    const overview = computed(() => link('navigation.admin_panel.overview', 'admin.index', 'i-lucide-layout-dashboard'))
    const sections = computed<AdminNavigationSection[]>(() => [
        {
            label: t('navigation.admin_panel.moderation'), icon: 'i-lucide-shield-check',
            links: [link('reports.admin.title', 'admin.reports.index', 'i-lucide-flag', ['admin.reports.*'])],
        },
        {
            label: t('navigation.admin_panel.content_data'), icon: 'i-lucide-database',
            links: [
                link('navigation.sidebar.activity_types', 'admin.activity-types.index', 'i-lucide-file-pen', ['admin.activity-types.*']),
                link('navigation.sidebar.character_definitions', 'admin.character-data', 'i-lucide-user-pen', ['admin.character-data', 'admin.characters.*', 'admin.character-classes.*', 'admin.phantom-jobs.*']),
                link('navigation.sidebar.system_data', 'admin.system-data', 'i-lucide-database', ['admin.system-data', 'admin.raid-positions.*', 'admin.bozja-items.*']),
            ],
        },
        {
            label: t('navigation.admin_panel.community'), icon: 'i-lucide-users',
            links: [
                link('navigation.sidebar.system_notifications', 'admin.system-notifications.index', 'i-lucide-megaphone', ['admin.system-notifications.*']),
                link('navigation.sidebar.featured_groups', 'admin.featured-groups.index', 'i-lucide-sparkles', ['admin.featured-groups.*']),
            ],
        },
        {
            label: t('navigation.sidebar.integrations'), icon: 'i-lucide-plug-zap',
            links: [
                link('navigation.sidebar.integrations', 'admin.integrations.index', 'i-lucide-plug-zap', ['admin.integrations.*']),
                link('navigation.sidebar.discord_guild_links', 'admin.discord-guild-links.index', 'i-lucide-server-cog', ['admin.discord-guild-links.*']),
                link('navigation.sidebar.fflogs_playground', 'admin.fflogs-playground.index', 'i-lucide-square-terminal', ['admin.fflogs-playground.*']),
            ],
        },
        {
            label: t('navigation.sidebar.quotas'), icon: 'i-lucide-gauge',
            links: [link('navigation.sidebar.quotas', 'admin.quotas.index', 'i-lucide-gauge', ['admin.quotas.*'])],
        },
        {
            label: t('navigation.sidebar.admin_audit_log'), icon: 'i-lucide-scroll-text',
            links: [link('navigation.sidebar.admin_audit_log', 'admin.audit-log', 'i-lucide-scroll-text')],
        },
    ])
    const items = computed(() => [overview.value, ...sections.value.map(section => section.links.length === 1 ? section.links[0] : {
        label: section.label, icon: section.icon, active: section.links.some(item => item.active), children: section.links,
    })])
    const mobileItems = computed(() => [[overview.value], ...sections.value.map(section => section.links)])
    const current = computed(() => [overview.value, ...sections.value.flatMap(section => section.links)].find(item => item.active) ?? overview.value)

    return { overview, sections, items, mobileItems, current }
}
