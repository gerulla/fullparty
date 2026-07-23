<script setup lang="ts">
import axios from "axios";
import { computed, ref } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { useToast } from "@nuxt/ui/composables";
import SeoHead from "@/components/Shared/SeoHead.vue";
import PageHeader from "@/components/PageHeader.vue";
import ActivityCompletionSummaryPanel from "@/components/Groups/Activities/ActivityCompletionSummaryPanel.vue";
import AddToCalendarMenu from "@/components/Groups/Activities/AddToCalendarMenu.vue";
import ActivityOverviewInfoPanel from "@/components/Groups/Activities/ActivityOverviewInfoPanel.vue";
import ActivityRosterSummaryPanel from "@/components/Groups/Activities/ActivityRosterSummaryPanel.vue";
import ActivitySelfAssignRosterBoard from "@/components/Groups/Activities/ActivitySelfAssignRosterBoard.vue";
import SelfAssignCharacterToSlotModal from "@/components/Groups/Activities/SelfAssignCharacterToSlotModal.vue";
import { localizedValue } from "@/utils/localizedValue";
import { getActivityStatusMeta } from "@/utils/activityStatusMeta";
import { isArchivedActivityStatus } from "@/utils/activityLifecycle";
import { buildActivityCompletionSummary } from "@/utils/buildActivityCompletionSummary";
import type { ActivityOverviewPermissions, AttendeeActivity, PublicGroupSummary } from "@/Types/ActivityAttendee";
import type { ActivitySlot } from "@/Types/ActivityRoster";
import type { ManualAssignmentCharacter, QueueFilterField } from "@/Types/ActivityQueue";
import type { ActivitySlotFieldSelection } from "@/Types/ActivityHolsters";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import { formatRelativeTime } from "@/utils/formatRelativeTime";
import { useMinuteTicker } from "@/composables/useMinuteTicker";

const props = defineProps<{
	group: PublicGroupSummary
	activity: AttendeeActivity
	permissions: ActivityOverviewPermissions
	secretKey?: string | null
	slotFieldDefinitions: QueueFilterField[]
	selfAssignmentCharacters: ManualAssignmentCharacter[]
}>()

const { t, locale } = useI18n();
const page = usePage();
const toast = useToast();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? "en"));
const localTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const relativeTimeTick = useMinuteTicker();
const activityData = ref<AttendeeActivity>({
	...props.activity,
	slots: [...props.activity.slots],
});
const selfAssignSlot = ref<ActivitySlot | null>(null);
const isSelfAssignModalOpen = ref(false);
const isSelfAssignmentPending = ref(false);
const pendingSlotId = ref<number | null>(null);
const viewerUserId = computed<number | null>(() => {
	const userId = page.props.auth?.user?.id;

	return typeof userId === "number" ? userId : null;
});

const currentActivity = computed(() => activityData.value);

const activityTypeName = computed(() => {
	return localizedValue(currentActivity.value.activity_type?.draft_name, locale.value, fallbackLocale.value)
		|| currentActivity.value.activity_type?.slug
		|| t("groups.activities.cards.unknown_type");
});

const activityTitle = computed(() => currentActivity.value.title || activityTypeName.value);
const statusMeta = computed(() => getActivityStatusMeta(currentActivity.value.status));
const seoDescription = computed(() => currentActivity.value.description
	|| t("meta.seo.activities.overview_description", {
		title: activityTitle.value,
		group: props.group.name,
	}));
const seoStructuredData = computed(() => ({
	"@context": "https://schema.org",
	"@type": "Event",
	name: activityTitle.value,
	description: seoDescription.value,
	startDate: currentActivity.value.starts_at || undefined,
	eventAttendanceMode: "https://schema.org/OnlineEventAttendanceMode",
	eventStatus: currentActivity.value.status === "cancelled"
		? "https://schema.org/EventCancelled"
		: currentActivity.value.status === "complete"
			? "https://schema.org/EventCompleted"
			: "https://schema.org/EventScheduled",
	organizer: {
		"@type": "Organization",
		name: props.group.name,
	},
	image: currentActivity.value.banner_image_url || currentActivity.value.small_image_url || undefined,
	url: route("groups.activities.overview", selfAssignmentRouteParameters.value),
}));
const completedProgression = computed(() => buildActivityCompletionSummary({
	activity: currentActivity.value,
	locale: locale.value,
	fallbackLocale: fallbackLocale.value,
	t,
}));
const canSelfAssign = computed(() => (
	props.permissions.can_self_assign
	&& !isArchivedActivityStatus(currentActivity.value.status)
));
const mainSlots = computed(() => currentActivity.value.slots.filter((slot) => !slot.is_bench && !slot.is_fill_in));
const benchSlots = computed(() => currentActivity.value.slots.filter((slot) => slot.is_bench));
const assignedMainSlotCount = computed(() => mainSlots.value.filter((slot) => slot.assigned_character_id !== null).length);
const viewerAssignedSlot = computed(() => currentActivity.value.slots.find((slot) => (
	slot.assigned_character !== null
	&& slot.assigned_character.user_id !== null
	&& slot.assigned_character.user_id === viewerUserId.value
)) ?? null);
const viewerAssignedCharacterName = computed(() => viewerAssignedSlot.value?.assigned_character?.name ?? null);
const hasSelfAssignmentCharacters = computed(() => props.selfAssignmentCharacters.length > 0);

const serverStartsAtLabel = computed(() => {
	if (!currentActivity.value.starts_at) {
		return t("groups.activities.cards.no_time");
	}

	return createDateTimeFormatter(locale.value, {
		weekday: "long",
		day: "numeric",
		month: "long",
		hour: "2-digit",
		minute: "2-digit",
		timeZone: "UTC",
	}).format(new Date(currentActivity.value.starts_at));
});

const localStartsAtLabel = computed(() => {
	if (!currentActivity.value.starts_at) {
		return t("groups.activities.cards.no_time");
	}

	return createDateTimeFormatter(locale.value, {
		weekday: "long",
		day: "numeric",
		month: "long",
		hour: "2-digit",
		minute: "2-digit",
		timeZoneName: "short",
	}).format(new Date(currentActivity.value.starts_at));
});

const relativeStartsAtLabel = computed(() => {
	return formatRelativeTime(
		currentActivity.value.starts_at,
		locale.value,
		t("notifications.just_now"),
		t("groups.activities.cards.no_relative_time"),
		relativeTimeTick.value,
	);
});

const durationLabel = computed(() => {
	if (!currentActivity.value.duration_hours) {
		return t("groups.activities.overview.meta.no_duration");
	}

	return t("groups.activities.management.overview.duration", { count: currentActivity.value.duration_hours });
});

const targetProgPointLabel = computed(() => {
	if (currentActivity.value.target_prog_point_label) {
		return localizedValue(currentActivity.value.target_prog_point_label, locale.value, fallbackLocale.value)
			|| currentActivity.value.target_prog_point_key
			|| t("groups.activities.overview.details.no_target_prog_point");
	}

	return currentActivity.value.target_prog_point_key
		|| t("groups.activities.overview.details.no_target_prog_point");
});

const difficultyLabel = computed(() => currentActivity.value.difficulty
	? t(`groups.activities.difficulties.${currentActivity.value.difficulty}`)
	: "—");

const runStyleLabel = computed(() => currentActivity.value.run_style
	? t(`groups.activities.run_styles.${currentActivity.value.run_style}`)
	: "—");

const intensityLabel = computed(() => currentActivity.value.intensity
	? t(`groups.activities.intensities.${currentActivity.value.intensity}`)
	: "—");

const minimumItemLevelLabel = computed(() => currentActivity.value.min_item_level
	? String(currentActivity.value.min_item_level)
	: t("groups.activities.overview.details.no_min_item_level"));

const beginnerFriendlyLabel = computed(() => t(
	currentActivity.value.beginner_friendly
		? "general.yes"
		: "general.no"
));

const organizerLabel = computed(() => (
	currentActivity.value.organized_by_character?.name
	|| currentActivity.value.organized_by?.name
	|| t("groups.activities.cards.no_organizer")
));
const cancellationAlertDescription = computed(() => (
	currentActivity.value.cancellation_reason
		|| t("groups.activities.overview.cancelled_alert.description")
));

const selfAssignmentRouteParameters = computed(() => ({
	group: props.group.slug,
	activity: currentActivity.value.id,
	secretKey: props.secretKey || undefined,
}));
const overviewUrl = computed(() => route("groups.activities.overview", selfAssignmentRouteParameters.value));
const calendarUrl = computed(() => route("groups.activities.calendar", selfAssignmentRouteParameters.value));

const initialCharacterId = computed(() => props.selfAssignmentCharacters[0]?.id ?? null);
const assignmentModeLabel = computed(() => t("groups.activities.create.summary.assignment_self_assign"));

const goBack = () => {
	router.get(route("groups.dashboard.activities.index", {
		group: props.group.slug,
	}));
};

const goToManagementPage = () => {
	router.get(route("groups.dashboard.activities.show", {
		group: props.group.slug,
		activity: currentActivity.value.id,
	}));
};

const firstValidationErrorMessage = (error: any): string | null => {
	const errors = error?.response?.data?.errors;

	if (!errors || typeof errors !== "object") {
		return null;
	}

	for (const value of Object.values(errors)) {
		if (Array.isArray(value) && typeof value[0] === "string" && value[0].length > 0) {
			return value[0];
		}
	}

	return null;
};

const patchSlot = (updatedSlot: ActivitySlot) => {
	activityData.value = {
		...activityData.value,
		slots: activityData.value.slots.map((slot) => slot.id === updatedSlot.id ? updatedSlot : slot),
	};
};

const openSelfAssignModal = (slot: ActivitySlot) => {
	if (!canSelfAssign.value || viewerAssignedSlot.value || !hasSelfAssignmentCharacters.value) {
		return;
	}

	selfAssignSlot.value = slot;
	isSelfAssignModalOpen.value = true;
};

const closeSelfAssignModal = () => {
	isSelfAssignModalOpen.value = false;
	selfAssignSlot.value = null;
};

const confirmSelfAssign = async (payload: { characterId: number, slotId: number, fieldValues: Record<string, ActivitySlotFieldSelection> }) => {
	if (!selfAssignSlot.value || isSelfAssignmentPending.value) {
		return;
	}

	isSelfAssignmentPending.value = true;
	pendingSlotId.value = payload.slotId;

	try {
		const response = await axios.post(route("groups.activities.self-assignments.store", {
			...selfAssignmentRouteParameters.value,
			slot: payload.slotId,
		}), {
			character_id: payload.characterId,
			field_values: payload.fieldValues,
			expected_slot_state_token: selfAssignSlot.value.state_token,
		});

		patchSlot(response.data.slot);
		closeSelfAssignModal();

		toast.add({
			title: t("general.success"),
			description: t("groups.activities.overview.self_signup.messages.assign_success"),
			color: "success",
			icon: "i-lucide-check",
		});
	} catch (error: any) {
		toast.add({
			title: t("general.error"),
			description: firstValidationErrorMessage(error)
				?? error?.response?.data?.message
				?? t("groups.activities.overview.self_signup.messages.assign_error"),
			color: "error",
			icon: "i-lucide-octagon-alert",
		});
	} finally {
		isSelfAssignmentPending.value = false;
		pendingSlotId.value = null;
	}
};

const removeSelfFromSlot = async (slot: ActivitySlot) => {
	if (isSelfAssignmentPending.value) {
		return;
	}

	isSelfAssignmentPending.value = true;
	pendingSlotId.value = slot.id;

	try {
		const response = await axios.delete(route("groups.activities.self-assignments.destroy", {
			...selfAssignmentRouteParameters.value,
			slot: slot.id,
		}), {
			data: {
				expected_slot_state_token: slot.state_token,
			},
		});

		patchSlot(response.data.slot);

		toast.add({
			title: t("general.success"),
			description: t("groups.activities.overview.self_signup.messages.remove_success"),
			color: "success",
			icon: "i-lucide-check",
		});
	} catch (error: any) {
		toast.add({
			title: t("general.error"),
			description: firstValidationErrorMessage(error)
				?? error?.response?.data?.message
				?? t("groups.activities.overview.self_signup.messages.remove_error"),
			color: "error",
			icon: "i-lucide-octagon-alert",
		});
	} finally {
		isSelfAssignmentPending.value = false;
		pendingSlotId.value = null;
	}
};
</script>

<template>
	<div class="w-full overflow-x-hidden">
		<SeoHead
			:title="activityTitle"
			:description="seoDescription"
			:image="currentActivity.banner_image_url || currentActivity.small_image_url"
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
			v-if="currentActivity.status === 'cancelled'"
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
					:label="t(`groups.activities.statuses.${currentActivity.status}`)"
				/>
				<AddToCalendarMenu
					:title="activityTitle"
					:group-name="group.name"
					:starts-at="currentActivity.starts_at"
					:duration-hours="currentActivity.duration_hours"
					:status="currentActivity.status"
					:overview-url="overviewUrl"
					:ics-url="calendarUrl"
					:enabled="group.features.calendar_sync_enabled"
					:notes="currentActivity.notes"
					:progress-point="targetProgPointLabel"
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

		<UAlert
			v-if="viewerAssignedCharacterName"
			class="mt-4"
			color="primary"
			variant="soft"
			icon="i-lucide-user-check"
			:title="t('groups.activities.overview.self_signup.alerts.assigned_title')"
			:description="t('groups.activities.overview.self_signup.alerts.assigned_description', { character: viewerAssignedCharacterName })"
		/>

		<UAlert
			v-else-if="canSelfAssign && !hasSelfAssignmentCharacters"
			class="mt-4"
			color="warning"
			variant="soft"
			icon="i-lucide-triangle-alert"
			:title="t('groups.activities.overview.self_signup.alerts.no_characters_title')"
			:description="t('groups.activities.overview.self_signup.alerts.no_characters_description')"
		/>

		<div class="mt-6 flex flex-col gap-6">
			<ActivityOverviewInfoPanel
				:activity-type-name="activityTypeName"
				:server-starts-at-label="serverStartsAtLabel"
				:local-starts-at-label="localStartsAtLabel"
				:relative-starts-at-label="relativeStartsAtLabel"
				:local-time-zone="localTimeZone"
				:duration-label="durationLabel"
				:datacenter="currentActivity.datacenter"
				:organizer-label="organizerLabel"
				:organizer-character="currentActivity.organized_by_character"
				:organizer-avatar-url="currentActivity.organized_by?.avatar_url ?? null"
				:group-name="group.name"
				:assigned-main-slot-count="assignedMainSlotCount"
				:main-slot-count="mainSlots.length"
				:bench-slot-count="benchSlots.length"
				:difficulty-label="difficultyLabel"
				:run-style-label="runStyleLabel"
				:intensity-label="intensityLabel"
				:minimum-item-level-label="minimumItemLevelLabel"
				:beginner-friendly-label="beginnerFriendlyLabel"
				:notes="currentActivity.notes"
				:target-prog-point-label="targetProgPointLabel"
				detail-mode="self_assignment"
				:assignment-mode-label="assignmentModeLabel"
			/>

			<ActivityCompletionSummaryPanel
				v-if="completedProgression"
				:completed-progression="completedProgression"
			/>
			<ActivityRosterSummaryPanel
				v-if="currentActivity.roster_summary_presets.length > 0"
				:presets="currentActivity.roster_summary_presets"
				:slots="currentActivity.slots"
			/>
			<ActivitySelfAssignRosterBoard
				:slots="currentActivity.slots"
				:can-self-assign="canSelfAssign"
				:has-verified-characters="hasSelfAssignmentCharacters"
				:viewer-assigned-slot-id="viewerAssignedSlot?.id ?? null"
				:pending-slot-id="pendingSlotId"
				@assign="openSelfAssignModal"
				@remove="removeSelfFromSlot"
			/>
		</div>

		<SelfAssignCharacterToSlotModal
			v-model:open="isSelfAssignModalOpen"
			:slot="selfAssignSlot"
			:characters="selfAssignmentCharacters"
			:slot-field-definitions="slotFieldDefinitions"
			:is-submitting="isSelfAssignmentPending"
			:initial-character-id="initialCharacterId"
			@confirm="confirmSelfAssign"
			@update:open="(value) => {
				if (!value) {
					closeSelfAssignModal();
				}
			}"
		/>
	</div>
</template>
