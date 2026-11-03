import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { GroupDashboardGroup } from '@/Types/Groups'

const languageLabels: Record<string, string> = {
    en: 'English', de: 'Deutsch', fr: 'Français', ja: '日本語',
}

export function useGroupInfo(group: () => GroupDashboardGroup) {
    const { t } = useI18n()
    const languagesText = computed(() => (group().preferred_languages ?? [])
        .map(value => languageLabels[value] ?? value.toUpperCase()).join(', '))
    const locationText = computed(() => group().region
        ? `${group().datacenter} · ${group().region}` : group().datacenter)
    const descriptionText = computed(() => group().description || t('groups.common.states.no_description_long'))
    const primaryBadges = computed(() => [
        group().badge_meta.experience_expectation,
        group().badge_meta.voice_expectation,
        ...(group().badge_meta.primary_focuses ?? []),
    ].filter((badge): badge is NonNullable<typeof badge> => badge != null))
    const tagBadges = computed(() => group().badge_meta.tags ?? [])
    const primaryFocusValues = computed(() => new Set((group().primary_focuses ?? []).map(String)))

    const activeDaysText = computed(() => (group().active_days ?? [])
        .map(day => t(`groups.common.active_days.${day}`)).join(', '))
    const activeHoursText = computed(() => {
        const { active_start_time: start, active_end_time: end } = group()
        return start && end ? `${start.slice(0, 5)} - ${end.slice(0, 5)}` : null
    })
    const activityWindowText = computed(() => {
        if (!group().active_timezone || !activeDaysText.value) return null
        return [activeDaysText.value, activeHoursText.value, group().active_timezone].filter(Boolean).join(' · ')
    })

    const resolveBadgeLabel = (value: string) => {
        if (value === group().experience_expectation) return t(`groups.index.create_modal.fields.experience_expectation.options.${value}`)
        if (value === group().voice_expectation) return t(`groups.common.voice_expectations.${value}`)
        if (primaryFocusValues.value.has(value)) return t(`groups.index.create_modal.fields.primary_focuses.options.${value}`)
        return value
    }

    return { languagesText, locationText, descriptionText, primaryBadges, tagBadges, activeDaysText, activeHoursText, activityWindowText, resolveBadgeLabel }
}
