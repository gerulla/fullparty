<script setup lang="ts">
import axios from "axios";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import { route } from "ziggy-js";
import ApplicantQueueItem from "@/components/Groups/Activities/ApplicantQueueItem.vue";
import { getRosterSlotDragData, isRosterSlotDrag } from "@/components/Groups/Activities/rosterDragData";

type LocalizedText = Record<string, string | null | undefined> | null | undefined;

type ActivityApplication = {
	id: number
	user: {
		id: number
		name: string
		avatar_url: string | null
	} | null
	selected_character: {
		id: number
		name: string
		avatar_url: string | null
		world: string | null
		datacenter: string | null
		occult_level: number | null
		phantom_mastery: number | null
	} | null
	status: string
	notes: string | null
	submitted_at: string | null
	progress_milestones: Array<{
		key: string
		label: LocalizedText
		reached: boolean
		source: string
		kills: number
		progress_percent: number
	}>
	answers: Array<{
		question_key: string
		question_label: LocalizedText
		question_type: string
		source: string | null
		raw_value: unknown
		display_values: string[]
		role_values: string[]
		display_items: Array<{
			label: string
			role?: string | null
			icon_url?: string | null
			flat_icon_url?: string | null
			transparent_icon_url?: string | null
		}>
	}>
};

type QueueFilterField = {
	key: string
	application_key: string
	label: LocalizedText
	type: string
	source: string | null
	options: Array<{
		key: string
		label: LocalizedText
		meta?: {
			icon_url?: string | null
			role?: string | null
			shorthand?: string | null
		} | null
	}>
}

type QueueFilterMilestone = {
	key: string
	label: LocalizedText
	matcher_type: string
	encounter_id: number | null
	phase_id: number | null
}

const props = defineProps<{
	groupSlug: string
	activityId: number
	initialPendingApplicationCount?: number
	activityStatus?: string | null
}>();

const { t, locale } = useI18n();
const page = usePage();
const isLoading = ref(true);
const fflogsZoneId = ref<number | null>(null);
const applications = ref<ActivityApplication[]>([]);
const queueFilters = ref<{
	slot_fields: QueueFilterField[]
	milestones: QueueFilterMilestone[]
}>({
	slot_fields: [],
	milestones: [],
});
const searchTerm = ref('');
const areFiltersOpen = ref(false);
const milestoneFilter = ref<string[]>([]);
const slotFieldFilters = ref<Record<string, string[]>>({});
const minimumKnowledgeLevel = ref('');
const minimumPhantomMastery = ref('');
const isQueueDropActive = ref(false);
const isReturningSlot = ref(false);

const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));

const localizedText = (value: LocalizedText, fallback: string) => (
	localizedValue(value, locale.value, fallbackLocale.value) || fallback
);

const slotFieldFilterItems = computed(() => queueFilters.value.slot_fields.map((field) => ({
	...field,
	labelText: localizedText(field.label, field.key),
	items: field.options.map((option) => ({
		label: localizedText(option.label, option.key),
		value: option.key,
	})),
})));

const milestoneFilterItems = computed(() => queueFilters.value.milestones.map((milestone) => ({
	label: localizedText(milestone.label, milestone.key),
	value: milestone.key,
})));

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

const canAcceptRosterDrop = computed(() => props.activityStatus !== 'complete' && !isReturningSlot.value);

const fetchQueuePayload = async () => {
	isLoading.value = true;

	try {
		const response = await axios.get(route('groups.dashboard.activities.applicant-queue', {
			group: props.groupSlug,
			activity: props.activityId,
		}));

		fflogsZoneId.value = response.data?.fflogs_zone_id ?? null;
		applications.value = response.data?.applications ?? [];
		queueFilters.value = response.data?.queue_filters ?? {
			slot_fields: [],
			milestones: [],
		};
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

const handleApplicationAssigned = (event: Event) => {
	const customEvent = event as CustomEvent<{ applicationId?: number }>;
	const assignedApplicationId = customEvent.detail?.applicationId;

	if (!assignedApplicationId) {
		return;
	}

	applications.value = applications.value.filter((application) => application.id !== assignedApplicationId);
};

const handleApplicationReturned = (event: Event) => {
	const customEvent = event as CustomEvent<{ application?: ActivityApplication }>;
	const restoredApplication = customEvent.detail?.application;

	if (!restoredApplication) {
		return;
	}

	applications.value = [
		restoredApplication,
		...applications.value.filter((application) => application.id !== restoredApplication.id),
	];
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
		}));

		window.dispatchEvent(new CustomEvent('fullparty:activity-slot-returned-to-queue', {
			detail: {
				slot: response.data?.slot ?? null,
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

onMounted(() => {
	void fetchQueuePayload();
	window.addEventListener('fullparty:activity-application-assigned', handleApplicationAssigned as EventListener);
	window.addEventListener('fullparty:activity-application-returned', handleApplicationReturned as EventListener);
});

onBeforeUnmount(() => {
	window.removeEventListener('fullparty:activity-application-assigned', handleApplicationAssigned as EventListener);
	window.removeEventListener('fullparty:activity-application-returned', handleApplicationReturned as EventListener);
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
		const characterName = application.selected_character?.name?.toLowerCase() ?? '';

		return applicantName.includes(normalizedSearchTerm) || characterName.includes(normalizedSearchTerm);
	});

	return searchedApplications.filter((application) => {
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
});
</script>

<template>
	<aside
		class="flex max-h-[calc(100vh-2rem)] flex-col border border-default bg-muted transition duration-200 dark:bg-elevated/50"
		:class="isQueueDropActive ? 'border-white shadow-[0_0_0_2px_rgba(255,255,255,0.95),0_0_0_10px_rgba(255,255,255,0.12)]' : ''"
		@dragover="handleDragOver"
		@dragleave="handleDragLeave"
		@drop="handleDrop"
	>
		<div class="flex items-center justify-between gap-3 border-b border-default px-4 py-4">
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

		<div class="border-b border-default px-4 py-4">
			<div
				v-if="canAcceptRosterDrop"
				class="mb-4 rounded-sm border border-dashed border-default px-3 py-2 text-xs uppercase tracking-[0.12em] text-muted"
				:class="isQueueDropActive ? 'border-white text-toned bg-white/5' : ''"
			>
				Drop a roster slot here to move it back to the applicant queue
			</div>

			<div class="flex items-center gap-3">
				<UInput
					v-model="searchTerm"
					size="lg"
					icon="i-lucide-search"
					class="flex-1"
					:placeholder="t('groups.activities.management.queue.search_placeholder')"
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
						:group-slug="groupSlug"
						:activity-id="activityId"
						:fflogs-zone-id="fflogsZoneId"
						:application="application"
					/>
			</div>

			<div v-else class="px-4 py-10 text-center text-sm text-muted">
				{{ t('groups.activities.management.queue.empty') }}
			</div>
		</div>
	</aside>
</template>
