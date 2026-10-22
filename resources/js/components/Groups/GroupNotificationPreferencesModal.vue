<script setup lang="ts">
import type { GroupNotificationPreferences, NotificationPreferenceChannel } from "@/Types/Groups";
import { GROUP_NOTIFICATION_TOPIC_KEYS } from "@/utils/groupNotifications";
import { useForm } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { route } from "ziggy-js";

type NotificationChoice = "inherit" | "on" | "off";

type GroupNotificationSettingsGroup = {
	slug: string
	name: string
	notifications: {
		enabled: boolean
		preferences?: GroupNotificationPreferences
	}
};

const props = defineProps<{
	open: boolean
	group: GroupNotificationSettingsGroup
}>();

const emit = defineEmits<{
	"update:open": [value: boolean]
	saved: []
}>();

const { t } = useI18n();

const isOpen = computed({
	get: () => props.open,
	set: (value: boolean) => emit("update:open", value),
});

const topics = [
	{
		key: GROUP_NOTIFICATION_TOPIC_KEYS[0],
		titleKey: "settings.notifications.topics.group_run_posts.title",
		descriptionKey: "settings.notifications.topics.group_run_posts.description",
	},
	{
		key: GROUP_NOTIFICATION_TOPIC_KEYS[1],
		titleKey: "settings.notifications.topics.group_membership.title",
		descriptionKey: "settings.notifications.topics.group_membership.description",
	},
	{
		key: GROUP_NOTIFICATION_TOPIC_KEYS[2],
		titleKey: "settings.notifications.topics.group_roles.title",
		descriptionKey: "settings.notifications.topics.group_roles.description",
	},
] as const;

const channel: NotificationPreferenceChannel = "in_app";

const choiceItems = computed(() => [
	{
		label: t("groups.notifications.preferences.inherit"),
		value: "inherit",
	},
	{
		label: t("groups.notifications.preferences.on"),
		value: "on",
	},
	{
		label: t("groups.notifications.preferences.off"),
		value: "off",
	},
]);

const choiceFor = (topic: string): NotificationChoice => {
	if (!props.group.notifications.enabled) {
		return "off";
	}

	const value = props.group.notifications.preferences?.[topic]?.[channel];

	if (value === true) {
		return "on";
	}

	if (value === false) {
		return "off";
	}

	return "inherit";
};

const choices = ref<Record<string, NotificationChoice>>({});

const resetChoices = () => {
	choices.value = Object.fromEntries(topics.map((topic) => [topic.key, choiceFor(topic.key)]));
};

watch(
	() => [props.group.slug, props.group.notifications.enabled, props.group.notifications.preferences],
	resetChoices,
	{ immediate: true },
);

const form = useForm({
	enabled: props.group.notifications.enabled,
	notification_preferences: {} as Record<string, Record<NotificationPreferenceChannel, boolean | null>>,
});

watch(
	() => props.group.notifications.enabled,
	(enabled) => {
		form.enabled = enabled;
	},
	{ immediate: true },
);

const preferencePayload = () => Object.fromEntries(
	topics.map((topic) => [
		topic.key,
		{
			[channel]: choices.value[topic.key] === "inherit"
				? null
				: choices.value[topic.key] === "on",
		},
	]),
);

const hasAnyEnabledChoice = () => Object.values(choices.value).some((choice) => choice !== "off");

const submit = () => {
	form.enabled = hasAnyEnabledChoice();
	form.notification_preferences = preferencePayload();

	form.patch(route("groups.notifications.update", props.group.slug), {
		preserveState: true,
		onSuccess: () => {
			isOpen.value = false;
			emit("saved");
		},
	});
};
</script>

<template>
	<UModal
		v-model:open="isOpen"
		:title="t('groups.notifications.preferences.title')"
		:description="t('groups.notifications.preferences.description', { group: group.name })"
		:ui="{ content: 'rounded-sm', header: 'border-0' }"
	>
		<template #body>
			<form class="space-y-4" @submit.prevent="submit">
				<div
					v-for="topic in topics"
					:key="topic.key"
					class="flex flex-col gap-3 rounded-lg bg-elevation-1 px-4 py-3 shadow-md sm:flex-row sm:items-center sm:justify-between dark:bg-white/5 dark:shadow-none"
				>
					<div class="min-w-0">
						<p class="font-semibold text-toned">{{ t(topic.titleKey) }}</p>
						<p class="mt-1 text-sm text-muted">{{ t(topic.descriptionKey) }}</p>
					</div>
					<USelect
						v-model="choices[topic.key]"
						:items="choiceItems"
						class="w-full sm:w-36"
					/>
				</div>

				<div class="flex justify-end gap-2">
					<UButton
						type="button"
						color="neutral"
						variant="ghost"
						:label="t('general.cancel')"
						@click="isOpen = false"
					/>
					<UButton
						type="submit"
						color="neutral"
						:loading="form.processing"
						:label="t('groups.notifications.preferences.save')"
					/>
				</div>
			</form>
		</template>
	</UModal>
</template>
