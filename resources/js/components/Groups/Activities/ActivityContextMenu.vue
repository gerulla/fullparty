<script setup lang="ts">
import type { ContextMenuItem } from "@nuxt/ui";
import { computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { useToast } from "@nuxt/ui/composables";
import type { ActivityIndexItem } from "@/Types/ActivityCore";
import { canAcceptActivityApplications, isArchivedActivityStatus } from "@/utils/activityLifecycle";
import { utcToDiscordTimestamp } from "@/utils/discordTimestamp";
import { localizedValue } from "@/utils/localizedValue";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";

const props = defineProps<{
	groupSlug: string
	canManageActivities: boolean
	activity: ActivityIndexItem
}>();

const { t, locale } = useI18n();
const page = usePage();
const toast = useToast();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? "en"));
const { withDisplayTimeZone } = useTimeDisplayMode();

const activityTypeName = computed(() => (
	localizedValue(props.activity.activity_type?.draft_name, locale.value, fallbackLocale.value)
		|| props.activity.activity_type?.slug
		|| t("groups.activities.cards.unknown_type")
));

const activityTitle = computed(() => props.activity.title || activityTypeName.value);
const discordTimestamp = computed(() => props.activity.starts_at ? utcToDiscordTimestamp(props.activity.starts_at) : null);
const canCopyRunLink = computed(() => true);
const canOpenOverview = computed(() => true);
const canOpenManagement = computed(() => props.canManageActivities);
const canEditRun = computed(() => props.canManageActivities && !isArchivedActivityStatus(props.activity.status));
const canCopyApplicationLink = computed(() => (
	props.activity.needs_application
	&& canAcceptActivityApplications(props.activity.status, props.activity.starts_at)
));

const overviewRouteParameters = computed(() => ({
	group: props.groupSlug,
	activity: props.activity.id,
}));

const applicationRouteParameters = computed(() => ({
	group: props.groupSlug,
	activity: props.activity.id,
}));

const copyWithFeedback = async (
	value: string | null,
	successTitleKey: string,
	successDescriptionKey: string,
	errorTitleKey: string,
	errorDescriptionKey: string,
) => {
	if (!value) {
		return;
	}

	try {
		await navigator.clipboard.writeText(value);

		toast.add({
			title: t(successTitleKey),
			description: t(successDescriptionKey),
			color: "success",
		});
	} catch {
		toast.add({
			title: t(errorTitleKey),
			description: t(errorDescriptionKey),
			color: "error",
		});
	}
};

const copyDiscordTimestamp = async () => {
	await copyWithFeedback(
		discordTimestamp.value,
		"groups.activities.context_menu.copy_discord_timestamp_success_title",
		"groups.activities.context_menu.copy_discord_timestamp_success_description",
		"groups.activities.context_menu.copy_discord_timestamp_error_title",
		"groups.activities.context_menu.copy_discord_timestamp_error_description",
	);
};

const copyRunLink = async () => {
	if (!canCopyRunLink.value) {
		return;
	}

	await copyWithFeedback(
		`${window.location.origin}${route("groups.activities.overview", overviewRouteParameters.value, false)}`,
		"groups.activities.context_menu.copy_run_link_success_title",
		"groups.activities.context_menu.copy_run_link_success_description",
		"groups.activities.context_menu.copy_run_link_error_title",
		"groups.activities.context_menu.copy_run_link_error_description",
	);
};

const copyApplicationLink = async () => {
	if (!canCopyApplicationLink.value) {
		return;
	}

	await copyWithFeedback(
		`${window.location.origin}${route("groups.activities.application", applicationRouteParameters.value, false)}`,
		"groups.activities.context_menu.copy_application_link_success_title",
		"groups.activities.context_menu.copy_application_link_success_description",
		"groups.activities.context_menu.copy_application_link_error_title",
		"groups.activities.context_menu.copy_application_link_error_description",
	);
};

const formatDateLabel = () => {
	if (!props.activity.starts_at) {
		return t("groups.activities.cards.no_time");
	}

	return createDateTimeFormatter(locale.value, withDisplayTimeZone({
		year: "numeric",
		month: "2-digit",
		day: "2-digit",
	})).format(new Date(props.activity.starts_at));
};

const formatTimeLabel = () => {
	if (!props.activity.starts_at) {
		return t("groups.activities.cards.no_time");
	}

	return createDateTimeFormatter(locale.value, withDisplayTimeZone({
		hour: "2-digit",
		minute: "2-digit",
	})).format(new Date(props.activity.starts_at));
};

const durationLabel = computed(() => (
	props.activity.duration_hours
		? t("groups.activities.management.overview.duration", { count: props.activity.duration_hours })
		: t("groups.activities.management.overview.no_duration")
));

const hostLabel = computed(() => (
	props.activity.organized_by?.name || t("groups.activities.cards.no_organizer")
));

const activityInfoSummary = computed(() => ([
	`${t("groups.activities.context_menu.summary_labels.title")}: ${activityTitle.value}`,
	`${t("groups.activities.context_menu.summary_labels.type")}: ${activityTypeName.value}`,
	`${t("groups.activities.context_menu.summary_labels.status")}: ${t(`groups.activities.statuses.${props.activity.status}`)}`,
	`${t("groups.activities.context_menu.summary_labels.date")}: ${formatDateLabel()}`,
	`${t("groups.activities.context_menu.summary_labels.time")}: ${formatTimeLabel()}`,
	`${t("groups.activities.context_menu.summary_labels.duration")}: ${durationLabel.value}`,
	`${t("groups.activities.context_menu.summary_labels.host")}: ${hostLabel.value}`,
	`${t("groups.activities.context_menu.summary_labels.datacenter")}: ${props.activity.datacenter || t("groups.dashboard.labels.not_available")}`,
	`${t("groups.activities.context_menu.summary_labels.slots")}: ${props.activity.slot_count}`,
	`${t("groups.activities.context_menu.summary_labels.applications")}: ${props.activity.application_count}`,
].join("\n")));

const copyActivityInfo = async () => {
	await copyWithFeedback(
		activityInfoSummary.value,
		"groups.activities.context_menu.copy_info_success_title",
		"groups.activities.context_menu.copy_info_success_description",
		"groups.activities.context_menu.copy_info_error_title",
		"groups.activities.context_menu.copy_info_error_description",
	);
};

const goToManagementPage = () => {
	if (!canOpenManagement.value) {
		return;
	}

	router.get(route("groups.dashboard.activities.show", {
		group: props.groupSlug,
		activity: props.activity.id,
	}));
};

const goToOverviewPage = () => {
	if (!canOpenOverview.value) {
		return;
	}

	router.get(route("groups.activities.overview", overviewRouteParameters.value));
};

const goToEditPage = () => {
	if (!canEditRun.value) {
		return;
	}

	router.get(route("groups.dashboard.activities.edit", {
		group: props.groupSlug,
		activity: props.activity.id,
	}));
};

const exportRoster = () => {
	if (!props.canManageActivities) {
		return;
	}

	window.location.href = route("groups.dashboard.activities.export-roster", {
		group: props.groupSlug,
		activity: props.activity.id,
	});
};

const contextMenuItems = computed<ContextMenuItem[][]>(() => {
	const navigationItems: ContextMenuItem[] = [
		...(canOpenManagement.value
			? [{
				label: t("groups.activities.context_menu.open_management"),
				icon: "i-lucide-settings-2",
				onSelect: goToManagementPage,
			}]
			: []),
		...(canOpenOverview.value
			? [{
				label: t("groups.activities.context_menu.open_overview"),
				icon: "i-lucide-external-link",
				onSelect: goToOverviewPage,
			}]
			: []),
	];

	const copyItems: ContextMenuItem[] = [
		{
			label: t("groups.activities.context_menu.copy_discord_timestamp"),
			icon: "i-lucide-clock-3",
			disabled: !discordTimestamp.value,
			onSelect: copyDiscordTimestamp,
		},
		{
			label: t("groups.activities.context_menu.copy_run_link"),
			icon: "i-lucide-link",
			disabled: !canCopyRunLink.value,
			onSelect: copyRunLink,
		},
		{
			label: t("groups.activities.context_menu.copy_info"),
			icon: "i-lucide-clipboard-copy",
			onSelect: copyActivityInfo,
		},
	];

	const applicationItems: ContextMenuItem[] = canCopyApplicationLink.value
		? [{
			label: t("groups.activities.context_menu.copy_application_link"),
			icon: "i-lucide-file-pen-line",
			onSelect: copyApplicationLink,
		}]
		: [];

	const managementItems: ContextMenuItem[] = props.canManageActivities
		? [
			...(canEditRun.value
				? [{
					label: t("groups.activities.context_menu.edit_run"),
					icon: "i-lucide-pencil",
					onSelect: goToEditPage,
				}]
				: []),
			{
				label: t("groups.activities.context_menu.export_excel"),
				icon: "i-lucide-download",
				onSelect: exportRoster,
			},
		]
		: [];

	return [
		...(navigationItems.length > 0 ? [navigationItems] : []),
		copyItems,
		...(applicationItems.length > 0 ? [applicationItems] : []),
		...(managementItems.length > 0 ? [managementItems] : []),
	];
});
</script>

<template>
	<UContextMenu :items="contextMenuItems">
		<slot />
	</UContextMenu>
</template>
