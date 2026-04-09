<script setup lang="ts">
import {useI18n} from "vue-i18n";
import {useForm} from "@inertiajs/vue3";
import {route} from "ziggy-js";
const props = defineProps({
	user: Object
})
const { t } = useI18n();

const form = useForm({
	run_reminders: props.user.run_reminders,
	application_notifications: props.user.application_notifications,
	group_updates: props.user.group_updates,
	assignment_updates: props.user.assignment_updates,
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
			<div class="option">
				<div>
					<p class="font-semibold">{{ t('settings.notifications.run_reminders') }}</p>
					<p class="text-sm">{{ t('settings.notifications.run_reminders_description') }}</p>
				</div>
				<UCheckbox v-model="form.run_reminders" />
			</div>
			<div class="option">
				<div>
					<p class="font-semibold">{{ t('settings.notifications.application_notifications') }}</p>
					<p class="text-sm">{{ t('settings.notifications.application_notifications_description') }}</p>
				</div>
				<UCheckbox v-model="form.application_notifications"/>
			</div>
			<div class="option">
				<div>
					<p class="font-semibold">{{ t('settings.notifications.group_updates') }}</p>
					<p class="text-sm">{{ t('settings.notifications.group_updates_description') }}</p>
				</div>
				<UCheckbox v-model="form.group_updates"/>
			</div>
			<div class="option">
				<div>
					<p class="font-semibold">{{ t('settings.notifications.assignment_updates') }}</p>
					<p class="text-sm">{{ t('settings.notifications.assignment_updates_description') }}</p>
				</div>
				<UCheckbox v-model="form.assignment_updates"/>
			</div>
			<div class="option">
				<div>
					<p class="font-semibold">{{ t('settings.notifications.email_notifications') }}</p>
					<p class="text-sm">{{ t('settings.notifications.email_notifications_description') }}</p>
				</div>
				<UCheckbox v-model="form.email_notifications" />
			</div>
			<div :class="hasProvider('discord') ? 'option' : 'option-muted'">
				<div>
					<p class="font-semibold">{{ t('settings.notifications.discord_notifications') }}</p>
					<p class="text-sm">{{ t('settings.notifications.discord_notifications_description') }}</p>
				</div>
				<UCheckbox v-model="form.discord_notifications" :disabled="!hasProvider('discord')"/>
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
</style>