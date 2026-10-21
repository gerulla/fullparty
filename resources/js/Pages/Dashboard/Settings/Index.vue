<script setup lang="ts">
import type { SettingsHomepageGroup, SettingsLinkedSession, SettingsNavigationState, SettingsSection, SettingsUser } from "@/Types/Settings";
import {settingsSections} from "@/Types/Settings";
import {useI18n} from "vue-i18n";
import V2Layout from "@/Layouts/V2Layout.vue";
import AccountSettings from "@/components/Settings/AccountSettings.vue";
import ActiveLinkedSessions from "@/components/Settings/ActiveLinkedSessions.vue";
import FfxivPluginSetup from "@/components/Settings/FfxivPluginSetup.vue";
import ConnectedAccounts from "@/components/Settings/ConnectedAccounts.vue";
import HomepagePreference from "@/components/Settings/HomepagePreference.vue";
import Notifications from "@/components/Settings/Notifications.vue";
import PrivacySecurity from "@/components/Settings/PrivacySecurity.vue";
import PasswordSettings from "@/components/Settings/PasswordSettings.vue";
import DiscordIntegration from "@/components/Settings/DiscordIntegration.vue";
import DeleteAccount from "@/components/Settings/DeleteAccount.vue";
import SettingsNavigation from "@/components/Settings/SettingsNavigation.vue";
import {router, usePage, useRemember} from "@inertiajs/vue3";
import {computed, nextTick, reactive, ref, watch} from "vue";
import {breakpointsTailwind, useBreakpoints} from "@vueuse/core";
import {useToast} from "@nuxt/ui/composables";
import {route} from "ziggy-js";

defineOptions({ layout: V2Layout })

const { t, locale } = useI18n();
const breadcrumbs = computed(() => [
    { label: t('navigation.sidebar.dashboard'), icon: 'i-lucide-house', to: route('dashboard', { locale: locale.value }), ui: { linkLabel: 'sr-only' } },
    { label: t('navigation.sidebar.account') },
    { label: t('settings.title') },
])

const props = defineProps<{
	activeLinkedSessions: SettingsLinkedSession[]
	homepageGroups: SettingsHomepageGroup[]
}>()

const page = usePage()
const toast = useToast()
const user = computed(() => page.props.auth?.user as SettingsUser)
const isMobileOrTablet = useBreakpoints(breakpointsTailwind).smaller('xl')
const navigation = useRemember(reactive<SettingsNavigationState>({
    activeSection: 'general',
    sectionOpen: false,
}), 'settings.navigation') as SettingsNavigationState
const sectionHeading = ref<HTMLElement | null>(null)
const sectionElement = ref<HTMLElement | null>(null)
const sectionNavigation = ref<InstanceType<typeof SettingsNavigation> | null>(null)

function sectionUrl(section: SettingsSection | null) {
    const url = new URL(page.url, window.location.origin)
    if (section === null) url.searchParams.delete('section')
    else url.searchParams.set('section', section)
    return `${url.pathname}${url.search}${url.hash}`
}

watch(() => page.url, (url) => {
    if (page.component !== 'Dashboard/Settings/Index') return

    const params = new URLSearchParams(url.split('?')[1]?.split('#')[0] ?? '')
    const section = settingsSections.find(section => section === params.get('section'))
    if (section) {
        navigation.activeSection = section
        navigation.sectionOpen = true
    } else if (params.has('section')) {
        navigation.activeSection = 'general'
        navigation.sectionOpen = false
    } else if (navigation.sectionOpen) {
        // Settings saves redirect to the base URL; keep the current section after saving.
        router.replace({ url: sectionUrl(navigation.activeSection), preserveState: true, preserveScroll: true })
    }
}, { immediate: true })

function openSection(section: SettingsSection) {
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
            sectionNavigation.value?.focusSection(navigation.activeSection)
        },
    })
}

watch(
	() => page.props.flash?.success,
	(success) => {
		if (!success) return

		if (success.includes('username_updated')) {
			toast.add({
				title: t('settings.toasts.title'),
				description: t('settings.toasts.username_updated'),
				color: 'success',
				icon: 'i-lucide-check'
			})
		}
		if (success.includes('profile_picture_updated')) {
			toast.add({
				title: t('settings.toasts.title'),
				description: t('settings.toasts.profile_picture_updated'),
				color: 'success',
				icon: 'i-lucide-check'
			})
		}
		if(success.includes('notification_settings_updated')){
			toast.add({
				title: t('settings.toasts.title'),
				description: t('settings.toasts.notification_updated'),
				color: 'success',
				icon: 'i-lucide-check'
			})
		}
		if(success.includes('notification_preferences_reviewed')){
			toast.add({
				title: t('settings.toasts.title'),
				description: t('settings.toasts.notification_preferences_reviewed'),
				color: 'success',
				icon: 'i-lucide-check'
			})
		}
		if(success.includes('discord_integration_disconnected')){
			toast.add({
				title: t('settings.toasts.title'),
				description: t('settings.toasts.discord_integration_disconnected'),
				color: 'success',
				icon: 'i-lucide-check'
			})
		}
		if(success.includes('discord_user_link_token_generated')){
			toast.add({
				title: t('settings.toasts.title'),
				description: t('settings.toasts.discord_user_link_token_generated'),
				color: 'success',
				icon: 'i-lucide-key-round'
			})
		}
		if(success.includes('privacy_settings_updated')){
			toast.add({
				title: t('settings.toasts.title'),
				description: t('settings.toasts.privacy_updated'),
				color: 'success',
				icon: 'i-lucide-check'
			})
		}
		if(success.includes('social_account_unlinked')){
			toast.add({
				title: t('settings.toasts.title'),
				description: t('settings.toasts.social_account_unlinked'),
				color: 'success',
				icon: 'i-lucide-check'
			})
		}
		if(success.includes('password_updated')){
			toast.add({
				title: t('settings.toasts.title'),
				description: t('settings.toasts.password_updated'),
				color: 'success',
				icon: 'i-lucide-check'
			})
		}
		if(success.includes('linked_session_revoked')){
			toast.add({
				title: t('settings.toasts.title'),
				description: t('settings.toasts.linked_session_revoked'),
				color: 'success',
				icon: 'i-lucide-unplug'
			})
		}
	},
	{ immediate: true }
)

watch(
	() => page.props.errors?.error,
	(error) => {
		if (!error) return

		if (error === 'social_account_unlink_last_login_method') {
			toast.add({
				title: t('settings.toasts.title'),
				description: t('settings.toasts.social_account_unlink_blocked'),
				color: 'error',
				icon: 'i-lucide-triangle-alert'
			})
		}

		if (error === 'social_oauth_invalid_state') {
			toast.add({
				title: t('settings.toasts.error_title'),
				description: t('settings.toasts.social_oauth_invalid_state'),
				color: 'error',
				icon: 'i-lucide-triangle-alert'
			})
		}
	},
	{ immediate: true }
)
</script>

<template>
	<div class="w-full">
        <UBreadcrumb :items="breadcrumbs" color="brand"
            :ui="{ link: 'text-xs', linkLeadingIcon: 'size-4', separatorIcon: 'size-3' }" />
        <div class="mt-4 grid w-full items-start gap-6 xl:grid-cols-[20rem_minmax(0,1fr)] xl:gap-8">
            <aside
                class="min-w-0 xl:sticky xl:top-[calc(var(--ui-header-height)+1rem)] xl:max-h-[calc(100dvh-var(--ui-header-height)-2rem)] xl:overflow-y-auto xl:p-1"
                :class="navigation.sectionOpen ? 'hidden xl:block' : 'block'"
            >
                <SettingsNavigation ref="sectionNavigation"
                    :active-section="isMobileOrTablet ? null : navigation.activeSection" @select="openSection" />
            </aside>

            <section id="settings-section" ref="sectionElement" aria-labelledby="settings-section-title" class="min-w-0 scroll-mt-24"
                :class="navigation.sectionOpen ? 'block' : 'hidden xl:block'">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 id="settings-section-title" ref="sectionHeading" tabindex="-1"
                        class="min-w-0 scroll-mt-24 break-words text-xl font-semibold focus:outline-none">
                        {{ t(`settings.navigation.sections.${navigation.activeSection}.title`) }}
                    </h2>
                    <UButton class="shrink-0 xl:hidden" color="neutral" variant="ghost" icon="i-lucide-arrow-left"
                        :label="t('settings.navigation.back')" @click="showAllSettings" />
                </div>

                <!-- Keep forms mounted so switching sections preserves unsaved edits and pending saves. -->
                <div v-show="navigation.activeSection === 'general'" class="space-y-6">
                    <AccountSettings :user="user" />
                    <HomepagePreference :user="user" :groups="props.homepageGroups" />
                </div>
                <ConnectedAccounts v-show="navigation.activeSection === 'socials'" :user="user" />
                <Notifications v-show="navigation.activeSection === 'notifications'" :user="user" />
                <div v-show="navigation.activeSection === 'privacy'" class="space-y-6">
                    <PrivacySecurity :user="user" />
                    <PasswordSettings />
                </div>
                <DiscordIntegration v-show="navigation.activeSection === 'discord'" :user="user" />
                <div v-show="navigation.activeSection === 'plugin'" class="space-y-6">
                    <FfxivPluginSetup />
                    <ActiveLinkedSessions :sessions="props.activeLinkedSessions" />
                </div>
                <DeleteAccount v-show="navigation.activeSection === 'delete'" />
            </section>
        </div>
	</div>
</template>
