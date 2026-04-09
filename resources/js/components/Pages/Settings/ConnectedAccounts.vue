<script setup>
import {useI18n} from "vue-i18n";
import {computed} from "vue";
import {router} from "@inertiajs/vue3";
import {route} from 'ziggy-js'
const props = defineProps({
	user: Object
})

const provider_names = computed(() => {
	return props.user.social_accounts.map(account => account.provider)
});

const getProviderIdentification = (provider_name) => {
	const provider = props.user.social_accounts.find(account => account.provider === provider_name);
	if(!provider) return null;
	return provider.provider_name ? provider.provider_name : provider.provider_email;
}

const { t } = useI18n();
</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-row items-center font-semibold text-md">
				<UIcon name="i-lucide-globe" class="mr-2" size="22"/>
				<p>{{ t('settings.connected_accounts') }}</p>
			</div>
		</template>

		<div class="w-full flex flex-col items-start gap-4">
			<div class="social-block">
				<div class="social-icon bg-primary/10">
					<UIcon name="i-lucide-mail" size="28" class="text-primary-500"/>
				</div>
				<div class="social-info">
					<p class="font-semibold">{{t('settings.account.email')}}</p>
					<p class="text-sm font-muted">{{ user.email }}</p>
				</div>
				<div class="social-action">
					<UBadge color="success" variant="soft" class="rounded-none" size="lg">{{ t('settings.connected') }}</UBadge>
				</div>
			</div>
			<div class="social-block">
				<div class="social-icon bg-[#5865F2]/10 text-[#5865F2]">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-8 w-8" aria-hidden="true">
						<path fill="currentColor" d="M20.3 4.37A19.8 19.8 0 0 0 15.4 2.9a13.8 13.8 0 0 0-.63 1.28a18.3 18.3 0 0 0-5.54 0a13.8 13.8 0 0 0-.63-1.28A19.74 19.74 0 0 0 3.7 4.37C.7 8.9-.1 13.32.3 17.68a19.9 19.9 0 0 0 6 3.02a14.5 14.5 0 0 0 1.28-2.1a12.9 12.9 0 0 1-2.02-.98c.17-.12.34-.25.5-.38c3.9 1.83 8.12 1.83 11.98 0c.17.14.34.26.5.38c-.64.39-1.32.72-2.03.98c.37.74.8 1.44 1.29 2.1a19.87 19.87 0 0 0 6-3.02c.48-5.05-.82-9.43-3.5-13.31ZM8.68 14.96c-1.17 0-2.13-1.08-2.13-2.4c0-1.33.94-2.4 2.13-2.4c1.2 0 2.14 1.08 2.13 2.4c0 1.32-.94 2.4-2.13 2.4Zm6.64 0c-1.17 0-2.13-1.08-2.13-2.4c0-1.33.94-2.4 2.13-2.4c1.2 0 2.14 1.08 2.13 2.4c0 1.32-.93 2.4-2.13 2.4Z"/>
					</svg>
				</div>
				<div class="social-info">
					<p class="font-semibold">Discord</p>
					<p class="text-sm font-muted">{{ provider_names.includes("discord") ? getProviderIdentification('discord') : t('settings.not_connected')}}</p>
				</div>
				<div class="social-action">
					<UBadge v-if="provider_names.includes('discord')" color="success" variant="soft" class="rounded-none" size="lg">{{ t('settings.connected') }}</UBadge>
					<UButton :to="route('discord.redirect')" v-else color="neutral" icon="i-lucide-link" size="lg">{{ t('settings.connect') }}</UButton>
				</div>
			</div>
			<div class="social-block">
				<div class="social-icon bg-[#EA4335]/10">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" class="h-8 w-8" aria-hidden="true">
						<path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.3 36 24 36c-6.6 0-12-5.4-12-12S17.4 12 24 12c3 0 5.7 1.1 7.8 2.9l5.7-5.7C33.9 6.1 29.2 4 24 4C12.95 4 4 12.95 4 24s8.95 20 20 20 20-8.95 20-20c0-1.34-.14-2.65-.4-3.5z"/>
						<path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 18.9 12 24 12c3 0 5.7 1.1 7.8 2.9l5.7-5.7C33.9 6.1 29.2 4 24 4c-7.7 0-14.3 4.3-17.7 10.7z"/>
						<path fill="#4CAF50" d="M24 44c5.1 0 9.8-2 13.3-5.2l-6.1-5.2C29.2 35.1 26.7 36 24 36c-5.3 0-9.7-3.3-11.4-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
						<path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-1.1 3.1-3.3 5.3-6.1 6.8l6.1 5.2C38.9 36.7 44 31 44 24c0-1.34-.14-2.65-.4-3.5z"/>
					</svg>
				</div>
				<div class="social-info">
					<p class="font-semibold">Google</p>
					<p class="text-sm font-muted">{{ provider_names.includes("google") ? getProviderIdentification('google') : t('settings.not_connected')}}</p>
				</div>
				<div class="social-action">
					<UBadge v-if="provider_names.includes('google')" color="success" variant="soft" class="rounded-none" size="lg">{{ t('settings.connected') }}</UBadge>
					<UButton :to="route('google.redirect')" v-else color="neutral" icon="i-lucide-link" size="lg">{{ t('settings.connect') }}</UButton>
				</div>
			</div>
			<div class="social-block">
				<div class="social-icon bg-blue-500/10">
					<UIcon name="i-lucide-globe" size="28" class="text-blue-500"/>
				</div>
				<div class="social-info">
					<p class="font-semibold">XIVAuth</p>
					<p class="text-sm font-muted">{{ provider_names.includes("xivauth") ? getProviderIdentification('xivauth') : t('settings.not_connected')}}</p>
				</div>
				<div class="social-action">
					<UBadge v-if="provider_names.includes('xivauth')" color="success" variant="soft" class="rounded-none" size="lg">{{ t('settings.connected') }}</UBadge>
					<UButton :to="route('xivauth.redirect')" v-else color="neutral" icon="i-lucide-link" size="lg">{{ t('settings.connect') }}</UButton>
				</div>
			</div>
		</div>
	</UCard>
</template>

<style scoped>
@reference "../../../../css/app.css";
.social-block {
	@apply w-full flex flex-row items-stretch p-4 border border-neutral-200 dark:border-neutral-700 rounded-sm gap-4
}

.social-icon {
	@apply h-12 w-12 rounded-sm flex items-center justify-center
}
.social-info {
	@apply flex flex-col items-start
}

.social-action {
	@apply flex items-center ml-auto
}
</style>