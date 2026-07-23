<script setup lang="ts">
import { computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import SeoHead from "@/components/Shared/SeoHead.vue";
import PageHeader from "@/components/PageHeader.vue";
import ActivityAttendeeRosterBoard from "@/components/Groups/Activities/ActivityAttendeeRosterBoard.vue";
import ActivityCompletionSummaryPanel from "@/components/Groups/Activities/ActivityCompletionSummaryPanel.vue";
import AddToCalendarMenu from "@/components/Groups/Activities/AddToCalendarMenu.vue";
import ActivityOverviewInfoPanel from "@/components/Groups/Activities/ActivityOverviewInfoPanel.vue";
import ActivityRosterSummaryPanel from "@/components/Groups/Activities/ActivityRosterSummaryPanel.vue";
import { localizedValue } from "@/utils/localizedValue";
import { getActivityStatusMeta } from "@/utils/activityStatusMeta";
import { isArchivedActivityStatus } from "@/utils/activityLifecycle";
import { buildActivityCompletionSummary } from "@/utils/buildActivityCompletionSummary";
import type { ActivityOverviewPermissions, AttendeeActivity, PublicGroupSummary } from "@/Types/ActivityAttendee";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import { formatRelativeTime } from "@/utils/formatRelativeTime";
import { useMinuteTicker } from "@/composables/useMinuteTicker";

const props = defineProps<{
	group: PublicGroupSummary
	activity: AttendeeActivity
	permissions: ActivityOverviewPermissions
	secretKey?: string | null
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? "en"));
const localTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const relativeTimeTick = useMinuteTicker();

const activityTypeName = computed(() => {
	return localizedValue(props.activity.activity_type?.draft_name, locale.value, fallbackLocale.value)
		|| props.activity.activity_type?.slug
		|| t("groups.activities.cards.unknown_type");
});

const activityTitle = computed(() => props.activity.title || activityTypeName.value);
const statusMeta = computed(() => getActivityStatusMeta(props.activity.status));
const seoDescription = computed(() => props.activity.description
	|| t("meta.seo.activities.overview_description", {
		title: activityTitle.value,
		group: props.group.name,
	}));
const seoStructuredData = computed(() => ({
	"@context": "https://schema.org",
	"@type": "Event",
	name: activityTitle.value,
	description: seoDescription.value,
	startDate: props.activity.starts_at || undefined,
	eventAttendanceMode: "https://schema.org/OnlineEventAttendanceMode",
	eventStatus: props.activity.status === "cancelled"
		? "https://schema.org/EventCancelled"
		: props.activity.status === "complete"
			? "https://schema.org/EventCompleted"
			: "https://schema.org/EventScheduled",
	organizer: {
		"@type": "Organization",
		name: props.group.name,
	},
	image: props.activity.banner_image_url || props.activity.small_image_url || undefined,
	url: route("groups.activities.overview", applicationRouteParameters.value),
}));
const completedProgression = computed(() => buildActivityCompletionSummary({
	activity: props.activity,
	locale: locale.value,
	fallbackLocale: fallbackLocale.value,
	t,
}));
const showApplicationButton = computed(() => (
	props.activity.needs_application
	&& (props.permissions.can_apply || props.permissions.can_apply_as_guest)
	&& !isArchivedActivityStatus(props.activity.status)
));
const mainSlots = computed(() => props.activity.slots.filter((slot) => !slot.is_bench && !slot.is_fill_in));
const benchSlots = computed(() => props.activity.slots.filter((slot) => slot.is_bench));
const assignedMainSlotCount = computed(() => mainSlots.value.filter((slot) => slot.assigned_character_id !== null).length);

const serverStartsAtLabel = computed(() => {
	if (!props.activity.starts_at) {
		return t("groups.activities.cards.no_time");
	}

	return createDateTimeFormatter(locale.value, {
		weekday: "long",
		day: "numeric",
		month: "long",
		hour: "2-digit",
		minute: "2-digit",
		timeZone: "UTC",
	}).format(new Date(props.activity.starts_at));
});

const localStartsAtLabel = computed(() => {
	if (!props.activity.starts_at) {
		return t("groups.activities.cards.no_time");
	}

	return createDateTimeFormatter(locale.value, {
		weekday: "long",
		day: "numeric",
		month: "long",
		hour: "2-digit",
		minute: "2-digit",
		timeZoneName: "short",
	}).format(new Date(props.activity.starts_at));
});

const relativeStartsAtLabel = computed(() => {
	return formatRelativeTime(
		props.activity.starts_at,
		locale.value,
		t("notifications.just_now"),
		t("groups.activities.cards.no_relative_time"),
		relativeTimeTick.value,
	);
});

const durationLabel = computed(() => {
	if (!props.activity.duration_hours) {
		return t("groups.activities.overview.meta.no_duration");
	}

	return t("groups.activities.management.overview.duration", { count: props.activity.duration_hours });
});

const targetProgPointLabel = computed(() => {
	if (props.activity.target_prog_point_label) {
		return localizedValue(props.activity.target_prog_point_label, locale.value, fallbackLocale.value)
			|| props.activity.target_prog_point_key
			|| t("groups.activities.overview.details.no_target_prog_point");
	}

	return props.activity.target_prog_point_key
		|| t("groups.activities.overview.details.no_target_prog_point");
});

const difficultyLabel = computed(() => props.activity.difficulty
	? t(`groups.activities.difficulties.${props.activity.difficulty}`)
	: "—");

const runStyleLabel = computed(() => props.activity.run_style
	? t(`groups.activities.run_styles.${props.activity.run_style}`)
	: "—");

const intensityLabel = computed(() => props.activity.intensity
	? t(`groups.activities.intensities.${props.activity.intensity}`)
	: "—");

const minimumItemLevelLabel = computed(() => props.activity.min_item_level
	? String(props.activity.min_item_level)
	: t("groups.activities.overview.details.no_min_item_level"));

const beginnerFriendlyLabel = computed(() => t(
	props.activity.beginner_friendly
		? "general.yes"
		: "general.no"
));

const organizerLabel = computed(() => (
	props.activity.organized_by_character?.name
	|| props.activity.organized_by?.name
	|| t("groups.activities.cards.no_organizer")
));
const cancellationAlertDescription = computed(() => (
	props.activity.cancellation_reason
		|| t("groups.activities.overview.cancelled_alert.description")
));

const applicationRouteParameters = computed(() => ({
	group: props.group.slug,
	activity: props.activity.id,
	secretKey: props.secretKey || undefined,
}));
const overviewUrl = computed(() => route("groups.activities.overview", applicationRouteParameters.value));
const calendarUrl = computed(() => route("groups.activities.calendar", applicationRouteParameters.value));

const goBack = () => {
	router.get(route("groups.dashboard.activities.index", {
		group: props.group.slug,
	}));
};

const goToApplicationPage = () => {
	if (!showApplicationButton.value) {
		return;
	}

	router.get(route("groups.activities.application", applicationRouteParameters.value));
};

const goToManagementPage = () => {
	router.get(route("groups.dashboard.activities.show", {
		group: props.group.slug,
		activity: props.activity.id,
	}));
};
</script>

<template>
	<div class="w-full overflow-x-hidden">
		<SeoHead
			:title="activityTitle"
			:description="seoDescription"
			:image="activity.banner_image_url || activity.small_image_url"
			og-type="event"
			:structured-data="seoStructuredData"
		/>

		<UButton
			:label="t('groups.activities.back')"
			icon="i-lucide-arrow-left"
			variant="ghost"
			color="neutral"
			@click.stop="goBack"
		/>

		<UAlert
			v-if="activity.status === 'cancelled'"
			class="mt-4"
			color="error"
			variant="soft"
			icon="i-lucide-ban"
			:title="t('groups.activities.overview.cancelled_alert.title')"
		>
			<template #description>
				<p class="whitespace-pre-wrap text-sm">
					{{ cancellationAlertDescription }}
				</p>
			</template>
		</UAlert>

		<PageHeader
			class="mt-4"
			:title="activityTitle"
			:subtitle="t('groups.activities.overview.subtitle', { group: group.name, type: activityTypeName })"
		>
			<div class="flex flex-wrap items-center justify-center gap-2 xl:justify-end">
				<UBadge
					size="md"
					variant="subtle"
					class="min-w-44 justify-center py-2"
					:color="statusMeta.color"
					:icon="statusMeta.icon"
					:label="t(`groups.activities.statuses.${activity.status}`)"
				/>
				<UBadge
					v-if="activity.allow_guest_applications"
					size="md"
					color="primary"
					variant="subtle"
					icon="i-lucide-user-plus"
					:label="t('groups.activities.overview.details.guest_applications')"
				/>
				<AddToCalendarMenu
					:title="activityTitle"
					:group-name="group.name"
					:starts-at="activity.starts_at"
					:duration-hours="activity.duration_hours"
					:status="activity.status"
					:overview-url="overviewUrl"
					:ics-url="calendarUrl"
					:enabled="group.features.calendar_sync_enabled"
					:notes="activity.notes"
					:progress-point="targetProgPointLabel"
				/>
				<UButton
					v-if="showApplicationButton"
					color="primary"
					icon="i-lucide-file-pen-line"
					:label="t('groups.activities.overview.open_application')"
					@click="goToApplicationPage"
				/>
				<UButton
					v-if="permissions.can_manage"
					color="neutral"
					variant="outline"
					icon="i-lucide-settings-2"
					:label="t('groups.activities.overview.go_to_management')"
					@click="goToManagementPage"
				/>
			</div>
		</PageHeader>

		<div class="mt-6 flex flex-col gap-6">
			<ActivityOverviewInfoPanel
				:activity-type-name="activityTypeName"
				:server-starts-at-label="serverStartsAtLabel"
				:local-starts-at-label="localStartsAtLabel"
				:relative-starts-at-label="relativeStartsAtLabel"
				:local-time-zone="localTimeZone"
				:duration-label="durationLabel"
				:datacenter="activity.datacenter"
				:organizer-label="organizerLabel"
				:organizer-character="activity.organized_by_character"
				:organizer-avatar-url="activity.organized_by?.avatar_url ?? null"
				:group-name="group.name"
				:assigned-main-slot-count="assignedMainSlotCount"
				:main-slot-count="mainSlots.length"
				:bench-slot-count="benchSlots.length"
				:difficulty-label="difficultyLabel"
				:run-style-label="runStyleLabel"
				:intensity-label="intensityLabel"
				:minimum-item-level-label="minimumItemLevelLabel"
				:beginner-friendly-label="beginnerFriendlyLabel"
				:notes="activity.notes"
				:target-prog-point-label="targetProgPointLabel"
				detail-mode="application"
				:pending-application-count="activity.pending_application_count"
			/>

			<ActivityCompletionSummaryPanel
				v-if="completedProgression"
				:completed-progression="completedProgression"
			/>
			<ActivityRosterSummaryPanel
				v-if="activity.roster_summary_presets.length > 0"
				:presets="activity.roster_summary_presets"
				:slots="activity.slots"
			/>
			<ActivityAttendeeRosterBoard :slots="activity.slots" />
		</div>
	</div>
</template>
