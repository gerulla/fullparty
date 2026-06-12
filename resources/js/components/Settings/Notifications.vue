<script setup lang="ts">
// @ts-ignore
import { useConfirmationModal } from "@/composables/useConfirmationModal";
import type { SettingsUser } from "@/Types/Settings";
import { router, useForm, usePage } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import axios from "axios";
import { route } from "ziggy-js";
import { computed, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	user: SettingsUser
}>();

type ChannelKey = "in_app" | "email" | "discord";
type ExternalChannelKey = "email" | "discord";
type NotificationFormKey =
	| "application_notifications"
	| "run_and_reminder_notifications"
	| "group_update_notifications"
	| "assignment_notifications"
	| "account_character_notifications"
	| "system_notice_notifications";

type ChannelSupport = Record<ChannelKey, boolean>;

type NotificationTopic = {
	key: string
	titleKey: string
	descriptionKey: string
	supports: ChannelSupport
};

type NotificationGroup = {
	key: string
	formKey: NotificationFormKey
	titleKey: string
	descriptionKey: string
	supports: ChannelSupport
	topics: NotificationTopic[]
};

const { t } = useI18n();
const page = usePage();
const toast = useToast();
const confirmationModal = useConfirmationModal();
const linkToken = ref<{ token: string; expires_at: string } | null>(null);
const generatingLinkToken = ref(false);
const selectedNotificationGroupKey = ref<string | null>(null);
const autosaveRequestId = ref(0);
let autosaveTimer: ReturnType<typeof window.setTimeout> | null = null;

const notificationGroups: NotificationGroup[] = [
	{
		key: "applications",
		formKey: "application_notifications",
		titleKey: "settings.notifications.applications",
		descriptionKey: "settings.notifications.applications_description",
		supports: { in_app: true, email: true, discord: true },
		topics: [
			{
				key: "applications.submitted",
				titleKey: "settings.notifications.topics.applications_submitted.title",
				descriptionKey: "settings.notifications.topics.applications_submitted.description",
				supports: { in_app: true, email: true, discord: true },
			},
			{
				key: "applications.review",
				titleKey: "settings.notifications.topics.applications_review.title",
				descriptionKey: "settings.notifications.topics.applications_review.description",
				supports: { in_app: true, email: false, discord: false },
			},
			{
				key: "applications.host_updates",
				titleKey: "settings.notifications.topics.applications_host_updates.title",
				descriptionKey: "settings.notifications.topics.applications_host_updates.description",
				supports: { in_app: true, email: true, discord: true },
			},
			{
				key: "applications.outcomes",
				titleKey: "settings.notifications.topics.applications_outcomes.title",
				descriptionKey: "settings.notifications.topics.applications_outcomes.description",
				supports: { in_app: true, email: true, discord: true },
			},
		],
	},
	{
		key: "assignments",
		formKey: "assignment_notifications",
		titleKey: "settings.notifications.assignments",
		descriptionKey: "settings.notifications.assignments_description",
		supports: { in_app: true, email: true, discord: true },
		topics: [
			{
				key: "assignments.roster",
				titleKey: "settings.notifications.topics.assignments_roster.title",
				descriptionKey: "settings.notifications.topics.assignments_roster.description",
				supports: { in_app: true, email: true, discord: true },
			},
			{
				key: "assignments.bench",
				titleKey: "settings.notifications.topics.assignments_bench.title",
				descriptionKey: "settings.notifications.topics.assignments_bench.description",
				supports: { in_app: true, email: true, discord: true },
			},
			{
				key: "assignments.status",
				titleKey: "settings.notifications.topics.assignments_status.title",
				descriptionKey: "settings.notifications.topics.assignments_status.description",
				supports: { in_app: true, email: true, discord: true },
			},
			{
				key: "assignments.designations",
				titleKey: "settings.notifications.topics.assignments_designations.title",
				descriptionKey: "settings.notifications.topics.assignments_designations.description",
				supports: { in_app: true, email: true, discord: true },
			},
		],
	},
	{
		key: "runs",
		formKey: "run_and_reminder_notifications",
		titleKey: "settings.notifications.runs_and_reminders",
		descriptionKey: "settings.notifications.runs_and_reminders_description",
		supports: { in_app: true, email: true, discord: true },
		topics: [
			{
				key: "runs.reminders",
				titleKey: "settings.notifications.topics.runs_reminders.title",
				descriptionKey: "settings.notifications.topics.runs_reminders.description",
				supports: { in_app: true, email: true, discord: true },
			},
			{
				key: "runs.lifecycle",
				titleKey: "settings.notifications.topics.runs_lifecycle.title",
				descriptionKey: "settings.notifications.topics.runs_lifecycle.description",
				supports: { in_app: true, email: true, discord: true },
			},
		],
	},
	{
		key: "group_updates",
		formKey: "group_update_notifications",
		titleKey: "settings.notifications.group_updates",
		descriptionKey: "settings.notifications.group_updates_description",
		supports: { in_app: true, email: false, discord: false },
		topics: [
			{
				key: "group_updates.run_posts",
				titleKey: "settings.notifications.topics.group_run_posts.title",
				descriptionKey: "settings.notifications.topics.group_run_posts.description",
				supports: { in_app: true, email: false, discord: false },
			},
			{
				key: "group_updates.membership",
				titleKey: "settings.notifications.topics.group_membership.title",
				descriptionKey: "settings.notifications.topics.group_membership.description",
				supports: { in_app: true, email: false, discord: false },
			},
			{
				key: "group_updates.roles",
				titleKey: "settings.notifications.topics.group_roles.title",
				descriptionKey: "settings.notifications.topics.group_roles.description",
				supports: { in_app: true, email: false, discord: false },
			},
		],
	},
	{
		key: "account_character",
		formKey: "account_character_notifications",
		titleKey: "settings.notifications.account_character_updates",
		descriptionKey: "settings.notifications.account_character_updates_description",
		supports: { in_app: true, email: true, discord: true },
		topics: [
			{
				key: "account.connected_accounts",
				titleKey: "settings.notifications.topics.connected_accounts.title",
				descriptionKey: "settings.notifications.topics.connected_accounts.description",
				supports: { in_app: true, email: true, discord: true },
			},
			{
				key: "account.settings",
				titleKey: "settings.notifications.topics.account_settings.title",
				descriptionKey: "settings.notifications.topics.account_settings.description",
				supports: { in_app: true, email: false, discord: false },
			},
			{
				key: "characters.changes",
				titleKey: "settings.notifications.topics.character_changes.title",
				descriptionKey: "settings.notifications.topics.character_changes.description",
				supports: { in_app: true, email: true, discord: true },
			},
			{
				key: "characters.refreshes",
				titleKey: "settings.notifications.topics.character_refreshes.title",
				descriptionKey: "settings.notifications.topics.character_refreshes.description",
				supports: { in_app: true, email: false, discord: false },
			},
		],
	},
	{
		key: "system",
		formKey: "system_notice_notifications",
		titleKey: "settings.notifications.system_notices",
		descriptionKey: "settings.notifications.system_notices_description",
		supports: { in_app: true, email: true, discord: true },
		topics: [
			{
				key: "system.maintenance",
				titleKey: "settings.notifications.topics.system_maintenance.title",
				descriptionKey: "settings.notifications.topics.system_maintenance.description",
				supports: { in_app: true, email: true, discord: true },
			},
			{
				key: "system.announcements",
				titleKey: "settings.notifications.topics.system_announcements.title",
				descriptionKey: "settings.notifications.topics.system_announcements.description",
				supports: { in_app: true, email: true, discord: true },
			},
		],
	},
];

const channelKeys: ChannelKey[] = ["in_app", "email", "discord"];
const formKeys: NotificationFormKey[] = [
	"application_notifications",
	"run_and_reminder_notifications",
	"group_update_notifications",
	"assignment_notifications",
	"account_character_notifications",
	"system_notice_notifications",
];

const form = useForm({
	application_notifications: props.user.application_notifications,
	run_and_reminder_notifications: props.user.run_and_reminder_notifications,
	group_update_notifications: props.user.group_update_notifications,
	assignment_notifications: props.user.assignment_notifications,
	account_character_notifications: props.user.account_character_notifications,
	system_notice_notifications: props.user.system_notice_notifications,
	email_notifications: props.user.email_notifications,
	discord_notifications: props.user.discord_notifications,
});

const hasDiscordIntegration = computed(() => props.user.discord_user_integration !== null);
const hasActiveLinkToken = computed(() => {
	if (linkToken.value) {
		return true;
	}

	if (!props.user.discord_link_token_expires_at) {
		return false;
	}

	return new Date(props.user.discord_link_token_expires_at).getTime() > Date.now();
});
const linkTokenExpiresAt = computed(() => linkToken.value?.expires_at ?? props.user.discord_link_token_expires_at);
const selectedNotificationGroup = computed(() => notificationGroups.find((group) => group.key === selectedNotificationGroupKey.value) ?? null);
const isGranularModalOpen = computed({
	get: () => selectedNotificationGroupKey.value !== null,
	set: (open: boolean) => {
		if (!open) {
			selectedNotificationGroupKey.value = null;
		}
	},
});

const preferenceValue = (topic: NotificationTopic, channel: ChannelKey, fallback: boolean) => (
	props.user.notification_preferences?.[topic.key]?.[channel] ?? fallback
);

const initialTopicChannelStates = (): Record<string, Record<ChannelKey, boolean>> => Object.fromEntries(
	notificationGroups.flatMap((group) => group.topics.map((topic) => [
		topic.key,
		{
			in_app: topic.supports.in_app && preferenceValue(topic, "in_app", Boolean(form[group.formKey])),
			email: topic.supports.email && preferenceValue(topic, "email", props.user.email_notifications && Boolean(form[group.formKey])),
			discord: topic.supports.discord && hasDiscordIntegration.value && preferenceValue(topic, "discord", props.user.discord_notifications && Boolean(form[group.formKey])),
		},
	])),
) as Record<string, Record<ChannelKey, boolean>>;

const topicChannelStates = ref(initialTopicChannelStates());
const initialGroupChannelStates = (): Record<string, Record<ExternalChannelKey, boolean>> => Object.fromEntries(
	notificationGroups.map((group) => [
		group.key,
		{
			email: group.supports.email && group.topics.some((topic) => topic.supports.email && topicChannelStates.value[topic.key]?.email),
			discord: group.supports.discord && group.topics.some((topic) => topic.supports.discord && topicChannelStates.value[topic.key]?.discord),
		},
	]),
) as Record<string, Record<ExternalChannelKey, boolean>>;
const groupChannelStates = ref(initialGroupChannelStates());

const channelLabel = (channel: ChannelKey) => t(`settings.notifications.channels.${channel === "in_app" ? "inApp" : channel}`);

const buildNotificationPreferencesPayload = () => Object.fromEntries(
	notificationGroups.flatMap((group) => group.topics.map((topic) => [
		topic.key,
		Object.fromEntries(
			channelKeys
				.filter((channel) => topic.supports[channel] && (channel !== "discord" || hasDiscordIntegration.value))
				.map((channel) => [channel, Boolean(topicChannelStates.value[topic.key]?.[channel])]),
		),
	])),
);

const syncLegacyChannelFields = () => {
	form.email_notifications = notificationGroups.some((group) => (
		group.supports.email && groupChannelStates.value[group.key]?.email
	));
	form.discord_notifications = hasDiscordIntegration.value && notificationGroups.some((group) => (
		group.supports.discord && groupChannelStates.value[group.key]?.discord
	));
};

const buildNotificationSettingsPayload = () => {
	syncLegacyChannelFields();

	return {
		application_notifications: Boolean(form.application_notifications),
		run_and_reminder_notifications: Boolean(form.run_and_reminder_notifications),
		group_update_notifications: Boolean(form.group_update_notifications),
		assignment_notifications: Boolean(form.assignment_notifications),
		account_character_notifications: Boolean(form.account_character_notifications),
		system_notice_notifications: Boolean(form.system_notice_notifications),
		email_notifications: Boolean(form.email_notifications),
		discord_notifications: Boolean(form.discord_notifications),
		notification_preferences: buildNotificationPreferencesPayload(),
	};
};

const applyServerNotificationState = (notifications: Partial<SettingsUser>) => {
	formKeys.forEach((key) => {
		if (key in notifications) {
			form[key] = Boolean(notifications[key]);
		}
	});

	if ("email_notifications" in notifications) {
		form.email_notifications = Boolean(notifications.email_notifications);
	}

	if ("discord_notifications" in notifications) {
		form.discord_notifications = hasDiscordIntegration.value && Boolean(notifications.discord_notifications);
	}

	if (notifications.notification_preferences) {
		notificationGroups.forEach((group) => {
			group.topics.forEach((topic) => {
				channelKeys.forEach((channel) => {
					if (topic.supports[channel] && topicChannelStates.value[topic.key]) {
						topicChannelStates.value[topic.key][channel] = Boolean(notifications.notification_preferences?.[topic.key]?.[channel]);
					}
				});
			});
		});
	}

	groupChannelStates.value = initialGroupChannelStates();
};

const saveNotificationSettings = async () => {
	const requestId = autosaveRequestId.value + 1;
	autosaveRequestId.value = requestId;

	try {
		const response = await axios.post(route("settings.notifications"), buildNotificationSettingsPayload(), {
			headers: {
				Accept: "application/json",
			},
		});

		if (autosaveRequestId.value !== requestId) {
			return;
		}

		if (response.data?.notifications) {
			applyServerNotificationState(response.data.notifications as Partial<SettingsUser>);
		}

		toast.add({
			title: t("settings.toasts.title"),
			description: t("settings.toasts.notification_updated"),
			color: "success",
			icon: "i-lucide-check",
		});
	} catch {
		if (autosaveRequestId.value !== requestId) {
			return;
		}

		toast.add({
			title: t("general.error"),
			description: t("settings.notifications.autosave_failed"),
			color: "error",
			icon: "i-lucide-triangle-alert",
		});
	}
};

const scheduleAutosave = () => {
	if (autosaveTimer) {
		window.clearTimeout(autosaveTimer);
	}

	autosaveTimer = window.setTimeout(() => {
		void saveNotificationSettings();
	}, 250);
};

const openGranularSettings = (group: NotificationGroup) => {
	selectedNotificationGroupKey.value = group.key;
};

const syncLegacyExternalChannel = (channel: ExternalChannelKey) => {
	const hasEnabledGroup = notificationGroups.some((group) => group.supports[channel] && groupChannelStates.value[group.key]?.[channel]);

	if (channel === "email") {
		form.email_notifications = hasEnabledGroup;

		return;
	}

	form.discord_notifications = hasEnabledGroup && hasDiscordIntegration.value;
};

const setGroupChannel = (group: NotificationGroup, channel: ChannelKey, value: boolean) => {
	if (!group.supports[channel]) {
		return;
	}

	if (channel === "in_app") {
		form[group.formKey] = value;
		group.topics.forEach((topic) => {
			if (topic.supports.in_app) {
				topicChannelStates.value[topic.key].in_app = value;
			}
		});

		scheduleAutosave();

		return;
	}

	if (channel === "discord" && !hasDiscordIntegration.value) {
		return;
	}

	groupChannelStates.value[group.key][channel] = value;
	group.topics.forEach((topic) => {
		if (topic.supports[channel]) {
			topicChannelStates.value[topic.key][channel] = value;
		}
	});

	syncLegacyExternalChannel(channel);
	scheduleAutosave();
};

const setTopicChannel = (group: NotificationGroup, topic: NotificationTopic, channel: ChannelKey, value: boolean) => {
	if (!topic.supports[channel]) {
		return;
	}

	if (channel === "discord" && !hasDiscordIntegration.value) {
		return;
	}

	topicChannelStates.value[topic.key][channel] = value;

	if (channel === "in_app") {
		form[group.formKey] = group.topics.some((item) => item.supports.in_app && topicChannelStates.value[item.key]?.in_app);

		scheduleAutosave();

		return;
	}

	groupChannelStates.value[group.key][channel] = group.topics.some((item) => item.supports[channel] && topicChannelStates.value[item.key]?.[channel]);
	syncLegacyExternalChannel(channel);
	scheduleAutosave();
};

const disconnectDiscord = async () => {
	await confirmationModal.open({
		title: t("settings.notifications.disconnect_discord_modal.title"),
		description: t("settings.notifications.disconnect_discord_modal.description"),
		severity: "warning",
		warningText: t("settings.notifications.disconnect_discord_modal.warning"),
		confirmLabel: t("settings.notifications.disconnect_discord_modal.confirm"),
		confirmIcon: "i-lucide-unplug",
		onConfirm: async ({ patch }) => {
			patch({ confirmLoading: true });

			return await new Promise<boolean>((resolve) => {
				router.delete(route("settings.discord-integration.destroy"), {
					preserveScroll: true,
					onSuccess: () => resolve(true),
					onError: () => resolve(false),
					onFinish: () => patch({ confirmLoading: false }),
				});
			});
		},
	});
};

const generateDiscordLinkToken = () => {
	generatingLinkToken.value = true;

	router.post(route("settings.discord-integration.link-token"), {}, {
		preserveScroll: true,
		onFinish: () => {
			generatingLinkToken.value = false;
		},
	});
};

const copyDiscordLinkToken = async () => {
	if (!linkToken.value) {
		return;
	}

	await navigator.clipboard.writeText(linkToken.value.token);

	toast.add({
		title: t("settings.notifications.discord_link_token_copied"),
		color: "success",
		icon: "i-lucide-copy-check",
	});
};

watch(
	() => page.props.flash?.data?.discord_user_link_token,
	(value) => {
		linkToken.value = (value as { token: string; expires_at: string } | null) ?? null;
	},
	{ immediate: true },
);

onUnmounted(() => {
	if (autosaveTimer) {
		window.clearTimeout(autosaveTimer);
	}
});
</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-row items-center font-semibold text-md">
				<UIcon name="i-lucide-bell" class="mr-2" size="22" />
				<p>{{ t("settings.notifications.title") }}</p>
			</div>
		</template>

		<div class="w-full flex flex-col items-stretch gap-5 mb-4">
			<div class="section">
				<div class="section-heading">
					<p class="font-semibold text-toned">{{ t("settings.notifications.discord_app_title") }}</p>
					<p class="text-sm text-muted">{{ t("settings.notifications.discord_app_description") }}</p>
				</div>

				<div :class="hasDiscordIntegration ? 'discord-card' : 'discord-card-muted'">
					<div class="flex min-w-0 items-center gap-3">
						<div class="flex size-11 shrink-0 items-center justify-center border border-white/10 bg-brand-500/12 text-brand-200">
							<UIcon name="ic:baseline-discord" class="size-6" />
						</div>
						<div class="min-w-0">
							<div class="flex flex-wrap items-center gap-2">
								<p class="font-semibold text-toned">
									{{ hasDiscordIntegration ? t("settings.notifications.discord_app_connected_title") : t("settings.notifications.discord_app_not_connected_title") }}
								</p>
								<UBadge
									:color="hasDiscordIntegration ? 'success' : 'neutral'"
									variant="soft"
									size="sm"
									:label="hasDiscordIntegration ? t('settings.connected') : t('settings.not_connected')"
								/>
							</div>
							<p class="mt-1 text-sm text-muted">
								{{ hasDiscordIntegration ? t("settings.notifications.discord_app_connected_description") : t("settings.notifications.discord_app_not_connected_description") }}
							</p>
							<p v-if="hasDiscordIntegration && user.discord_user_integration" class="mt-2 truncate text-xs text-muted">
								{{ user.discord_user_integration.global_name || user.discord_user_integration.username || user.discord_user_integration.discord_user_id }}
							</p>
						</div>
					</div>

					<div class="flex flex-wrap justify-end gap-2">
						<template v-if="!hasDiscordIntegration">
							<UButton
								:to="route('discord-app.user.redirect')"
								size="sm"
								color="neutral"
								variant="subtle"
								icon="ic:baseline-discord"
								:label="t('settings.notifications.install_discord_app')"
							/>
							<UButton
								size="sm"
								color="neutral"
								variant="subtle"
								icon="i-lucide-key-round"
								:loading="generatingLinkToken"
								:label="t('settings.notifications.generate_discord_link_token')"
								@click="generateDiscordLinkToken"
							/>
						</template>
						<UButton
							v-else
							size="sm"
							color="error"
							variant="subtle"
							icon="i-lucide-unplug"
							:label="t('settings.notifications.disconnect_discord')"
							@click="disconnectDiscord"
						/>
					</div>
				</div>

				<div
					v-if="!hasDiscordIntegration && (linkToken || hasActiveLinkToken)"
					class="border border-brand-400/25 bg-brand-500/10 px-4 py-3"
				>
					<div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
						<div class="min-w-0">
							<p class="text-sm font-semibold text-toned">{{ t("settings.notifications.discord_link_token_title") }}</p>
							<p v-if="linkToken" class="mt-1 break-all font-mono text-base font-semibold text-highlighted">{{ linkToken.token }}</p>
							<p class="mt-1 text-xs text-muted">
								{{ t(
									linkToken
										? "settings.notifications.discord_link_token_expires"
										: "settings.notifications.discord_link_token_active",
									{ date: linkTokenExpiresAt ? new Date(linkTokenExpiresAt).toLocaleString() : "" },
								) }}
							</p>
						</div>
						<UButton
							v-if="linkToken"
							size="xs"
							color="neutral"
							variant="soft"
							icon="i-lucide-copy"
							:label="t('settings.notifications.copy_discord_link_token')"
							@click="copyDiscordLinkToken"
						/>
					</div>
				</div>
			</div>

			<div class="section">
				<div class="section-heading">
					<p class="font-semibold text-toned">{{ t("settings.notifications.categories_title") }}</p>
					<p class="text-sm text-muted">{{ t("settings.notifications.categories_description") }}</p>
				</div>

				<div class="notification-table">
					<div class="notification-table-header">
						<span />
						<span>{{ channelLabel("in_app") }}</span>
						<span>{{ channelLabel("email") }}</span>
						<span>{{ channelLabel("discord") }}</span>
						<span />
					</div>

					<div
						v-for="group in notificationGroups"
						:key="group.key"
						class="notification-row"
					>
						<div class="notification-copy">
							<p class="font-semibold">{{ t(group.titleKey) }}</p>
							<p class="text-sm text-muted">{{ t(group.descriptionKey) }}</p>
						</div>

						<div class="channel-cell" :data-label="channelLabel('in_app')">
							<USwitch
								:model-value="Boolean(form[group.formKey])"
								@update:model-value="setGroupChannel(group, 'in_app', Boolean($event))"
							/>
						</div>

						<div class="channel-cell" :data-label="channelLabel('email')">
							<USwitch
								v-if="group.supports.email"
								:model-value="Boolean(groupChannelStates[group.key]?.email)"
								@update:model-value="setGroupChannel(group, 'email', Boolean($event))"
							/>
							<span v-else class="unsupported-mark">-</span>
						</div>

						<div class="channel-cell" :data-label="channelLabel('discord')">
							<USwitch
								v-if="group.supports.discord"
								:model-value="Boolean(groupChannelStates[group.key]?.discord)"
								:disabled="!hasDiscordIntegration"
								@update:model-value="setGroupChannel(group, 'discord', Boolean($event))"
							/>
							<span v-else class="unsupported-mark">-</span>
						</div>

						<div class="channel-cell justify-end" data-label="">
							<UButton
								color="neutral"
								variant="ghost"
								size="sm"
								icon="i-lucide-settings"
								class="rounded-none"
								:aria-label="t('settings.notifications.customize')"
								@click="openGranularSettings(group)"
							/>
						</div>
					</div>
				</div>
			</div>

		</div>

		<UModal
			v-model:open="isGranularModalOpen"
			:title="selectedNotificationGroup ? t(selectedNotificationGroup.titleKey) : ''"
			:description="t('settings.notifications.customize_description')"
			:ui="{ content: 'rounded-sm', header: 'border-0' }"
		>
			<template #body>
				<div v-if="selectedNotificationGroup" class="space-y-3">
					<div class="granular-header">
						<span />
						<span>{{ channelLabel("in_app") }}</span>
						<span>{{ channelLabel("email") }}</span>
						<span>{{ channelLabel("discord") }}</span>
					</div>

					<div
						v-for="topic in selectedNotificationGroup.topics"
						:key="topic.key"
						class="granular-row"
					>
						<div class="min-w-0">
							<div class="flex flex-wrap items-center gap-2">
								<p class="font-semibold text-toned">{{ t(topic.titleKey) }}</p>
							</div>
							<p class="mt-1 text-sm text-muted">{{ t(topic.descriptionKey) }}</p>
						</div>

						<div
							v-for="channel in channelKeys"
							:key="`${topic.key}-${channel}`"
							class="channel-cell"
							:data-label="channelLabel(channel)"
						>
							<USwitch
								v-if="topic.supports[channel]"
								:model-value="Boolean(topicChannelStates[topic.key]?.[channel])"
								:disabled="channel === 'discord' && !hasDiscordIntegration"
								@update:model-value="setTopicChannel(selectedNotificationGroup, topic, channel, Boolean($event))"
							/>
							<span v-else class="unsupported-mark">-</span>
						</div>
					</div>
				</div>
			</template>
		</UModal>
	</UCard>
</template>

<style scoped>
@reference "../../../css/app.css";

.section {
	@apply flex flex-col gap-4;
}

.section-heading {
	@apply flex flex-col gap-1 pb-1;
}

.discord-card,
.discord-card-muted {
	@apply flex flex-col gap-4 border px-4 py-3 md:flex-row md:items-center md:justify-between;
}

.discord-card {
	@apply border-default bg-default/30 dark:border-white/20 dark:bg-white/5;
}

.discord-card-muted {
	@apply border-default/70 bg-muted/20 dark:border-white/15 dark:bg-white/3;
}

.notification-table {
	@apply overflow-hidden border border-default dark:border-white/20;
}

.notification-table-header,
.notification-row {
	@apply grid grid-cols-[minmax(0,1fr)_4.5rem_4.5rem_4.5rem_3rem] items-center gap-3;
}

.notification-table-header {
	@apply border-b border-default bg-muted/30 px-4 py-2 text-center text-xs font-semibold uppercase tracking-[0.12em] text-muted dark:border-white/10 dark:bg-white/5;
}

.notification-row {
	@apply border-b border-default bg-default/25 px-4 py-3 last:border-b-0 dark:border-white/10 dark:bg-white/3;
}

.notification-copy {
	@apply min-w-0;
}

.channel-cell {
	@apply flex items-center justify-center;
}

.unsupported-mark {
	@apply text-sm text-muted/60;
}

.granular-header,
.granular-row {
	@apply grid grid-cols-[minmax(0,1fr)_4.5rem_4.5rem_4.5rem] items-center gap-3;
}

.granular-header {
	@apply text-center text-xs font-semibold uppercase tracking-[0.12em] text-muted;
}

.granular-row {
	@apply border border-default bg-default/25 px-4 py-3 dark:border-white/15 dark:bg-white/5;
}

@media (max-width: 767px) {
	.notification-table-header {
		@apply hidden;
	}

	.notification-row {
		@apply gap-4;
		grid-template-columns: repeat(3, minmax(0, 1fr)) 2.5rem;
	}

	.notification-copy {
		@apply col-span-4;
	}

	.granular-header {
		@apply hidden;
	}

	.granular-row {
		@apply gap-4;
		grid-template-columns: repeat(3, minmax(0, 1fr));
	}

	.granular-row > :first-child {
		@apply col-span-3;
	}

	.channel-cell {
		@apply flex-col items-start justify-start gap-2;
	}

	.channel-cell::before {
		@apply text-xs font-semibold uppercase tracking-[0.12em] text-muted;
		content: attr(data-label);
	}

	.channel-cell[data-label=""] {
		@apply items-end justify-end;
	}

	.channel-cell[data-label=""]::before {
		content: none;
	}
}
</style>
