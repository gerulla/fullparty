import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { localizedValue } from '@/utils/localizedValue'
import type { GroupDashboardActivity } from '@/Types/Groups'

export function useGroupRunLabels() {
    const page = usePage()
    const { locale, t } = useI18n()
    const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'))

    const typeName = (activity: GroupDashboardActivity) => localizedValue(
        activity.activity_type.draft_name, locale.value, fallbackLocale.value,
    ) || activity.activity_type.slug || t('groups.activities.cards.unknown_type')

    const title = (activity: GroupDashboardActivity) => activity.title || typeName(activity)
    const target = (activity: GroupDashboardActivity) => localizedValue(
        activity.target_prog_point_label, locale.value, fallbackLocale.value,
    ) || activity.target_prog_point_key

    return { typeName, title, target }
}
