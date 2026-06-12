<script setup lang="ts">
import type { SettingsSocialAccount, SettingsUser } from "@/Types/Settings";
import { router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	user: SettingsUser
}>();

const { t } = useI18n();

const providerNames = computed(() => props.user.social_accounts.map((account) => account.provider));

const getProviderAccount = (providerName: string): SettingsSocialAccount | null => {
	return props.user.social_accounts.find((account) => account.provider === providerName) ?? null;
};

const getProviderIdentification = (providerName: string) => {
	const provider = getProviderAccount(providerName);

	if (!provider) {
		return null;
	}

	return provider.provider_name ? provider.provider_name : provider.provider_email;
};

const unlinkModalOpen = ref(false);
const isUnlinking = ref(false);
const unlinkTarget = ref<SettingsSocialAccount | null>(null);

const promptUnlink = (providerName: string) => {
	const provider = getProviderAccount(providerName);

	if (!provider) {
		return;
	}

	unlinkTarget.value = provider;
	unlinkModalOpen.value = true;
};

const unlinkSocialAccount = () => {
	if (!unlinkTarget.value) {
		return;
	}

	isUnlinking.value = true;

	router.delete(route('settings.social-accounts.destroy', unlinkTarget.value.id), {
		preserveScroll: true,
		onFinish: () => {
			isUnlinking.value = false;
			unlinkModalOpen.value = false;
			unlinkTarget.value = null;
		},
	});
};

const providerDisplayName = computed(() => {
	if (!unlinkTarget.value) {
		return '';
	}

	return unlinkTarget.value.provider === 'xivauth'
		? 'XIVAuth'
		: unlinkTarget.value.provider.charAt(0).toUpperCase() + unlinkTarget.value.provider.slice(1);
});
</script>

<template>
	<UCard class="h-full w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-row items-center font-semibold text-md">
				<UIcon name="i-lucide-globe" class="mr-2" size="22" />
				<p>{{ t('settings.connected_accounts') }}</p>
			</div>
		</template>

		<div class="w-full flex flex-col items-start gap-4">
			<div class="social-block">
				<div class="social-icon bg-primary/10">
					<UIcon name="i-lucide-mail" size="28" class="text-primary-500" />
				</div>
				<div class="social-info">
					<p class="font-semibold">{{ t('general.email') }}</p>
					<p class="text-sm font-muted">{{ props.user.email }}</p>
				</div>
				<div class="social-action">
					<UBadge color="success" variant="soft" class="rounded-none" size="lg">{{ t('settings.connected') }}</UBadge>
				</div>
			</div>

			<div class="social-block">
				<div class="social-icon bg-[#5865F2]/10 text-[#5865F2]">
					<UIcon name="ic:baseline-discord" class="h-8 w-8" />
				</div>
				<div class="social-info">
					<p class="font-semibold">Discord</p>
					<p class="text-sm font-muted">{{ providerNames.includes('discord') ? getProviderIdentification('discord') : t('settings.not_connected') }}</p>
				</div>
				<div class="social-action gap-2">
					<template v-if="providerNames.includes('discord')">
						<UBadge color="success" variant="soft" class="rounded-none" size="lg">{{ t('settings.connected') }}</UBadge>
						<UButton color="error" variant="ghost" icon="i-lucide-unlink" size="lg" @click="promptUnlink('discord')">
							{{ t('settings.unlink') }}
						</UButton>
					</template>
					<UButton :to="route('discord.redirect')" v-else color="neutral" icon="i-lucide-link" size="lg">{{ t('settings.connect') }}</UButton>
				</div>
			</div>

			<div class="social-block">
				<div class="social-icon bg-[#EA4335]/10">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" class="h-8 w-8" aria-hidden="true">
						<path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.3 36 24 36c-6.6 0-12-5.4-12-12S17.4 12 24 12c3 0 5.7 1.1 7.8 2.9l5.7-5.7C33.9 6.1 29.2 4 24 4C12.95 4 4 12.95 4 24s8.95 20 20 20 20-8.95 20-20c0-1.34-.14-2.65-.4-3.5z" />
						<path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 18.9 12 24 12c3 0 5.7 1.1 7.8 2.9l5.7-5.7C33.9 6.1 29.2 4 24 4c-7.7 0-14.3 4.3-17.7 10.7z" />
						<path fill="#4CAF50" d="M24 44c5.1 0 9.8-2 13.3-5.2l-6.1-5.2C29.2 35.1 26.7 36 24 36c-5.3 0-9.7-3.3-11.4-8l-6.5 5C9.5 39.6 16.2 44 24 44z" />
						<path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-1.1 3.1-3.3 5.3-6.1 6.8l6.1 5.2C38.9 36.7 44 31 44 24c0-1.34-.14-2.65-.4-3.5z" />
					</svg>
				</div>
				<div class="social-info">
					<p class="font-semibold">Google</p>
					<p class="text-sm font-muted">{{ providerNames.includes('google') ? getProviderIdentification('google') : t('settings.not_connected') }}</p>
				</div>
				<div class="social-action gap-2">
					<template v-if="providerNames.includes('google')">
						<UBadge color="success" variant="soft" class="rounded-none" size="lg">{{ t('settings.connected') }}</UBadge>
						<UButton color="error" variant="ghost" icon="i-lucide-unlink" size="lg" @click="promptUnlink('google')">
							{{ t('settings.unlink') }}
						</UButton>
					</template>
					<UButton :to="route('google.redirect')" v-else color="neutral" icon="i-lucide-link" size="lg">{{ t('settings.connect') }}</UButton>
				</div>
			</div>

			<div class="social-block">
				<div class="social-icon bg-blue-500/10">
					<UIcon name="i-lucide-globe" size="28" class="text-blue-500" />
				</div>
				<div class="social-info">
					<p class="font-semibold">XIVAuth</p>
					<p class="text-sm font-muted">{{ providerNames.includes('xivauth') ? getProviderIdentification('xivauth') : t('settings.not_connected') }}</p>
				</div>
				<div class="social-action gap-2">
					<template v-if="providerNames.includes('xivauth')">
						<UBadge color="success" variant="soft" class="rounded-none" size="lg">{{ t('settings.connected') }}</UBadge>
						<UButton color="error" variant="ghost" icon="i-lucide-unlink" size="lg" @click="promptUnlink('xivauth')">
							{{ t('settings.unlink') }}
						</UButton>
					</template>
					<UButton :to="route('xivauth.redirect')" v-else color="neutral" icon="i-lucide-link" size="lg">{{ t('settings.connect') }}</UButton>
				</div>
			</div>
		</div>

		<UModal
			v-model:open="unlinkModalOpen"
			:title="t('settings.unlink_social_account_title')"
			:description="t('settings.unlink_social_account_description', { provider: providerDisplayName })"
		>
			<template #body>
				<p class="text-sm text-toned">
					{{ t('settings.unlink_social_account_warning') }}
				</p>
			</template>

			<template #footer>
				<div class="flex w-full justify-end gap-2">
					<UButton color="neutral" variant="ghost" @click="unlinkModalOpen = false">
						{{ t('settings.unlink_social_account_cancel') }}
					</UButton>
					<UButton color="error" variant="solid" :loading="isUnlinking" @click="unlinkSocialAccount">
						{{ t('settings.unlink_social_account_confirm') }}
					</UButton>
				</div>
			</template>
		</UModal>
	</UCard>
</template>

<style scoped>
@reference "../../../css/app.css";

.social-block {
	@apply w-full grid items-center p-4 border border-neutral-200 dark:border-neutral-700 rounded-sm gap-4;
	grid-template-columns: auto minmax(0, 1fr) auto;
}

.social-icon {
	@apply h-12 w-12 rounded-sm flex items-center justify-center;
}

.social-info {
	@apply flex min-w-0 flex-col items-start;
}

.social-action {
	@apply flex flex-wrap items-center justify-end;
}

@media (max-width: 430px) {
	.social-block {
		grid-template-areas:
			"info icon"
			"action icon";
		grid-template-columns: minmax(0, 1fr) auto;
	}

	.social-icon {
		grid-area: icon;
	}

	.social-info {
		grid-area: info;
	}

	.social-action {
		grid-area: action;
		@apply justify-start;
	}
}
</style>
