<script setup lang="ts">
import axios from "axios";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { router, usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import { canCompleteActivity, canPublishActivityRoster, canScheduleActivity, isArchivedActivityStatus } from "@/utils/activityLifecycle";
import { route } from "ziggy-js";
import { useToast } from "@nuxt/ui/composables";
import ActivityOverview from "@/components/Groups/Activities/ActivityOverview.vue";
import RosterAssignments from "@/components/Groups/Activities/RosterAssignments.vue";
import ApplicantQueue from "@/components/Groups/Activities/ApplicantQueue.vue";
import AssignApplicantToSlotModal from "@/components/Groups/Activities/AssignApplicantToSlotModal.vue";
import ManualAssignCharacterToSlotModal from "@/components/Groups/Activities/ManualAssignCharacterToSlotModal.vue";
import CompleteActivityModal from "@/components/Groups/Activities/CompleteActivityModal.vue";
import type { ManualAssignmentCharacter, QueueApplication, QueueFilterField } from "@/components/Groups/Activities/queueTypes";
import type { ActivitySlot } from "@/components/Groups/Activities/rosterTypes";

type LocalizedText = Record<string, string | null | undefined> | null | undefined;

type ActivityDetails = {
	id: number
	activity_type: {
		id: number | null
		slug: string | null
		draft_name: LocalizedText
	}
	activity_type_version_id: number
	fflogs_zone_id: number | null
	title: string | null
	description: string | null
	notes: string | null
	status: string
	starts_at: string | null
	duration_hours: number | null
	target_prog_point_key: string | null
	furthest_progress_key: string | null
	furthest_progress_percent: number | null
	is_public: boolean
	needs_application: boolean
	secret_key: string | null
	progress_entry_mode: string | null
	progress_link_url: string | null
	progress_notes: string | null
	completed_at: string | null
	organized_by: {
		id: number
		name: string
		avatar_url: string | null
	} | null
	organized_by_character: {
		id: number
		user_id: number
		name: string
		avatar_url: string | null
	} | null
	slot_count: number
	bench_slot_count: number
	application_count: number
	pending_application_count: number
	progress_milestone_count: number
	can_use_fflogs_completion: boolean
	prog_points: Array<{
		key: string
		label: LocalizedText
	}>
	slot_field_definitions: QueueFilterField[]
	slots: ActivitySlot[]
	missing_assignments: Array<{
		id: number
		slot_id: number | null
		character: {
			id: number
			name: string
			avatar_url: string | null
			world: string | null
			datacenter: string | null
		} | null
		slot_label: LocalizedText
		group_label: LocalizedText
		marked_missing_at: string | null
	}>
	progress_milestones: Array<{
		id: number
		milestone_key: string
		milestone_label: LocalizedText
		sort_order: number
		kills: number
		best_progress_percent: number | null
		source: string | null
		notes: string | null
	}>
}

type ActivityManagementPatch = {
	updated_slots?: ActivitySlot[]
	pending_application_count?: number
	queue_application_sync_ids?: number[]
	queue_application_remove_ids?: number[]
	upsert_missing_assignments?: ActivityDetails['missing_assignments']
	remove_missing_assignment_ids?: number[]
}

const props = defineProps<{
	group: {
		id: number
		name: string
		slug: string
		current_user_role: string | null
		permissions: {
			can_manage_activities: boolean
		}
	}
	activity: {
		id: number
	}
}>();

const { t, locale } = useI18n();
const page = usePage();
const toast = useToast();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));
const rosterView = ref<'party' | 'role' | 'list'>('party');
const showApplicantQueue = ref(true);
const isLoading = ref(true);
const isSlotSwapPending = ref(false);
const pendingSwapSlotIds = ref<number[]>([]);
const isSlotAssignmentPending = ref(false);
const isCompleteModalOpen = ref(false);
const isCompletingActivity = ref(false);
const isScheduleConfirmOpen = ref(false);
const isSchedulingActivity = ref(false);
const isCancelConfirmOpen = ref(false);
const isCancellingActivity = ref(false);
const pendingMissingUndoIds = ref<number[]>([]);
const completionErrors = ref<Record<string, string[]>>({});
const assignmentModalApplication = ref<QueueApplication | null>(null);
const assignmentModalSlotId = ref<number | null>(null);
const assignmentModalSourceSlotId = ref<number | null>(null);
const assignmentModalMode = ref<'assign' | 'edit'>('assign');
const manualAssignmentModalOpen = ref(false);
const manualAssignmentSlotId = ref<number | null>(null);
const manualAssignmentSourceSlotId = ref<number | null>(null);
const manualAssignmentInitialCharacterId = ref<number | null>(null);
const manualAssignmentCharacters = ref<ManualAssignmentCharacter[]>([]);
const activityData = ref<ActivityDetails | null>(null);
const subscribedManagementChannelName = ref<string | null>(null);

const currentActivity = computed(() => activityData.value);
const activityTypeName = computed(() => {
	if (!currentActivity.value) {
		return '';
	}

	return localizedValue(currentActivity.value.activity_type?.draft_name, locale.value, fallbackLocale.value)
		|| currentActivity.value.activity_type?.slug
		|| t('groups.activities.cards.unknown_type');
});

const activityTitle = computed(() => currentActivity.value?.title || activityTypeName.value);
const isActivityArchived = computed(() => isArchivedActivityStatus(currentActivity.value?.status));
const canEditActivity = computed(() => !currentActivity.value || !isActivityArchived.value);
const canScheduleActivityAction = computed(() => Boolean(currentActivity.value && canScheduleActivity(currentActivity.value.status)));
const canCompleteActivityAction = computed(() => Boolean(currentActivity.value && canCompleteActivity(currentActivity.value.status)));
const canPublishRoster = computed(() => Boolean(currentActivity.value && canPublishActivityRoster(currentActivity.value.status)));
const canCancelActivity = computed(() => Boolean(currentActivity.value && !isActivityArchived.value));
const organizerName = computed(() => currentActivity.value?.organized_by_character?.name || null);
const organizerAvatarUrl = computed(() => currentActivity.value?.organized_by_character?.avatar_url || null);
const assignedCount = computed(() => currentActivity.value?.slots.filter((slot) => !slot.is_bench && slot.assigned_character_id !== null).length ?? 0);
const pendingApplicationCount = computed(() => currentActivity.value?.pending_application_count ?? 0);
const missingAssignments = computed(() => currentActivity.value?.missing_assignments ?? []);
const completedProgression = computed(() => {
	if (!currentActivity.value || currentActivity.value.status !== 'complete') {
		return null;
	}

	const furthestProgPoint = currentActivity.value.prog_points.find((progPoint) => progPoint.key === currentActivity.value?.furthest_progress_key);
	const milestones = [...currentActivity.value.progress_milestones]
		.sort((left, right) => left.sort_order - right.sort_order)
		.filter((milestone) => milestone.kills > 0 || milestone.best_progress_percent !== null)
		.map((milestone) => ({
			key: milestone.milestone_key,
			label: localizedValue(milestone.milestone_label, locale.value, fallbackLocale.value) || milestone.milestone_key,
			kills: milestone.kills,
			bestProgressPercent: milestone.best_progress_percent,
		}));

	return {
		completedAt: currentActivity.value.completed_at,
		sourceLabel: currentActivity.value.progress_entry_mode
			? t(`groups.activities.management.complete_activity_modal.methods.${currentActivity.value.progress_entry_mode}`)
			: t('groups.activities.management.overview.progression.not_recorded'),
		furthestPointLabel: furthestProgPoint
			? (localizedValue(furthestProgPoint.label, locale.value, fallbackLocale.value) || furthestProgPoint.key)
			: null,
		bestProgressPercent: currentActivity.value.furthest_progress_percent,
		progressLinkUrl: currentActivity.value.progress_link_url,
		notes: currentActivity.value.progress_notes,
		milestones,
	};
});
const assignmentModalOpen = computed({
	get: () => Boolean(assignmentModalApplication.value && assignmentModalSlot.value),
	set: (value: boolean) => {
		if (!value) {
			closeAssignmentModal();
		}
	},
});
const assignmentModalSlot = computed(() => {
	if (!currentActivity.value || assignmentModalSlotId.value === null) {
		return null;
	}

	return currentActivity.value.slots.find((slot) => slot.id === assignmentModalSlotId.value) ?? null;
});
const manualAssignmentModalSlot = computed(() => {
	if (!currentActivity.value || manualAssignmentSlotId.value === null) {
		return null;
	}

	return currentActivity.value.slots.find((slot) => slot.id === manualAssignmentSlotId.value) ?? null;
});
const lockManualAssignmentCharacter = computed(() => Boolean(
	manualAssignmentModalSlot.value?.assigned_character_id
	&& manualAssignmentModalSlot.value.assignment_source === 'manual',
));
const managementChannelName = computed(() => `groups.${props.group.id}.activities.${props.activity.id}.management`);

const findSlotById = (slotId: number | null | undefined) => (
	slotId === null || slotId === undefined || !currentActivity.value
		? null
		: currentActivity.value.slots.find((slot) => slot.id === slotId) ?? null
);

const handleSlotStateConflict = async (error: any) => {
	if (error?.response?.status !== 409) {
		return false;
	}

	toast.add({
		title: t('general.error'),
		description: error?.response?.data?.message ?? 'This slot changed while you were editing it. Refresh and try again.',
		color: 'warning',
		icon: 'i-lucide-triangle-alert',
	});

	await fetchManagementData();

	return true;
};

const goBack = () => {
	router.get(route('groups.dashboard.activities.index', props.group.slug));
};

const goToOverviewPage = () => {
	if (!currentActivity.value) {
		return;
	}

	router.get(route('groups.activities.overview', {
		group: props.group.slug,
		activity: currentActivity.value.id,
		secretKey: currentActivity.value.is_public ? undefined : currentActivity.value.secret_key,
	}));
};

const goToEditPage = () => {
	router.get(route('groups.dashboard.activities.edit', {
		group: props.group.slug,
		activity: props.activity.id,
	}));
};

const cancelActivity = () => {
	if (!currentActivity.value) {
		return;
	}

	isCancelConfirmOpen.value = true;
};

const scheduleActivity = () => {
	if (!currentActivity.value) {
		return;
	}

	isScheduleConfirmOpen.value = true;
};

const confirmScheduleActivity = () => {
	if (!currentActivity.value || isSchedulingActivity.value) {
		return;
	}

	isSchedulingActivity.value = true;
	router.post(route('groups.dashboard.activities.schedule', {
		group: props.group.slug,
		activity: props.activity.id,
	}), {}, {
		preserveScroll: true,
		onSuccess: () => {
			isScheduleConfirmOpen.value = false;
			void fetchManagementData();
		},
		onFinish: () => {
			isSchedulingActivity.value = false;
		},
	});
};

const confirmCancelActivity = () => {
	if (!currentActivity.value || isCancellingActivity.value) {
		return;
	}

	isCancellingActivity.value = true;
	router.post(route('groups.dashboard.activities.cancel', {
		group: props.group.slug,
		activity: props.activity.id,
	}), {}, {
		preserveScroll: true,
		onSuccess: () => {
			isCancelConfirmOpen.value = false;
			void fetchManagementData();
		},
		onFinish: () => {
			isCancellingActivity.value = false;
		},
	});
};

const completeActivity = () => {
	if (!currentActivity.value) {
		return;
	}

	completionErrors.value = {};
	isCompleteModalOpen.value = true;
};

const confirmCompleteActivity = async (payload: {
	progress_entry_mode: 'manual' | 'fflogs' | null
	progress_link_url: string | null
	progress_notes: string | null
	furthest_progress_key: string | null
	milestones: Array<{
		milestone_key: string
		kills: number
		best_progress_percent: number | null
	}>
}) => {
	if (!currentActivity.value || isCompletingActivity.value) {
		return;
	}

	isCompletingActivity.value = true;
	completionErrors.value = {};

	try {
		await axios.post(route('groups.dashboard.activities.complete', {
			group: props.group.slug,
			activity: props.activity.id,
		}), payload);

		isCompleteModalOpen.value = false;
		void fetchManagementData();
	} catch (error: any) {
		console.error(error);

		completionErrors.value = error?.response?.data?.errors ?? {};

		toast.add({
			title: t('general.error'),
			description: error?.response?.data?.message ?? t('groups.activities.management.messages.complete_failed'),
			color: 'error',
			icon: 'i-lucide-octagon-alert',
		});
	} finally {
		isCompletingActivity.value = false;
	}
};

const publishRoster = () => {
	if (!currentActivity.value) {
		return;
	}

	router.post(route('groups.dashboard.activities.publish-roster', {
		group: props.group.slug,
		activity: props.activity.id,
	}), {}, {
		preserveScroll: true,
		onSuccess: () => {
			void fetchManagementData();
		},
	});
};

const activityApplicationRouteParams = computed(() => {
	if (!currentActivity.value) {
		return null;
	}

	return {
		group: props.group.slug,
		activity: currentActivity.value.id,
		secretKey: currentActivity.value.is_public ? undefined : currentActivity.value.secret_key,
	};
});

const goToApplicationPage = () => {
	if (!activityApplicationRouteParams.value) {
		return;
	}

	router.get(route('groups.activities.application', activityApplicationRouteParams.value));
};

const exportRoster = () => {
	if (!currentActivity.value) {
		return;
	}

	window.location.href = route('groups.dashboard.activities.export-roster', {
		group: props.group.slug,
		activity: currentActivity.value.id,
	});
};

const copyApplicationLink = async () => {
	if (!activityApplicationRouteParams.value) {
		return;
	}

	await navigator.clipboard.writeText(`${window.location.origin}${route('groups.activities.application', activityApplicationRouteParams.value, false)}`);

	toast.add({
		title: t('general.success'),
		description: t('groups.activities.management.overview.application_link_copied'),
		color: 'success',
		icon: 'i-lucide-copy-check',
	});
};

const fetchManagementData = async () => {
	isLoading.value = true;

	try {
		const response = await axios.get(route('groups.dashboard.activities.management-data', {
			group: props.group.slug,
			activity: props.activity.id,
		}));

		activityData.value = response.data?.activity ?? null;
	} catch (error) {
		console.error(error);
		activityData.value = null;
	} finally {
		isLoading.value = false;
	}
};

const dispatchQueueSyncEvent = (
	syncApplicationIds: number[] = [],
	removeApplicationIds: number[] = [],
) => {
	if (syncApplicationIds.length === 0 && removeApplicationIds.length === 0) {
		return;
	}

	window.dispatchEvent(new CustomEvent('fullparty:activity-management-queue-sync', {
		detail: {
			syncApplicationIds,
			removeApplicationIds,
		},
	}));
};

const applyManagementPatch = (patch: ActivityManagementPatch) => {
	if (!currentActivity.value) {
		return;
	}

	const updatedSlotsById = new Map((patch.updated_slots ?? []).map((slot) => [slot.id, slot]));
	const upsertMissingAssignmentsById = new Map((patch.upsert_missing_assignments ?? []).map((assignment) => [assignment.id, assignment]));
	const removeMissingAssignmentIds = new Set(patch.remove_missing_assignment_ids ?? []);
	const nextMissingAssignments = currentActivity.value.missing_assignments
		.filter((assignment) => !removeMissingAssignmentIds.has(assignment.id))
		.map((assignment) => upsertMissingAssignmentsById.get(assignment.id) ?? assignment);

	for (const assignment of upsertMissingAssignmentsById.values()) {
		if (!nextMissingAssignments.some((entry) => entry.id === assignment.id)) {
			nextMissingAssignments.unshift(assignment);
		}
	}

	activityData.value = {
		...currentActivity.value,
		pending_application_count: patch.pending_application_count ?? currentActivity.value.pending_application_count,
		slots: updatedSlotsById.size > 0
			? currentActivity.value.slots.map((slot) => updatedSlotsById.get(slot.id) ?? slot)
			: currentActivity.value.slots,
		missing_assignments: nextMissingAssignments,
	};

	dispatchQueueSyncEvent(
		patch.queue_application_sync_ids ?? [],
		patch.queue_application_remove_ids ?? [],
	);
};

const subscribeToManagementChannel = () => {
	const echo = (window as Window & {
		Echo?: {
			private: (channelName: string) => { listen: (eventName: string, callback: (payload: { patch?: ActivityManagementPatch }) => void) => void }
			leave?: (channelName: string) => void
		}
	}).Echo;

	if (!echo) {
		return;
	}

	const channelName = managementChannelName.value;

	if (subscribedManagementChannelName.value === channelName) {
		return;
	}

	if (subscribedManagementChannelName.value && echo.leave) {
		echo.leave(subscribedManagementChannelName.value);
	}

	echo.private(channelName)
		.listen('.activity.management.updated', (payload: { patch?: ActivityManagementPatch }) => {
			if (payload.patch) {
				applyManagementPatch(payload.patch);
			}
		});

	subscribedManagementChannelName.value = channelName;
};

const handleSlotSwap = async (payload: { sourceSlotId: number, targetSlotId: number }) => {
	if (!currentActivity.value || isActivityArchived.value || isSlotSwapPending.value || isSlotAssignmentPending.value) {
		return;
	}

	const sourceSlot = currentActivity.value.slots.find((slot) => slot.id === payload.sourceSlotId);
	const targetSlot = currentActivity.value.slots.find((slot) => slot.id === payload.targetSlotId);

	if (!sourceSlot || !targetSlot) {
		return;
	}

	if (sourceSlot.is_bench && !targetSlot.is_bench) {
		await openAssignmentModalFromSlot(targetSlot.id, sourceSlot.id);
		return;
	}

	if (!sourceSlot.is_bench && targetSlot.is_bench && targetSlot.assigned_character_id) {
		await openAssignmentModalFromSlot(sourceSlot.id, targetSlot.id);
		return;
	}

	isSlotSwapPending.value = true;
	pendingSwapSlotIds.value = [payload.sourceSlotId, payload.targetSlotId];

	try {
		const response = await axios.post(route('groups.dashboard.activities.slot-swaps.store', {
			group: props.group.slug,
			activity: props.activity.id,
		}), {
			source_slot_id: payload.sourceSlotId,
			target_slot_id: payload.targetSlotId,
			expected_source_slot_state_token: sourceSlot.state_token,
			expected_target_slot_state_token: targetSlot.state_token,
		});

		const updatedSlots = response.data?.slots ?? [];

		if (updatedSlots.length > 0) {
			const updatedSlotsById = new Map(updatedSlots.map((slot: ActivitySlot) => [slot.id, slot]));

			activityData.value = {
				...currentActivity.value,
				slots: currentActivity.value.slots.map((slot) => updatedSlotsById.get(slot.id) ?? slot),
			};
		}
	} catch (error: any) {
		console.error(error);

		if (await handleSlotStateConflict(error)) {
			return;
		}

		toast.add({
			title: t('general.error'),
			description: t('groups.activities.management.messages.swap_failed'),
			color: 'error',
			icon: 'i-lucide-octagon-alert',
		});
	} finally {
		isSlotSwapPending.value = false;
		pendingSwapSlotIds.value = [];
	}
};

const openAssignmentModal = (payload: { slotId: number, application: QueueApplication }) => {
	if (!currentActivity.value || isActivityArchived.value || isSlotSwapPending.value || isSlotAssignmentPending.value) {
		return;
	}

	const targetSlot = currentActivity.value.slots.find((slot) => slot.id === payload.slotId);

	if (!targetSlot) {
		return;
	}

	if (targetSlot.is_bench) {
		void handleAssignApplicantToSlot({
			applicationId: payload.application.id,
			slotId: payload.slotId,
			fieldValues: {},
		});
		return;
	}

	assignmentModalMode.value = 'assign';
	assignmentModalSlotId.value = payload.slotId;
	assignmentModalSourceSlotId.value = null;
	assignmentModalApplication.value = payload.application;
};

const closeAssignmentModal = () => {
	assignmentModalMode.value = 'assign';
	assignmentModalSlotId.value = null;
	assignmentModalSourceSlotId.value = null;
	assignmentModalApplication.value = null;
};

const closeManualAssignmentModal = () => {
	manualAssignmentModalOpen.value = false;
	manualAssignmentSlotId.value = null;
	manualAssignmentSourceSlotId.value = null;
	manualAssignmentInitialCharacterId.value = null;
	manualAssignmentCharacters.value = [];
};

const fetchSlotAssignmentContext = async (slotId: number) => {
	if (!currentActivity.value) {
		return null;
	}

	const response = await axios.get(route('groups.dashboard.activities.slot-assignments.context', {
		group: props.group.slug,
		activity: props.activity.id,
		slot: slotId,
	}));

	return response.data?.application ?? null;
};

const fetchManualAssignmentOptions = async (slotId: number, sourceSlotId?: number | null) => {
	const response = await axios.get(route('groups.dashboard.activities.slot-manual-assignment-options.show', {
		group: props.group.slug,
		activity: props.activity.id,
		slot: slotId,
	}), {
		params: sourceSlotId ? { source_slot_id: sourceSlotId } : undefined,
	});

	return response.data?.characters ?? [];
};

const openManualAssignmentModalForSlot = async (
	slotId: number,
	options: {
		sourceSlotId?: number | null
		initialCharacterId?: number | null
	} = {},
) => {
	if (!currentActivity.value || isActivityArchived.value || isSlotSwapPending.value || isSlotAssignmentPending.value) {
		return;
	}

	try {
		manualAssignmentCharacters.value = await fetchManualAssignmentOptions(slotId, options.sourceSlotId);
		manualAssignmentSlotId.value = slotId;
		manualAssignmentSourceSlotId.value = options.sourceSlotId ?? null;
		manualAssignmentInitialCharacterId.value = options.initialCharacterId ?? null;
		manualAssignmentModalOpen.value = true;
	} catch (error) {
		console.error(error);
		toast.add({
			title: t('general.error'),
			description: t('groups.activities.management.messages.load_manual_assignment_failed'),
			color: 'error',
			icon: 'i-lucide-octagon-alert',
		});
	}
};

const openAssignmentModalFromSlot = async (targetSlotId: number, sourceSlotId: number) => {
	if (!currentActivity.value || isActivityArchived.value || isSlotSwapPending.value || isSlotAssignmentPending.value) {
		return;
	}

	const sourceSlot = currentActivity.value.slots.find((slot) => slot.id === sourceSlotId);

	if (sourceSlot?.assignment_source === 'manual' && sourceSlot.assigned_character_id) {
		await openManualAssignmentModalForSlot(targetSlotId, {
			sourceSlotId,
			initialCharacterId: sourceSlot.assigned_character_id,
		});
		return;
	}

	try {
		const application = await fetchSlotAssignmentContext(sourceSlotId);

		if (!application) {
			return;
		}

		assignmentModalMode.value = 'assign';
		assignmentModalSlotId.value = targetSlotId;
		assignmentModalSourceSlotId.value = sourceSlotId;
		assignmentModalApplication.value = application;
	} catch (error) {
		console.error(error);
		toast.add({
			title: t('general.error'),
			description: t('groups.activities.management.messages.prepare_move_failed'),
			color: 'error',
			icon: 'i-lucide-octagon-alert',
		});
	}
};

const openSlotEditModal = async (slotId: number) => {
	if (!currentActivity.value || isActivityArchived.value || isSlotSwapPending.value || isSlotAssignmentPending.value) {
		return;
	}

	const slot = currentActivity.value.slots.find((entry) => entry.id === slotId);

	if (!slot?.assigned_character_id) {
		await openManualAssignmentModalForSlot(slotId);
		return;
	}

	if (slot.is_bench) {
		if (slot.assignment_source === 'manual') {
			await openManualAssignmentModalForSlot(slotId, {
				initialCharacterId: slot.assigned_character_id,
			});
		}

		return;
	}

	if (slot.assignment_source === 'manual') {
		await openManualAssignmentModalForSlot(slotId, {
			initialCharacterId: slot.assigned_character_id,
		});
		return;
	}

	try {
		assignmentModalMode.value = 'edit';
		assignmentModalSlotId.value = slotId;
		assignmentModalSourceSlotId.value = null;
		assignmentModalApplication.value = await fetchSlotAssignmentContext(slotId);
	} catch (error) {
		console.error(error);
		toast.add({
			title: t('general.error'),
			description: t('groups.activities.management.messages.load_slot_assignment_failed'),
			color: 'error',
			icon: 'i-lucide-octagon-alert',
		});
	}
};

const handleSlotReturnedToQueue = (event: Event) => {
	const customEvent = event as CustomEvent<{ slot?: ActivitySlot | null }>;
	const updatedSlot = customEvent.detail?.slot;

	if (!currentActivity.value || !updatedSlot) {
		return;
	}

	activityData.value = {
		...currentActivity.value,
		pending_application_count: currentActivity.value.pending_application_count + 1,
		slots: currentActivity.value.slots.map((slot) => slot.id === updatedSlot.id ? updatedSlot : slot),
	};
};

const returnSlotToQueue = async (slotId: number) => {
	if (!currentActivity.value || isActivityArchived.value || isSlotAssignmentPending.value || isSlotSwapPending.value) {
		return;
	}

	isSlotAssignmentPending.value = true;
	pendingSwapSlotIds.value = [slotId];

	try {
		const slot = findSlotById(slotId);

		if (!slot) {
			return;
		}

		const response = await axios.post(route('groups.dashboard.activities.slot-unassignments.store', {
			group: props.group.slug,
			activity: props.activity.id,
			slot: slotId,
		}), {
			expected_slot_state_token: slot.state_token,
		});

		const updatedSlot = response.data?.slot ?? null;
		const restoredApplication = response.data?.application ?? null;
		const pendingApplicationCount = response.data?.pending_application_count;

		if (updatedSlot) {
			activityData.value = {
				...currentActivity.value,
				pending_application_count: typeof pendingApplicationCount === 'number'
					? pendingApplicationCount
					: currentActivity.value.pending_application_count + 1,
				slots: currentActivity.value.slots.map((slot) => slot.id === updatedSlot.id ? updatedSlot : slot),
			};
		}

		if (restoredApplication) {
			window.dispatchEvent(new CustomEvent('fullparty:activity-application-returned', {
				detail: {
					application: restoredApplication,
				},
			}));
		}
	} catch (error: any) {
		console.error(error);

		if (await handleSlotStateConflict(error)) {
			return;
		}

		toast.add({
			title: t('general.error'),
			description: t('groups.activities.management.messages.return_to_queue_failed'),
			color: 'error',
			icon: 'i-lucide-octagon-alert',
		});
	} finally {
		isSlotAssignmentPending.value = false;
		pendingSwapSlotIds.value = [];
	}
};

const moveSlotToBench = async (slotId: number) => {
	if (!currentActivity.value || isActivityArchived.value || isSlotAssignmentPending.value || isSlotSwapPending.value) {
		return;
	}

	const sourceSlot = currentActivity.value.slots.find((slot) => slot.id === slotId);
	const targetBenchSlot = currentActivity.value.slots.find((slot) => slot.is_bench && slot.assigned_character_id === null);

	if (!sourceSlot || sourceSlot.is_bench || !targetBenchSlot) {
		return;
	}

	await handleSlotSwap({
		sourceSlotId: sourceSlot.id,
		targetSlotId: targetBenchSlot.id,
	});
};

const checkInSlot = async (slotId: number) => {
	await updateSlotAttendance(slotId, 'check_in');
};

const markSlotLate = async (slotId: number) => {
	await updateSlotAttendance(slotId, 'late');
};

const updateSlotAttendance = async (slotId: number, mode: 'check_in' | 'late') => {
	if (!currentActivity.value || isActivityArchived.value || isSlotAssignmentPending.value || isSlotSwapPending.value) {
		return;
	}

	const slot = currentActivity.value.slots.find((entry) => entry.id === slotId);

	if (!slot || !slot.assigned_character_id || slot.is_bench) {
		return;
	}

	isSlotAssignmentPending.value = true;
	pendingSwapSlotIds.value = [slotId];

	try {
		const response = await axios.post(route(
			mode === 'late'
				? 'groups.dashboard.activities.slot-checkins.late'
				: ['checked_in', 'late'].includes(slot.attendance_status ?? '')
				? 'groups.dashboard.activities.slot-checkins.undo'
				: 'groups.dashboard.activities.slot-checkins.store',
			{
				group: props.group.slug,
				activity: props.activity.id,
				slot: slotId,
			},
		), {
			expected_slot_state_token: slot.state_token,
		});

		const updatedSlot = response.data?.slot ?? null;

		if (updatedSlot) {
			activityData.value = {
				...currentActivity.value,
				slots: currentActivity.value.slots.map((slot) => slot.id === updatedSlot.id ? updatedSlot : slot),
			};
		}
	} catch (error: any) {
		console.error(error);

		if (await handleSlotStateConflict(error)) {
			return;
		}

		toast.add({
			title: t('general.error'),
			description: mode === 'late'
				? t('groups.activities.management.messages.mark_late_failed')
				: ['checked_in', 'late'].includes(slot.attendance_status ?? '')
				? t('groups.activities.management.messages.undo_check_in_failed')
				: t('groups.activities.management.messages.check_in_failed'),
			color: 'error',
			icon: 'i-lucide-octagon-alert',
		});
	} finally {
		isSlotAssignmentPending.value = false;
		pendingSwapSlotIds.value = [];
	}
};

const checkInGroup = async (groupKey: string) => {
	if (!currentActivity.value || isActivityArchived.value || isSlotAssignmentPending.value || isSlotSwapPending.value) {
		return;
	}

	const targetSlotIds = currentActivity.value.slots
		.filter((slot) => slot.group_key === groupKey && slot.assigned_character_id !== null && slot.attendance_status !== 'checked_in')
		.map((slot) => slot.id);

	if (targetSlotIds.length === 0) {
		return;
	}

	isSlotAssignmentPending.value = true;
	pendingSwapSlotIds.value = targetSlotIds;

	try {
		const expectedSlotStateTokens = Object.fromEntries(
			currentActivity.value.slots
				.filter((slot) => slot.group_key === groupKey && slot.assigned_character_id !== null && slot.attendance_status !== 'checked_in')
				.map((slot) => [slot.id, slot.state_token]),
		);

		const response = await axios.post(route('groups.dashboard.activities.slot-group-checkins.store', {
			group: props.group.slug,
			activity: props.activity.id,
		}), {
			group_key: groupKey,
			expected_slot_state_tokens: expectedSlotStateTokens,
		});

		const updatedSlots = response.data?.slots ?? [];

		if (updatedSlots.length > 0) {
			const updatedSlotsById = new Map(updatedSlots.map((slot: ActivitySlot) => [slot.id, slot]));

			activityData.value = {
				...currentActivity.value,
				slots: currentActivity.value.slots.map((slot) => updatedSlotsById.get(slot.id) ?? slot),
			};
		}
	} catch (error: any) {
		console.error(error);

		if (await handleSlotStateConflict(error)) {
			return;
		}

		toast.add({
			title: t('general.error'),
			description: t('groups.activities.management.messages.check_in_group_failed'),
			color: 'error',
			icon: 'i-lucide-octagon-alert',
		});
	} finally {
		isSlotAssignmentPending.value = false;
		pendingSwapSlotIds.value = [];
	}
};

const markSlotMissing = async (slotId: number) => {
	if (!currentActivity.value || isActivityArchived.value || isSlotAssignmentPending.value || isSlotSwapPending.value) {
		return;
	}

	isSlotAssignmentPending.value = true;
	pendingSwapSlotIds.value = [slotId];

	try {
		const slot = findSlotById(slotId);

		if (!slot) {
			return;
		}

		const response = await axios.post(route('groups.dashboard.activities.slot-missing.store', {
			group: props.group.slug,
			activity: props.activity.id,
			slot: slotId,
		}), {
			expected_slot_state_token: slot.state_token,
		});

		const updatedSlot = response.data?.slot ?? null;
		const missingAssignment = response.data?.missing_assignment ?? null;

		if (updatedSlot) {
			activityData.value = {
				...currentActivity.value,
				slots: currentActivity.value.slots.map((slot) => slot.id === updatedSlot.id ? updatedSlot : slot),
				missing_assignments: missingAssignment
					? [missingAssignment, ...currentActivity.value.missing_assignments.filter((entry) => entry.id !== missingAssignment.id)]
					: currentActivity.value.missing_assignments,
			};
		}
	} catch (error: any) {
		console.error(error);

		if (await handleSlotStateConflict(error)) {
			return;
		}

		toast.add({
			title: t('general.error'),
			description: t('groups.activities.management.messages.mark_missing_failed'),
			color: 'error',
			icon: 'i-lucide-octagon-alert',
		});
	} finally {
		isSlotAssignmentPending.value = false;
		pendingSwapSlotIds.value = [];
	}
};

const undoMissingAssignment = async (assignmentId: number) => {
	if (!currentActivity.value || isActivityArchived.value || pendingMissingUndoIds.value.includes(assignmentId)) {
		return;
	}

	pendingMissingUndoIds.value = [...pendingMissingUndoIds.value, assignmentId];

	try {
		const missingAssignment = currentActivity.value.missing_assignments.find((entry) => entry.id === assignmentId);
		const sourceSlot = findSlotById(missingAssignment?.slot_id ?? null);

		const response = await axios.post(route('groups.dashboard.activities.slot-missing.undo', {
			group: props.group.slug,
			activity: props.activity.id,
			assignment: assignmentId,
		}), {
			expected_slot_state_token: sourceSlot?.state_token ?? null,
		});

		const updatedSlots = response.data?.slots ?? [];
		const updatedSlotsById = new Map(updatedSlots.map((slot: ActivitySlot) => [slot.id, slot]));

		activityData.value = {
			...currentActivity.value,
			slots: updatedSlots.length > 0
				? currentActivity.value.slots.map((slot) => updatedSlotsById.get(slot.id) ?? slot)
				: currentActivity.value.slots,
			missing_assignments: currentActivity.value.missing_assignments.filter((entry) => entry.id !== assignmentId),
		};
	} catch (error: any) {
		console.error(error);

		if (await handleSlotStateConflict(error)) {
			return;
		}

		const warningMessage = error?.response?.data?.errors?.assignment?.[0];

		toast.add({
			title: warningMessage ? t('groups.activities.management.messages.warning_title') : t('general.error'),
			description: warningMessage ?? t('groups.activities.management.messages.undo_missing_failed'),
			color: warningMessage ? 'warning' : 'error',
			icon: warningMessage ? 'i-lucide-triangle-alert' : 'i-lucide-octagon-alert',
		});
	} finally {
		pendingMissingUndoIds.value = pendingMissingUndoIds.value.filter((id) => id !== assignmentId);
	}
};

const handleAssignApplicantToSlot = async (payload: { applicationId: number, slotId: number, fieldValues: Record<string, string | string[]>, sourceSlotId?: number | null }) => {
	if (!currentActivity.value || isActivityArchived.value || isSlotAssignmentPending.value) {
		return;
	}

	isSlotAssignmentPending.value = true;
	pendingSwapSlotIds.value = [payload.slotId, ...(payload.sourceSlotId ? [payload.sourceSlotId] : [])];

	try {
		const targetSlot = findSlotById(payload.slotId);
		const sourceSlot = findSlotById(payload.sourceSlotId ?? assignmentModalSourceSlotId.value ?? null);

		if (!targetSlot) {
			return;
		}

		const response = await axios.post(route('groups.dashboard.activities.slot-assignments.store', {
			group: props.group.slug,
			activity: props.activity.id,
			slot: payload.slotId,
		}), {
			application_id: payload.applicationId,
			field_values: payload.fieldValues,
			source_slot_id: payload.sourceSlotId ?? assignmentModalSourceSlotId.value,
			expected_slot_state_token: targetSlot.state_token,
			expected_source_slot_state_token: sourceSlot?.state_token ?? null,
		});

		const updatedSlots = response.data?.slots ?? [];
		const pendingApplicationCount = response.data?.pending_application_count;
		const removedQueueApplicationIds = response.data?.queue_application_remove_ids ?? [];
		const restoredQueueApplication = response.data?.restored_queue_application ?? null;

		if (updatedSlots.length > 0) {
			const updatedSlotsById = new Map(updatedSlots.map((slot: ActivitySlot) => [slot.id, slot]));

			activityData.value = {
				...currentActivity.value,
				pending_application_count: typeof pendingApplicationCount === 'number'
					? pendingApplicationCount
					: currentActivity.value.pending_application_count,
				slots: currentActivity.value.slots.map((slot) => updatedSlotsById.get(slot.id) ?? slot),
			};
		}

		for (const applicationId of removedQueueApplicationIds) {
			window.dispatchEvent(new CustomEvent('fullparty:activity-application-assigned', {
				detail: {
					applicationId,
				},
			}));
		}

		if (restoredQueueApplication) {
			window.dispatchEvent(new CustomEvent('fullparty:activity-application-returned', {
				detail: {
					application: restoredQueueApplication,
				},
			}));
		}

		closeAssignmentModal();
	} catch (error: any) {
		console.error(error);

		if (await handleSlotStateConflict(error)) {
			closeAssignmentModal();
			return;
		}

		toast.add({
			title: t('general.error'),
			description: t('groups.activities.management.messages.assign_failed'),
			color: 'error',
			icon: 'i-lucide-octagon-alert',
		});
	} finally {
		isSlotAssignmentPending.value = false;
		pendingSwapSlotIds.value = [];
	}
};

const handleAssignCharacterToSlot = async (payload: { characterId: number, slotId: number, fieldValues: Record<string, string | string[]>, sourceSlotId?: number | null }) => {
	if (!currentActivity.value || isActivityArchived.value || isSlotAssignmentPending.value) {
		return;
	}

	isSlotAssignmentPending.value = true;
	pendingSwapSlotIds.value = [payload.slotId, ...(payload.sourceSlotId ? [payload.sourceSlotId] : [])];

	try {
		const targetSlot = findSlotById(payload.slotId);
		const sourceSlot = findSlotById(payload.sourceSlotId ?? manualAssignmentSourceSlotId.value ?? null);

		if (!targetSlot) {
			return;
		}

		const response = await axios.post(route('groups.dashboard.activities.slot-assignments.store', {
			group: props.group.slug,
			activity: props.activity.id,
			slot: payload.slotId,
		}), {
			character_id: payload.characterId,
			field_values: payload.fieldValues,
			source_slot_id: payload.sourceSlotId ?? manualAssignmentSourceSlotId.value,
			expected_slot_state_token: targetSlot.state_token,
			expected_source_slot_state_token: sourceSlot?.state_token ?? null,
		});

		const updatedSlots = response.data?.slots ?? [];
		const pendingApplicationCount = response.data?.pending_application_count;

		if (updatedSlots.length > 0) {
			const updatedSlotsById = new Map(updatedSlots.map((slot: ActivitySlot) => [slot.id, slot]));

			activityData.value = {
				...currentActivity.value,
				pending_application_count: typeof pendingApplicationCount === 'number'
					? pendingApplicationCount
					: currentActivity.value.pending_application_count,
				slots: currentActivity.value.slots.map((slot) => updatedSlotsById.get(slot.id) ?? slot),
			};
		}

		closeManualAssignmentModal();
	} catch (error: any) {
		console.error(error);

		if (await handleSlotStateConflict(error)) {
			closeManualAssignmentModal();
			return;
		}

		toast.add({
			title: t('general.error'),
			description: t('groups.activities.management.messages.manual_assign_failed'),
			color: 'error',
			icon: 'i-lucide-octagon-alert',
		});
	} finally {
		isSlotAssignmentPending.value = false;
		pendingSwapSlotIds.value = [];
	}
};

onMounted(() => {
	void fetchManagementData();
	subscribeToManagementChannel();
	window.addEventListener('fullparty:activity-slot-returned-to-queue', handleSlotReturnedToQueue as EventListener);
});

onBeforeUnmount(() => {
	const echo = (window as Window & {
		Echo?: {
			leave?: (channelName: string) => void
		}
	}).Echo;

	if (subscribedManagementChannelName.value && echo?.leave) {
		echo.leave(subscribedManagementChannelName.value);
	}

	window.removeEventListener('fullparty:activity-slot-returned-to-queue', handleSlotReturnedToQueue as EventListener);
});
</script>

<template>
	<div class="w-full">
		<UButton
			:label="t('groups.activities.back')"
			icon="i-lucide-arrow-left"
			variant="ghost"
			color="neutral"
			@click.stop="goBack"
		/>

		<div class="mt-4">
			<ActivityOverview
				v-if="currentActivity"
				:title="activityTitle"
				:status="currentActivity.status"
				:can-edit="canEditActivity"
				:can-schedule="canScheduleActivityAction"
				:can-complete="canCompleteActivityAction"
				:can-publish-roster="canPublishRoster"
				:can-cancel="canCancelActivity"
				:roster-view="rosterView"
				:show-applicant-queue="showApplicantQueue"
				:group-name="group.name"
				:activity-type-name="activityTypeName"
				:starts-at="currentActivity.starts_at"
				:duration-hours="currentActivity.duration_hours"
				:organizer-name="organizerName"
				:organizer-avatar-url="organizerAvatarUrl"
				:slot-count="currentActivity.slot_count"
				:assigned-count="assignedCount"
				:pending-application-count="pendingApplicationCount"
				:needs-application="currentActivity.needs_application"
				:description="currentActivity.description"
				:notes="currentActivity.notes"
				:completed-progression="completedProgression"
				@edit="goToEditPage"
				@view-overview="goToOverviewPage"
				@go-to-application="goToApplicationPage"
				@copy-application-link="copyApplicationLink"
				@export-roster="exportRoster"
				@schedule="scheduleActivity"
				@complete="completeActivity"
				@publish-roster="publishRoster"
				@cancel="cancelActivity"
				@update-roster-view="rosterView = $event"
				@toggle-applicant-queue="showApplicantQueue = !showApplicantQueue"
			/>

			<section
				v-else-if="isLoading"
				class="border border-default bg-muted dark:bg-elevated/50 px-5 py-5 shadow-sm"
			>
				<div class="flex flex-col gap-4">
					<div class="flex flex-col gap-4 border-b border-default pb-4 xl:flex-row xl:items-start xl:justify-between">
						<div class="flex flex-col gap-3">
							<div class="flex flex-wrap items-center gap-3">
								<USkeleton class="h-8 w-64" />
								<USkeleton class="h-6 w-24" />
								<USkeleton class="h-6 w-32" />
							</div>
						</div>

						<div class="flex flex-wrap items-center gap-2 xl:justify-end">
							<USkeleton class="h-10 w-28" />
							<USkeleton class="h-10 w-32" />
						</div>
					</div>

					<div class="flex flex-wrap items-center gap-x-6 gap-y-3">
						<USkeleton class="h-5 w-32" />
						<USkeleton class="h-5 w-40" />
						<USkeleton class="h-5 w-28" />
					</div>

					<div class="flex flex-wrap items-center gap-x-6 gap-y-3 border-t border-default pt-4">
						<USkeleton class="h-5 w-36" />
						<USkeleton class="h-5 w-40" />
						<USkeleton class="h-5 w-44" />
					</div>

					<div class="flex flex-col gap-3 border-t border-default pt-4">
						<USkeleton class="h-4 w-full" />
						<USkeleton class="h-4 w-11/12" />
						<USkeleton class="h-4 w-3/4" />
					</div>
				</div>
			</section>

			<UAlert
				v-else
				color="error"
				variant="soft"
				:title="t('general.error')"
				:description="t('groups.activities.management.messages.load_failed')"
			>
				<template #actions>
					<UButton
						color="error"
						variant="outline"
						size="sm"
						icon="i-lucide-refresh-cw"
						:label="t('groups.activities.management.messages.retry')"
						@click="fetchManagementData"
					/>
				</template>
			</UAlert>
		</div>

		<div class="mt-6 flex flex-col gap-6 xl:flex-row xl:items-start">
			<div class="min-w-0 flex-1">
				<RosterAssignments
					v-if="currentActivity"
					:view="rosterView"
					:slots="currentActivity.slots"
					:is-swap-pending="isSlotSwapPending || isSlotAssignmentPending"
					:pending-swap-slot-ids="pendingSwapSlotIds"
					:can-return-to-queue="!isActivityArchived"
					:can-mark-missing="!isActivityArchived"
					:can-check-in="!isActivityArchived"
					@swap-slots="handleSlotSwap"
					@assign-application-to-slot="openAssignmentModal"
					@click-slot="openSlotEditModal"
					@return-slot-to-queue="returnSlotToQueue"
					@move-slot-to-bench="moveSlotToBench"
					@mark-slot-missing="markSlotMissing"
					@check-in-slot="checkInSlot"
					@mark-slot-late="markSlotLate"
					@check-in-group="checkInGroup"
				/>

				<section
					v-if="missingAssignments.length > 0"
					class="mt-6 border border-default bg-muted shadow-sm dark:bg-elevated/50"
				>
					<div class="border-b border-default px-5 py-4">
						<div class="flex items-center gap-3">
							<div class="flex h-9 w-9 items-center justify-center rounded-sm bg-error text-inverted">
								<UIcon name="i-lucide-user-x" class="size-4" />
							</div>
							<div class="flex items-center gap-3">
								<h3 class="font-semibold text-lg text-toned">
									{{ t('groups.activities.management.messages.missing_title') }}
								</h3>
								<UBadge color="error" variant="soft" :label="String(missingAssignments.length)" />
							</div>
						</div>
					</div>

					<div class="flex flex-col gap-3 px-5 py-5">
						<div
							v-for="entry in missingAssignments"
							:key="entry.id"
							class="flex flex-col gap-2 border border-default bg-default/60 p-4 md:flex-row md:items-center md:justify-between"
						>
							<div class="flex items-center gap-3">
								<UAvatar
									v-if="entry.character?.avatar_url"
									:src="entry.character.avatar_url"
									size="lg"
									alt=""
								/>
								<div class="flex flex-col gap-1">
									<p class="font-medium text-toned">
										{{ entry.character?.name ?? t('groups.activities.management.messages.unknown_character') }}
									</p>
									<p class="text-sm text-muted">
										{{ entry.character?.world || t('groups.activities.management.messages.unknown_world') }}
									</p>
								</div>
							</div>

							<div class="flex flex-col gap-1 text-sm text-muted md:items-end">
								<p>
									{{ localizedValue(entry.group_label, locale, fallbackLocale) || localizedValue(entry.slot_label, locale, fallbackLocale) || t('groups.activities.management.messages.unknown_slot') }}
								</p>
								<p v-if="entry.marked_missing_at">
									{{ new Intl.DateTimeFormat(locale, {
										year: 'numeric',
										month: '2-digit',
										day: '2-digit',
										hour: '2-digit',
										minute: '2-digit',
									}).format(new Date(entry.marked_missing_at)) }}
								</p>
							</div>

							<div class="md:ml-4 md:self-center">
								<UButton
									:label="t('groups.activities.management.messages.undo_missing')"
									icon="i-lucide-rotate-ccw"
									variant="soft"
									color="neutral"
									size="sm"
									:loading="pendingMissingUndoIds.includes(entry.id)"
									:disabled="isActivityArchived"
									@click="undoMissingAssignment(entry.id)"
								/>
							</div>
						</div>
					</div>
				</section>

				<section v-else-if="isLoading" class="flex flex-col gap-4 transition-all duration-300 ease-in-out">
					<h2 class="font-semibold text-lg text-toned">
						{{ t('groups.activities.management.roster.title') }}
					</h2>

					<div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
						<USkeleton class="h-36 w-full" />
						<USkeleton class="h-36 w-full" />
						<USkeleton class="h-36 w-full" />
						<USkeleton class="h-36 w-full" />
						<USkeleton class="h-36 w-full" />
						<USkeleton class="h-36 w-full" />
					</div>
				</section>
			</div>

			<div
				class="sticky top-4 self-start overflow-hidden transition-all duration-300 ease-in-out"
				:class="showApplicantQueue
					? 'xl:w-96 xl:opacity-100'
					: 'xl:w-0 xl:opacity-0 xl:pointer-events-none'"
			>
				<div class="w-full min-w-0">
					<ApplicantQueue
						:group-slug="group.slug"
						:activity-id="activity.id"
						:activity-status="currentActivity?.status"
						:initial-pending-application-count="currentActivity?.pending_application_count"
					/>
				</div>
			</div>
		</div>

		<AssignApplicantToSlotModal
			v-model:open="assignmentModalOpen"
			:slot="assignmentModalSlot"
			:application="assignmentModalApplication"
			:slot-field-definitions="currentActivity?.slot_field_definitions ?? []"
			:mode="assignmentModalMode"
			:is-submitting="isSlotAssignmentPending"
			@confirm="handleAssignApplicantToSlot"
		/>

		<ManualAssignCharacterToSlotModal
			v-model:open="manualAssignmentModalOpen"
			:slot="manualAssignmentModalSlot"
			:characters="manualAssignmentCharacters"
			:slot-field-definitions="currentActivity?.slot_field_definitions ?? []"
			:is-submitting="isSlotAssignmentPending"
			:initial-character-id="manualAssignmentInitialCharacterId"
			:lock-character="lockManualAssignmentCharacter"
			@confirm="handleAssignCharacterToSlot"
		/>

		<CompleteActivityModal
			v-model:open="isCompleteModalOpen"
			:group-slug="group.slug"
			:activity-id="activity.id"
			:is-submitting="isCompletingActivity"
			:can-use-fflogs-completion="currentActivity?.can_use_fflogs_completion ?? false"
			:prog-points="currentActivity?.prog_points ?? []"
			:progress-milestones="currentActivity?.progress_milestones ?? []"
			:errors="completionErrors"
			@confirm="confirmCompleteActivity"
		/>

		<UModal
			v-model:open="isScheduleConfirmOpen"
			:title="t('groups.activities.management.schedule_activity_modal.title')"
			:description="t('groups.activities.management.schedule_activity_confirm')"
		>
			<template #body>
				<p class="text-sm text-muted">
					{{ t('groups.activities.management.schedule_activity_modal.body') }}
				</p>
			</template>

			<template #footer>
				<div class="flex w-full items-center justify-end gap-3">
					<UButton
						color="neutral"
						variant="ghost"
						:label="t('general.cancel')"
						@click="isScheduleConfirmOpen = false"
					/>
					<UButton
						color="primary"
						icon="i-lucide-calendar-check-2"
						:label="t('groups.activities.management.schedule_activity_modal.confirm')"
						:loading="isSchedulingActivity"
						@click="confirmScheduleActivity"
					/>
				</div>
			</template>
		</UModal>

		<UModal
			v-model:open="isCancelConfirmOpen"
			:title="t('groups.activities.management.cancel_activity_modal.title')"
			:description="t('groups.activities.management.cancel_activity_confirm')"
		>
			<template #body>
				<p class="text-sm text-muted">
					{{ t('groups.activities.management.cancel_activity_modal.body') }}
				</p>
			</template>

			<template #footer>
				<div class="flex w-full items-center justify-end gap-3">
					<UButton
						color="neutral"
						variant="ghost"
						:label="t('general.cancel')"
						@click="isCancelConfirmOpen = false"
					/>
					<UButton
						color="error"
						icon="i-lucide-ban"
						:label="t('groups.activities.management.cancel_activity_modal.confirm')"
						:loading="isCancellingActivity"
						@click="confirmCancelActivity"
					/>
				</div>
			</template>
		</UModal>
	</div>
</template>
