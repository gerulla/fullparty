<script setup lang="ts">
import axios from "axios";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import { localizedValue } from "@/utils/localizedValue";
import { isArchivedActivityStatus } from "@/utils/activityLifecycle";
import { route } from "ziggy-js";
import ApplicantQueueItem from "@/components/Groups/Activities/ApplicantQueueItem.vue";
import ApplicantQueueDetailsModal from "@/components/Groups/Activities/ApplicantQueueDetailsModal.vue";
import { getRosterSlotDragData, isRosterSlotDrag } from "@/components/Groups/Activities/rosterDragData";
import MembersNotesModal from "@/components/Shared/Notes/MembersNotesModal.vue";
import { useMemberNotes } from "@/composables/useMemberNotes";
import type { LocalizedText } from "@/Types/Common";
import type {
	QueueApplication,
	QueueFilterField,
	QueueFilterMilestone,
} from "@/Types/ActivityQueue";

type QueueEventNotice = {
	id: number
	label: string
	icon: string
	color: "primary" | "warning" | "error"
};

const props = defineProps<{
	groupSlug: string
	activityId: number
	initialPendingApplicationCount?: number
	activityStatus?: string | null
}>();

const { t, locale } = useI18n();
const toast = useToast();
const page = usePage();
const isLoading = ref(true);
const fflogsZoneId = ref<number | null>(null);
const applications = ref<QueueApplication[]>([]);
const queueFilters = ref<{
	slot_fields: QueueFilterField[]
	milestones: QueueFilterMilestone[]
}>({
	slot_fields: [],
	milestones: [],
});
const searchTerm = ref('');
const sortMode = ref<'oldest' | 'newest' | 'most_group_runs' | 'least_group_runs'>('oldest');
const areFiltersOpen = ref(false);
const milestoneFilter = ref<string[]>([]);
const slotFieldFilters = ref<Record<string, string[]>>({});
const minimumKnowledgeLevel = ref('');
const minimumPhantomMastery = ref('');
const isQueueDropActive = ref(false);
const isReturningSlot = ref(false);
const isApplicationModalOpen = ref(false);
const selectedApplication = ref<QueueApplication | null>(null);
const memberNotes = useMemberNotes({
	groupSlug: computed(() => props.groupSlug),
});
const newApplicationNoticeCount = ref(0);
const queueEventNotices = ref<QueueEventNotice[]>([]);
let queueRefreshTimeout: number | null = null;
let nextQueueEventNoticeId = 1;
const announcedNewApplicationIds = new Set<number>();

const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));

const newApplicationCountLabel = (count: number) => t(
	count === 1
		? 'groups.activities.management.queue.new_application_notice_one'
		: 'groups.activities.management.queue.new_application_notice_many',
	{ count },
);

const newApplicationNoticeLabel = computed(() => newApplicationCountLabel(newApplicationNoticeCount.value));

const applicationEditedLabel = (name: string) => t(
	'groups.activities.management.queue.application_edited_notice',
	{ name },
);

const applicationWithdrawnLabel = (name: string) => t(
	'groups.activities.management.queue.application_withdrawn_notice',
	{ name },
);

const localizedText = (value: LocalizedText, fallback: string) => (
	localizedValue(value, locale.value, fallbackLocale.value) || fallback
);

const slotFieldFilterItems = computed(() => queueFilters.value.slot_fields.map((field) => ({
	...field,
	labelText: localizedText(field.label, field.key),
	items: (field.filter_options?.length ? field.filter_options : field.options).map((option) => ({
		label: localizedText(option.label, option.key),
		value: option.key,
	})),
})));

const milestoneFilterItems = computed(() => queueFilters.value.milestones.map((milestone) => ({
	label: localizedText(milestone.label, milestone.key),
	value: milestone.key,
})));
const sortItems = computed(() => [
	{
		label: t('groups.activities.management.queue.sort.oldest'),
		value: 'oldest',
	},
	{
		label: t('groups.activities.management.queue.sort.newest'),
		value: 'newest',
	},
	{
		label: t('groups.activities.management.queue.sort.most_group_runs'),
		value: 'most_group_runs',
	},
	{
		label: t('groups.activities.management.queue.sort.least_group_runs'),
		value: 'least_group_runs',
	},
]);

const normalizedMinimumKnowledgeLevel = computed(() => {
	const parsed = Number.parseInt(minimumKnowledgeLevel.value, 10);

	return Number.isNaN(parsed) ? null : parsed;
});

const normalizedMinimumPhantomMastery = computed(() => {
	const parsed = Number.parseInt(minimumPhantomMastery.value, 10);

	return Number.isNaN(parsed) ? null : parsed;
});

const activeFilterCount = computed(() => {
	const slotFieldCount = Object.values(slotFieldFilters.value)
		.filter((values) => values.length > 0)
		.length;
	const scalarCount = [normalizedMinimumKnowledgeLevel.value, normalizedMinimumPhantomMastery.value]
		.filter((value) => value !== null)
		.length;

	return slotFieldCount + scalarCount + (milestoneFilter.value.length > 0 ? 1 : 0);
});

const normalizeAnswerValues = (rawValue: unknown): string[] => {
	if (Array.isArray(rawValue)) {
		return rawValue
			.map((value) => String(value))
			.filter((value) => value !== '');
	}

	if (rawValue === null || rawValue === undefined || rawValue === '') {
		return [];
	}

	return [String(rawValue)];
};

const submittedAtTimestamp = (application: QueueApplication): number => {
	if (!application.submitted_at) {
		return Number.MAX_SAFE_INTEGER;
	}

	const timestamp = new Date(application.submitted_at).getTime();

	return Number.isNaN(timestamp) ? Number.MAX_SAFE_INTEGER : timestamp;
};

const groupRunCount = (application: QueueApplication): number => application.user_stats?.group_run_count ?? 0;

const sortApplications = (items: QueueApplication[]): QueueApplication[] => [...items].sort((left, right) => {
	if (sortMode.value === 'newest') {
		return submittedAtTimestamp(right) - submittedAtTimestamp(left);
	}

	if (sortMode.value === 'most_group_runs') {
		const groupRunDiff = groupRunCount(right) - groupRunCount(left);

		return groupRunDiff !== 0 ? groupRunDiff : submittedAtTimestamp(left) - submittedAtTimestamp(right);
	}

	if (sortMode.value === 'least_group_runs') {
		const groupRunDiff = groupRunCount(left) - groupRunCount(right);

		return groupRunDiff !== 0 ? groupRunDiff : submittedAtTimestamp(left) - submittedAtTimestamp(right);
	}

	return submittedAtTimestamp(left) - submittedAtTimestamp(right);
});

const updateSlotFieldFilter = (fieldKey: string, value: string[] | undefined) => {
	slotFieldFilters.value = {
		...slotFieldFilters.value,
		[fieldKey]: value ?? [],
	};
};

const clearFilters = () => {
	slotFieldFilters.value = {};
	milestoneFilter.value = [];
	minimumKnowledgeLevel.value = '';
	minimumPhantomMastery.value = '';
};

const canAcceptRosterDrop = computed(() => !isArchivedActivityStatus(props.activityStatus) && !isReturningSlot.value);

const showNewApplicationNotice = (count: number) => {
	if (count <= 0) {
		return;
	}

	newApplicationNoticeCount.value += count;
	toast.add({
		title: newApplicationCountLabel(count),
		color: 'primary',
		icon: 'i-lucide-inbox',
	});
};

const showQueueEventNotice = (
	label: string,
	color: QueueEventNotice["color"],
	icon: string,
) => {
	queueEventNotices.value = [
		{
			id: nextQueueEventNoticeId,
			label,
			color,
			icon,
		},
		...queueEventNotices.value,
	].slice(0, 3);
	nextQueueEventNoticeId += 1;

	toast.add({
		title: label,
		color,
		icon,
	});
};

const fetchQueuePayload = async (options: { announceNewApplications?: boolean } = {}) => {
	isLoading.value = true;

	try {
		const previousApplicationIds = new Set(applications.value.map((application) => application.id));
		const response = await axios.get(route('groups.dashboard.activities.applicant-queue', {
			group: props.groupSlug,
			activity: props.activityId,
		}));
		const nextApplications = response.data?.applications ?? [];

		fflogsZoneId.value = response.data?.fflogs_zone_id ?? null;
		applications.value = nextApplications;
		queueFilters.value = response.data?.queue_filters ?? {
			slot_fields: [],
			milestones: [],
		};

		if (options.announceNewApplications && previousApplicationIds.size > 0) {
			const newPendingApplications = nextApplications.filter((application: QueueApplication) => (
				application.status === 'pending'
				&& !previousApplicationIds.has(application.id)
				&& !announcedNewApplicationIds.has(application.id)
			));

			for (const application of newPendingApplications) {
				announcedNewApplicationIds.add(application.id);
			}

			showNewApplicationNotice(newPendingApplications.length);
		}
	} catch (error) {
		console.error(error);
		fflogsZoneId.value = null;
		applications.value = [];
		queueFilters.value = {
			slot_fields: [],
			milestones: [],
		};
	} finally {
		isLoading.value = false;
	}
};

const clearQueueRefreshTimeout = () => {
	if (queueRefreshTimeout === null) {
		return;
	}

	window.clearTimeout(queueRefreshTimeout);
	queueRefreshTimeout = null;
};

const scheduleQueueRefresh = (options: { announceNewApplications?: boolean } = {}) => {
	clearQueueRefreshTimeout();

	queueRefreshTimeout = window.setTimeout(() => {
		queueRefreshTimeout = null;
		void fetchQueuePayload(options);
	}, 250);
};

const dismissNewApplicationNotice = () => {
	newApplicationNoticeCount.value = 0;
};

const dismissQueueEventNotice = (noticeId: number) => {
	queueEventNotices.value = queueEventNotices.value.filter((notice) => notice.id !== noticeId);
};

const fetchQueueApplication = async (applicationId: number): Promise<QueueApplication | null> => {
	try {
		const response = await axios.get(route('groups.dashboard.activities.applicant-queue.application', {
			group: props.groupSlug,
			activity: props.activityId,
			application: applicationId,
		}));

		return response.data?.application ?? null;
	} catch (error) {
		console.error(error);
		return null;
	}
};

const upsertApplication = (application: QueueApplication) => {
	const existingIndex = applications.value.findIndex((entry) => entry.id === application.id);

	if (existingIndex === -1) {
		applications.value = [application, ...applications.value];
		return;
	}

	const nextApplications = [...applications.value];
	nextApplications.splice(existingIndex, 1, application);
	applications.value = nextApplications;
};

const handleApplicationAssigned = (event: Event) => {
	const customEvent = event as CustomEvent<{ applicationId?: number }>;
	const assignedApplicationId = customEvent.detail?.applicationId;

	if (!assignedApplicationId) {
		return;
	}

	applications.value = applications.value.filter((application) => application.id !== assignedApplicationId);

	if (selectedApplication.value?.id === assignedApplicationId) {
		selectedApplication.value = null;
		isApplicationModalOpen.value = false;
	}
};

const handleApplicationReturned = (event: Event) => {
	const customEvent = event as CustomEvent<{ application?: QueueApplication }>;
	const restoredApplication = customEvent.detail?.application;

	if (!restoredApplication) {
		return;
	}

	applications.value = [
		restoredApplication,
		...applications.value.filter((application) => application.id !== restoredApplication.id),
	];
};

const handleManagementQueueSync = async (event: Event) => {
	const customEvent = event as CustomEvent<{
		syncApplicationIds?: number[]
		removeApplicationIds?: number[]
		invalidate?: boolean
		reason?: string | null
		newApplicationCount?: number
		newApplicationIds?: number[]
		updatedApplicationNames?: string[]
		withdrawnApplicationNames?: string[]
	}>;
	const syncApplicationIds = customEvent.detail?.syncApplicationIds ?? [];
	const removeApplicationIds = new Set(customEvent.detail?.removeApplicationIds ?? []);
	const newApplicationCount = Number(customEvent.detail?.newApplicationCount ?? 0);
	const newApplicationIds = customEvent.detail?.newApplicationIds ?? [];
	const updatedApplicationNames = customEvent.detail?.updatedApplicationNames ?? [];
	const withdrawnApplicationNames = customEvent.detail?.withdrawnApplicationNames ?? [];

	for (const applicationId of newApplicationIds) {
		announcedNewApplicationIds.add(applicationId);
	}

	if (Number.isFinite(newApplicationCount) && newApplicationCount > 0) {
		showNewApplicationNotice(newApplicationCount);
	}

	for (const name of updatedApplicationNames.filter((value) => value.trim() !== '')) {
		showQueueEventNotice(applicationEditedLabel(name), 'warning', 'i-lucide-pencil');
	}

	for (const name of withdrawnApplicationNames.filter((value) => value.trim() !== '')) {
		showQueueEventNotice(applicationWithdrawnLabel(name), 'error', 'i-lucide-user-minus');
	}

	if (removeApplicationIds.size > 0) {
		applications.value = applications.value.filter((application) => !removeApplicationIds.has(application.id));

		if (selectedApplication.value && removeApplicationIds.has(selectedApplication.value.id)) {
			selectedApplication.value = null;
			isApplicationModalOpen.value = false;
		}
	}

	if (customEvent.detail?.invalidate) {
		scheduleQueueRefresh();
		return;
	}

	if (syncApplicationIds.length === 0) {
		return;
	}

	const refreshedApplications = await Promise.all(syncApplicationIds.map((applicationId) => fetchQueueApplication(applicationId)));

	for (let index = 0; index < refreshedApplications.length; index += 1) {
		const refreshedApplication = refreshedApplications[index];
		const applicationId = syncApplicationIds[index];

		if (!refreshedApplication || refreshedApplication.status !== 'pending') {
			applications.value = applications.value.filter((application) => application.id !== applicationId);

			if (selectedApplication.value?.id === applicationId) {
				selectedApplication.value = null;
				isApplicationModalOpen.value = false;
			}

			continue;
		}

		upsertApplication(refreshedApplication);

		if (selectedApplication.value?.id === refreshedApplication.id) {
			selectedApplication.value = refreshedApplication;
		}
	}
};

const handleApplicationDeclined = (applicationId: number) => {
	applications.value = applications.value.filter((application) => application.id !== applicationId);

	if (selectedApplication.value?.id === applicationId) {
		selectedApplication.value = null;
	}
};

const handleApplicationRefreshed = (application: QueueApplication) => {
	upsertApplication(application);
	selectedApplication.value = application;
};

const handleDragOver = (event: DragEvent) => {
	if (!canAcceptRosterDrop.value || !isRosterSlotDrag(event)) {
		return;
	}

	event.preventDefault();
	isQueueDropActive.value = true;

	if (event.dataTransfer) {
		event.dataTransfer.dropEffect = 'move';
	}
};

const handleDragLeave = () => {
	isQueueDropActive.value = false;
};

const handleDrop = async (event: DragEvent) => {
	isQueueDropActive.value = false;

	if (!canAcceptRosterDrop.value) {
		return;
	}

	const droppedSlot = getRosterSlotDragData(event);

	if (!droppedSlot?.id) {
		return;
	}

	event.preventDefault();
	isReturningSlot.value = true;

	try {
		const response = await axios.post(route('groups.dashboard.activities.slot-unassignments.store', {
			group: props.groupSlug,
			activity: props.activityId,
			slot: droppedSlot.id,
		}), {
			expected_slot_state_token: droppedSlot.state_token,
		});

		window.dispatchEvent(new CustomEvent('fullparty:activity-slot-returned-to-queue', {
			detail: {
				slot: response.data?.slot ?? null,
				pendingApplicationCount: response.data?.pending_application_count,
			},
		}));

		window.dispatchEvent(new CustomEvent('fullparty:activity-application-returned', {
			detail: {
				application: response.data?.application ?? null,
			},
		}));
	} catch (error) {
		console.error(error);
	} finally {
		isReturningSlot.value = false;
	}
};

const openApplicationDetails = (application: QueueApplication) => {
	selectedApplication.value = application;
	isApplicationModalOpen.value = true;
};

const refreshQueueWhenVisible = () => {
	if (document.visibilityState !== 'visible') {
		return;
	}

	scheduleQueueRefresh({ announceNewApplications: true });
};

onMounted(() => {
	void fetchQueuePayload();
	window.addEventListener('focus', refreshQueueWhenVisible);
	document.addEventListener('visibilitychange', refreshQueueWhenVisible);
	window.addEventListener('fullparty:activity-application-assigned', handleApplicationAssigned as EventListener);
	window.addEventListener('fullparty:activity-application-returned', handleApplicationReturned as EventListener);
	window.addEventListener('fullparty:activity-management-queue-sync', handleManagementQueueSync as EventListener);
});

onBeforeUnmount(() => {
	clearQueueRefreshTimeout();
	window.removeEventListener('focus', refreshQueueWhenVisible);
	document.removeEventListener('visibilitychange', refreshQueueWhenVisible);
	window.removeEventListener('fullparty:activity-application-assigned', handleApplicationAssigned as EventListener);
	window.removeEventListener('fullparty:activity-application-returned', handleApplicationReturned as EventListener);
	window.removeEventListener('fullparty:activity-management-queue-sync', handleManagementQueueSync as EventListener);
});

const visibleApplications = computed(() => {
	const filteredByStatus = applications.value.filter((application) => application.status === 'pending');
	const filteredByKnowledge = filteredByStatus.filter((application) => {
		if (normalizedMinimumKnowledgeLevel.value === null) {
			return true;
		}

		return (application.selected_character?.occult_level ?? -1) >= normalizedMinimumKnowledgeLevel.value;
	});
	const filteredByPhantomMastery = filteredByKnowledge.filter((application) => {
		if (normalizedMinimumPhantomMastery.value === null) {
			return true;
		}

		return (application.selected_character?.phantom_mastery ?? -1) >= normalizedMinimumPhantomMastery.value;
	});
	const normalizedSearchTerm = searchTerm.value.trim().toLowerCase();
	const searchedApplications = !normalizedSearchTerm
		? filteredByPhantomMastery
		: filteredByPhantomMastery.filter((application) => {
		const applicantName = application.user?.name?.toLowerCase() ?? '';
		const characterName = application.selected_character?.name?.toLowerCase()
			?? application.applicant_character?.name?.toLowerCase()
			?? '';

		return applicantName.includes(normalizedSearchTerm) || characterName.includes(normalizedSearchTerm);
	});

	const filteredApplications = searchedApplications.filter((application) => {
		const matchesSlotFields = slotFieldFilterItems.value.every((field) => {
			const selectedValues = slotFieldFilters.value[field.key] ?? [];

			if (selectedValues.length === 0) {
				return true;
			}

			const answer = application.answers.find((entry) => entry.question_key === field.application_key);

			if (!answer) {
				return false;
			}

			const answerValues = normalizeAnswerValues(answer.raw_value);

			return selectedValues.some((selectedValue) => answerValues.includes(selectedValue));
		});

		if (!matchesSlotFields) {
			return false;
		}

		if (milestoneFilter.value.length > 0) {
			const reachedMilestones = application.progress_milestones
				.filter((milestone) => milestone.reached)
				.map((milestone) => milestone.key);

			if (!milestoneFilter.value.some((milestoneKey) => reachedMilestones.includes(milestoneKey))) {
				return false;
			}
		}

		return true;
	});

	return sortApplications(filteredApplications);
});
</script>

<template>
	<aside
		class="flex w-full max-h-[calc(100vh-2rem)] flex-col border border-default bg-muted transition duration-200 dark:bg-elevated/50 xl:max-w-96"
		:class="isQueueDropActive ? 'border-white shadow-[0_0_0_2px_rgba(255,255,255,0.95),0_0_0_10px_rgba(255,255,255,0.12)]' : ''"
		@dragover="handleDragOver"
		@dragleave="handleDragLeave"
		@drop="handleDrop"
	>
		<div class="border-b border-default px-4 py-4">
			<div class="flex items-center justify-between gap-3">
				<div class="flex items-center gap-3">
					<h2 class="font-semibold text-sm uppercase tracking-[0.12em] text-toned">
						{{ t('groups.activities.management.queue.title') }}
					</h2>
					<UBadge
						color="primary"
						variant="soft"
						:label="String(isLoading ? (initialPendingApplicationCount ?? 0) : visibleApplications.length)"
					/>
				</div>
			</div>

			<div
				v-if="newApplicationNoticeCount > 0"
				class="mt-3 flex items-center justify-between gap-3 border border-primary/40 bg-primary/10 px-3 py-2 text-sm text-highlighted"
			>
				<div class="flex items-center gap-2">
					<UIcon name="i-lucide-inbox" class="size-4 text-primary" />
					<span>{{ newApplicationNoticeLabel }}</span>
				</div>
				<UButton
					color="neutral"
					variant="ghost"
					size="xs"
					icon="i-lucide-x"
					:aria-label="t('groups.activities.management.queue.dismiss_new_application_notice')"
					@click="dismissNewApplicationNotice"
				/>
			</div>

			<div v-if="queueEventNotices.length > 0" class="mt-3 flex flex-col gap-2">
				<div
					v-for="notice in queueEventNotices"
					:key="notice.id"
					class="flex items-center justify-between gap-3 border px-3 py-2 text-sm text-highlighted"
					:class="{
						'border-warning/40 bg-warning/10': notice.color === 'warning',
						'border-error/40 bg-error/10': notice.color === 'error',
						'border-primary/40 bg-primary/10': notice.color === 'primary',
					}"
				>
					<div class="flex items-center gap-2">
						<UIcon
							:name="notice.icon"
							class="size-4"
							:class="{
								'text-warning': notice.color === 'warning',
								'text-error': notice.color === 'error',
								'text-primary': notice.color === 'primary',
							}"
						/>
						<span>{{ notice.label }}</span>
					</div>
					<UButton
						color="neutral"
						variant="ghost"
						size="xs"
						icon="i-lucide-x"
						:aria-label="t('groups.activities.management.queue.dismiss_queue_event_notice')"
						@click="dismissQueueEventNotice(notice.id)"
					/>
				</div>
			</div>
		</div>

		<div class="border-b border-default px-4 py-4">
			<div
				v-if="canAcceptRosterDrop"
				class="mb-4 rounded-sm border border-dashed border-default px-3 py-2 text-xs uppercase tracking-[0.12em] text-muted"
				:class="isQueueDropActive ? 'border-white text-toned bg-white/5' : ''"
			>
				Drop a roster slot here to move it back to the applicant queue
			</div>

			<div class="flex flex-col gap-3">
				<UInput
					v-model="searchTerm"
					size="lg"
					icon="i-lucide-search"
					class="w-full"
					:placeholder="t('groups.activities.management.queue.search_placeholder')"
				/>

				<div class="flex items-center gap-3">
					<USelectMenu
						v-model="sortMode"
						size="lg"
						class="min-w-0 flex-1"
						:items="sortItems"
						value-key="value"
						:placeholder="t('groups.activities.management.queue.sort.label')"
						:search-input="false"
						:content="{ class: 'min-w-64' }"
					/>

					<UButton
						color="neutral"
						variant="soft"
						icon="i-lucide-sliders-horizontal"
						:label="activeFilterCount > 0
							? t('groups.activities.management.queue.filters_with_count', { count: activeFilterCount })
							: t('groups.activities.management.queue.filters')"
						@click="areFiltersOpen = !areFiltersOpen"
					/>
				</div>
			</div>

			<div v-if="areFiltersOpen" class="mt-4 space-y-4 border-t border-default pt-4">
				<div
					v-if="slotFieldFilterItems.length > 0"
					class="grid gap-4"
				>
					<UFormField
						v-for="field in slotFieldFilterItems"
						:key="field.key"
						:label="field.labelText"
					>
						<USelectMenu
							:model-value="slotFieldFilters[field.key] ?? []"
							multiple
							size="lg"
							class="w-full"
							:items="field.items"
							value-key="value"
							:placeholder="t('groups.activities.management.queue.filter_any')"
							@update:model-value="(value) => updateSlotFieldFilter(field.key, value)"
						/>
					</UFormField>
				</div>

				<UFormField
					v-if="milestoneFilterItems.length > 0"
					:label="t('groups.activities.management.queue.milestones_reached')"
				>
					<USelectMenu
						v-model="milestoneFilter"
						multiple
						size="lg"
						class="w-full"
						:items="milestoneFilterItems"
						value-key="value"
						:placeholder="t('groups.activities.management.queue.filter_any_milestone')"
					/>
				</UFormField>

				<div class="grid gap-4 sm:grid-cols-2">
					<UFormField :label="t('groups.activities.management.queue.min_knowledge_level')">
						<UInput
							v-model="minimumKnowledgeLevel"
							type="number"
							min="0"
							size="lg"
							class="w-full"
							:placeholder="t('groups.activities.management.queue.minimum_value_placeholder')"
						/>
					</UFormField>

					<UFormField :label="t('groups.activities.management.queue.min_phantom_mastery')">
						<UInput
							v-model="minimumPhantomMastery"
							type="number"
							min="0"
							size="lg"
							class="w-full"
							:placeholder="t('groups.activities.management.queue.minimum_value_placeholder')"
						/>
					</UFormField>
				</div>

				<div class="flex items-center justify-end">
					<UButton
						color="neutral"
						variant="ghost"
						icon="i-lucide-x"
						:label="t('groups.activities.management.queue.clear_filters')"
						@click="clearFilters"
					/>
				</div>
			</div>
		</div>

		<div class="min-h-0 flex-1 overflow-y-auto">
			<div v-if="isLoading" class="flex flex-col gap-3 p-4">
				<USkeleton class="h-28 w-full" />
				<USkeleton class="h-28 w-full" />
				<USkeleton class="h-28 w-full" />
			</div>

			<div
				v-else-if="visibleApplications.length > 0"
				class="flex flex-col gap-3 p-4"
			>
					<ApplicantQueueItem
						v-for="application in visibleApplications"
						:key="application.id"
						:application="application"
						@open-details="openApplicationDetails"
						@open-notes="memberNotes.openMemberNotes"
					/>
			</div>

			<div v-else class="px-4 py-10 text-center text-sm text-muted">
				{{ t('groups.activities.management.queue.empty') }}
			</div>
		</div>

		<ApplicantQueueDetailsModal
			v-model:open="isApplicationModalOpen"
			:group-slug="groupSlug"
			:activity-id="activityId"
			:fflogs-zone-id="fflogsZoneId"
			:application="selectedApplication"
			@declined="handleApplicationDeclined"
			@refreshed="handleApplicationRefreshed"
		/>

		<MembersNotesModal :notes="memberNotes" />
	</aside>
</template>
