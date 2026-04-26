<script setup lang="ts">
import axios from "axios";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { router, usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import { route } from "ziggy-js";
import { useToast } from "@nuxt/ui/composables";
import ActivityOverview from "@/components/Groups/Activities/ActivityOverview.vue";
import RosterAssignments from "@/components/Groups/Activities/RosterAssignments.vue";
import ApplicantQueue from "@/components/Groups/Activities/ApplicantQueue.vue";
import AssignApplicantToSlotModal from "@/components/Groups/Activities/AssignApplicantToSlotModal.vue";
import type { QueueApplication, QueueFilterField } from "@/components/Groups/Activities/queueTypes";
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
	is_public: boolean
	needs_application: boolean
	secret_key: string | null
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
	application_count: number
	pending_application_count: number
	progress_milestone_count: number
	slot_field_definitions: QueueFilterField[]
	slots: ActivitySlot[]
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
const assignmentModalApplication = ref<QueueApplication | null>(null);
const assignmentModalSlotId = ref<number | null>(null);
const assignmentModalMode = ref<'assign' | 'edit'>('assign');
const activityData = ref<ActivityDetails | null>(null);

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
const canEditActivity = computed(() => currentActivity.value?.status !== 'complete');
const organizerName = computed(() => currentActivity.value?.organized_by_character?.name || null);
const organizerAvatarUrl = computed(() => currentActivity.value?.organized_by_character?.avatar_url || null);
const assignedCount = computed(() => currentActivity.value?.slots.filter((slot) => slot.assigned_character_id !== null).length ?? 0);
const pendingApplicationCount = computed(() => currentActivity.value?.pending_application_count ?? 0);
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

const handleSlotSwap = async (payload: { sourceSlotId: number, targetSlotId: number }) => {
	if (!currentActivity.value || isSlotSwapPending.value || isSlotAssignmentPending.value) {
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
		});

		const updatedSlots = response.data?.slots ?? [];

		if (updatedSlots.length > 0) {
			const updatedSlotsById = new Map(updatedSlots.map((slot: ActivitySlot) => [slot.id, slot]));

			activityData.value = {
				...currentActivity.value,
				slots: currentActivity.value.slots.map((slot) => updatedSlotsById.get(slot.id) ?? slot),
			};
		}
	} catch (error) {
		console.error(error);
		toast.add({
			title: t('general.error'),
			description: 'Unable to swap roster slots.',
			color: 'error',
			icon: 'i-lucide-octagon-alert',
		});
	} finally {
		isSlotSwapPending.value = false;
		pendingSwapSlotIds.value = [];
	}
};

const openAssignmentModal = (payload: { slotId: number, application: QueueApplication }) => {
	if (isSlotSwapPending.value || isSlotAssignmentPending.value) {
		return;
	}

	assignmentModalMode.value = 'assign';
	assignmentModalSlotId.value = payload.slotId;
	assignmentModalApplication.value = payload.application;
};

const closeAssignmentModal = () => {
	assignmentModalMode.value = 'assign';
	assignmentModalSlotId.value = null;
	assignmentModalApplication.value = null;
};

const openSlotEditModal = async (slotId: number) => {
	if (!currentActivity.value || isSlotSwapPending.value || isSlotAssignmentPending.value) {
		return;
	}

	const slot = currentActivity.value.slots.find((entry) => entry.id === slotId);

	if (!slot?.assigned_character_id) {
		return;
	}

	try {
		const response = await axios.get(route('groups.dashboard.activities.slot-assignments.context', {
			group: props.group.slug,
			activity: props.activity.id,
			slot: slotId,
		}));

		assignmentModalMode.value = 'edit';
		assignmentModalSlotId.value = slotId;
		assignmentModalApplication.value = response.data?.application ?? null;
	} catch (error) {
		console.error(error);
		toast.add({
			title: t('general.error'),
			description: 'Unable to load slot assignment details.',
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
		slots: currentActivity.value.slots.map((slot) => slot.id === updatedSlot.id ? updatedSlot : slot),
	};
};

const handleAssignApplicantToSlot = async (payload: { applicationId: number, slotId: number, fieldValues: Record<string, string | string[]> }) => {
	if (!currentActivity.value || isSlotAssignmentPending.value) {
		return;
	}

	isSlotAssignmentPending.value = true;
	pendingSwapSlotIds.value = [payload.slotId];

	try {
		const response = await axios.post(route('groups.dashboard.activities.slot-assignments.store', {
			group: props.group.slug,
			activity: props.activity.id,
			slot: payload.slotId,
		}), {
			application_id: payload.applicationId,
			field_values: payload.fieldValues,
		});

		const updatedSlot = response.data?.slot ?? null;

		if (updatedSlot) {
			activityData.value = {
				...currentActivity.value,
				slots: currentActivity.value.slots.map((slot) => slot.id === updatedSlot.id ? updatedSlot : slot),
			};
		}

		window.dispatchEvent(new CustomEvent('fullparty:activity-application-assigned', {
			detail: {
				applicationId: payload.applicationId,
			},
		}));

		closeAssignmentModal();
	} catch (error) {
		console.error(error);
		toast.add({
			title: t('general.error'),
			description: 'Unable to assign this application to the roster slot.',
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
	window.addEventListener('fullparty:activity-slot-returned-to-queue', handleSlotReturnedToQueue as EventListener);
});

onBeforeUnmount(() => {
	window.removeEventListener('fullparty:activity-slot-returned-to-queue', handleSlotReturnedToQueue as EventListener);
});
</script>

<template>
	<div class="w-full overflow-x-hidden">
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
				@edit="goToEditPage"
				@view-overview="goToOverviewPage"
				@go-to-application="goToApplicationPage"
				@copy-application-link="copyApplicationLink"
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
				description="Unable to load activity details."
			>
				<template #actions>
					<UButton
						color="error"
						variant="outline"
						size="sm"
						icon="i-lucide-refresh-cw"
						label="Retry"
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
					@swap-slots="handleSlotSwap"
					@assign-application-to-slot="openAssignmentModal"
					@click-slot="openSlotEditModal"
				/>

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
				class="sticky top-4 self-start transition-all duration-300 ease-in-out"
				:class="showApplicantQueue
					? 'xl:w-96 xl:opacity-100'
					: 'xl:w-0 xl:opacity-0 xl:pointer-events-none'"
			>
				<div class="w-full min-w-0 xl:w-96">
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
	</div>
</template>
