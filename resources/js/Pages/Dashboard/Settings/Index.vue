<script setup lang="ts">
import {useI18n} from "vue-i18n";
import PageHeader from "@/components/PageHeader.vue";
import AccountSettings from "@/components/Pages/Settings/AccountSettings.vue";
import ConnectedAccounts from "@/components/Pages/Settings/ConnectedAccounts.vue";
import Notifications from "@/components/Pages/Settings/Notifications.vue";
import PrivacySecurity from "@/components/Pages/Settings/PrivacySecurity.vue";
import {useForm, usePage} from "@inertiajs/vue3";
import {computed, watch} from "vue";
import {useToast} from "@nuxt/ui/composables";

const { t } = useI18n();

const page = usePage()
const toast = useToast()
const user = computed(() => page.props.auth?.user)

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
		if(success.includes('notification_settings_updated')){
			toast.add({
				title: t('settings.toasts.title'),
				description: t('settings.toasts.notification_updated'),
				color: 'success',
				icon: 'i-lucide-check'
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
	},
	{ immediate: true }
)
</script>

<template>
	<div class="w-full bg-neutral-100 dark:bg-neutral-900">
		<PageHeader :title="t('settings.title')" :subtitle="t('settings.subtitle')" />

		<div class="w-full flex flex-col items-start mt-4 gap-8">
			<AccountSettings :user="user"/>
			<ConnectedAccounts :user="user" />
			<Notifications :user="user" />
			<PrivacySecurity :user="user" />
		</div>
	</div>
</template>

<style scoped>

</style>