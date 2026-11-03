import { computed, nextTick, reactive, ref, watch } from 'vue'
import { router, usePage, useRemember } from '@inertiajs/vue3'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import type { GroupSettingsGroup, GroupSettingsNavigationState, GroupSettingsSection } from '@/Types/GroupSettings'

export function useGroupSettingsNavigation(groupId: number, sections: () => GroupSettingsSection[]) {
    const page = usePage<{ group: GroupSettingsGroup }>()
    const isMobileOrTablet = useBreakpoints(breakpointsTailwind).smaller('xl')
    const navigation = useRemember(reactive<GroupSettingsNavigationState>({
        activeSection: 'general',
        sectionOpen: false,
    }), `groups.${groupId}.settings.navigation`)
    const navigationElement = ref<HTMLElement | null>(null)
    const sectionElement = ref<HTMLElement | null>(null)
    const sectionHeading = ref<HTMLElement | null>(null)
    const availableSections = computed(sections)

    watch([() => page.url, availableSections], ([url, available]) => {
        if (page.component !== 'Dashboard/Groups/Settings/Index' || page.props.group?.id !== groupId) return

        const params = new URLSearchParams(url.split('?')[1]?.split('#')[0] ?? '')
        const section = available.find(section => section === params.get('section'))
        navigation.sectionOpen = Boolean(section)
        if (section) navigation.activeSection = section
        else if (params.has('section') || !available.includes(navigation.activeSection)) navigation.activeSection = 'general'
    }, { immediate: true })

    function sectionUrl(section: GroupSettingsSection | null) {
        const url = new URL(page.url, window.location.origin)
        if (section) url.searchParams.set('section', section)
        else url.searchParams.delete('section')
        return `${url.pathname}${url.search}${url.hash}`
    }

    function openSection(section: GroupSettingsSection) {
        if (!availableSections.value.includes(section)) return

        router.push({
            url: sectionUrl(section), preserveState: true, preserveScroll: true,
            onFinish: async () => {
                await nextTick()
                if (isMobileOrTablet.value) {
                    sectionHeading.value?.focus({ preventScroll: true })
                    sectionElement.value?.scrollIntoView({ block: 'start' })
                } else {
                    sectionHeading.value?.scrollIntoView({ block: 'nearest' })
                }
            },
        })
    }

    function showAllSettings() {
        navigation.sectionOpen = false
        router.push({
            url: sectionUrl(null), preserveState: true, preserveScroll: true,
            onFinish: async () => {
                await nextTick()
                navigationElement.value?.querySelector<HTMLButtonElement>(`[data-settings-section="${navigation.activeSection}"]`)?.focus()
            },
        })
    }

    return { navigation, isMobileOrTablet, navigationElement, sectionElement, sectionHeading, openSection, showAllSettings }
}
