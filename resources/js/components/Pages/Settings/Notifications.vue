<script setup lang="ts">
import {useI18n} from "vue-i18n";
import {useForm} from "@inertiajs/vue3";
import {route} from "ziggy-js";
const props = defineProps({
	user: Object
})
const { t } = useI18n();

const form = useForm({
	application_notifications: props.user.application_notifications,
	run_and_reminder_notifications: props.user.run_and_reminder_notifications,
	group_update_notifications: props.user.group_update_notifications,
	assignment_notifications: props.user.assignment_notifications,
	account_character_notifications: props.user.account_character_notifications,
	system_notice_notifications: props.user.system_notice_notifications,
	email_notifications: props.user.email_notifications,
	discord_notifications: props.user.discord_notifications,
})

const hasProvider = (provider_name) => {
	return !!props.user.social_accounts.find(account => account.provider === provider_name);
}

function submit() {
	form.post(route('settings.notifications'));
}

</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-row items-center font-semibold text-md">
				<UIcon name="i-lucide-bell" class="mr-2" size="22"/>
				<p>{{ t('settings.notifications.title') }}</p>
			</div>
		</template>
		<form @submit.prevent="submit" class="w-full flex flex-col items-stretch gap-4 mb-4">
			<div class="section">
				<div class="section-heading">
					<p class="font-semibold text-toned">{{ t('settings.notifications.categories_title') }}</p>
					<p class="text-sm text-muted">{{ t('settings.notifications.categories_description') }}</p>
				</div>

				<div class="option">
					<div>
						<p class="font-semibold">{{ t('settings.notifications.applications') }}</p>
						<p class="text-sm">{{ t('settings.notifications.applications_description') }}</p>
					</div>
					<USwitch v-model="form.application_notifications" />
				</div>

				<div class="option">
					<div>
						<p class="font-semibold">{{ t('settings.notifications.assignments') }}</p>
						<p class="text-sm">{{ t('settings.notifications.assignments_description') }}</p>
					</div>
					<USwitch v-model="form.assignment_notifications" />
				</div>

				<div class="option">
					<div>
						<p class="font-semibold">{{ t('settings.notifications.runs_and_reminders') }}</p>
						<p class="text-sm">{{ t('settings.notifications.runs_and_reminders_description') }}</p>
					</div>
					<USwitch v-model="form.run_and_reminder_notifications" />
				</div>

				<div class="option">
					<div>
						<p class="font-semibold">{{ t('settings.notifications.group_updates') }}</p>
						<p class="text-sm">{{ t('settings.notifications.group_updates_description') }}</p>
					</div>
					<USwitch v-model="form.group_update_notifications" />
				</div>

				<div class="option">
					<div>
						<p class="font-semibold">{{ t('settings.notifications.account_character_updates') }}</p>
						<p class="text-sm">{{ t('settings.notifications.account_character_updates_description') }}</p>
					</div>
					<USwitch v-model="form.account_character_notifications" />
				</div>

				<div class="option">
					<div>
						<p class="font-semibold">{{ t('settings.notifications.system_notices') }}</p>
						<p class="text-sm">{{ t('settings.notifications.system_notices_description') }}</p>
					</div>
					<USwitch v-model="form.system_notice_notifications" />
				</div>
			</div>

			<div class="section">
				<div class="section-heading">
					<p class="font-semibold text-toned">{{ t('settings.notifications.delivery_title') }}</p>
					<p class="text-sm text-muted">{{ t('settings.notifications.delivery_description') }}</p>
				</div>

				<div class="option">
					<div>
						<p class="font-semibold">{{ t('settings.notifications.email_notifications') }}</p>
						<p class="text-sm">{{ t('settings.notifications.email_notifications_description') }}</p>
					</div>
					<USwitch v-model="form.email_notifications" />
				</div>

				<div :class="hasProvider('discord') ? 'option' : 'option-muted'">
					<div>
						<p class="font-semibold">{{ t('settings.notifications.discord_notifications') }}</p>
						<p class="text-sm">{{ t('settings.notifications.discord_notifications_description') }}</p>
					</div>
					<USwitch v-model="form.discord_notifications" :disabled="!hasProvider('discord')" />
				</div>
			</div>

			<div class="m-0 p-0">
				<UButton type="submit" :label="t('settings.notifications.save')" size="lg" color="neutral"/>
			</div>
		</form>

	</UCard>
</template>

<style scoped>
@reference "../../../../css/app.css";
.option {
	@apply w-full flex flex-row items-center justify-between;
}

.option-muted {
	@apply w-full flex flex-row items-center justify-between text-muted cursor-not-allowed;
}

.section {
	@apply flex flex-col gap-4;
}

.section-heading {
	@apply flex flex-col gap-1 pb-1;
}
</style>
