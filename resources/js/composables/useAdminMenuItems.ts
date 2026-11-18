import { usePage } from '@inertiajs/vue3'
import type { DropdownMenuItem } from '@nuxt/ui'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

export function useAdminMenuItems() {
    const page = usePage()
    const { t, locale } = useI18n()

    return computed<DropdownMenuItem[]>(() => page.props.auth?.user?.is_admin ? [
        {
            label: t('navigation.admin_panel.title'),
            icon: 'i-lucide-shield-check',
            to: route('admin.index', { locale: locale.value }, false),
        },
        {
            label: t('navigation.sidebar.pulse'),
            icon: 'i-lucide-activity',
            to: route('pulse'),
            external: true,
        },
    ] : [])
}
