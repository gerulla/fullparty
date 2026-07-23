<script setup lang="ts">
import axios from "axios";
import { computed, ref } from "vue";
import { route } from "ziggy-js";
import { useToast } from "@nuxt/ui/composables";
import { useI18n } from "vue-i18n";
import ActivitySlotCompositionCustomModal from "@/components/Groups/Activities/ActivitySlotCompositionCustomModal.vue";
import ActivityFillInSlotsSection from "@/components/Groups/Activities/ActivityFillInSlotsSection.vue";
import ActivityRosterPartyView from "@/components/Groups/Activities/ActivityRosterPartyView.vue";
import ActivityRosterRoleView from "@/components/Groups/Activities/ActivityRosterRoleView.vue";
import ActivityRosterListView from "@/components/Groups/Activities/ActivityRosterListView.vue";
import type { QueueApplication } from "@/Types/ActivityQueue";
import type { ActivityCompositionClassOption, ActivitySlot, ActivitySlotCompositionHintInput } from "@/Types/ActivityRoster";

const props = defineProps<{
	view: 'party' | 'role' | 'list'
	slots: ActivitySlot[]
	isSwapPending?: boolean
	pendingSwapSlotIds?: number[]
	cutSlotId?: number | null
	canReturnToQueue?: boolean
	canMarkMissing?: boolean
	canCheckIn?: boolean
	isFillInPending?: boolean
	groupSlug: string
	activityId: number
	compositionClassOptions: ActivityCompositionClassOption[]
}>();

const emit = defineEmits<{
	swapSlots: [payload: { sourceSlotId: number, targetSlotId: number }]
	assignApplicationToSlot: [payload: { slotId: number, application: QueueApplication }]
	clickSlot: [slotId: number]
	viewApplication: [slotId: number]
	returnSlotToQueue: [slotId: number]
	moveSlotToBench: [slotId: number]
	moveSlotToFillIn: [slotId: number]
	markSlotMissing: [slotId: number]
	checkInSlot: [slotId: number]
	markSlotLate: [slotId: number]
	markSlotHost: [slotId: number]
	markSlotRaidLeader: [slotId: number]
	checkInGroup: [groupKey: string]
	createFillInSlot: []
	slotsUpdated: [slots: ActivitySlot[]]
	cutSlot: [slotId: number]
	pasteCutSlot: [slotId: number]
	clearCutSlot: []
}>();

const { t } = useI18n();
const toast = useToast();
const draggedSlotId = ref<number | null>(null);
const dropTargetSlotId = ref<number | null>(null);
const isCompositionHintPending = ref(false);
const compositionHintModalOpen = ref(false);
const compositionHintSlotId = ref<number | null>(null);
const firstAvailableBenchSlotId = computed(() => (
	props.slots.find((slot) => slot.is_bench && slot.assigned_character_id === null)?.id ?? null
));
const fillInSlots = computed(() => props.slots.filter((slot) => slot.is_fill_in));
const currentViewSlots = computed(() => (
	props.view === 'list'
		? props.slots.filter((slot) => !slot.is_fill_in)
		: props.slots
));
const canShowListFillIns = computed(() => (
	props.view === 'list' && (fillInSlots.value.length > 0 || Boolean(props.canReturnToQueue))
));
const cutSlotIsBench = computed(() => (
	props.cutSlotId === null || props.cutSlotId === undefined
		? null
		: props.slots.find((slot) => slot.id === props.cutSlotId)?.is_bench ?? null
));
const compositionHintSlot = computed(() => (
	compositionHintSlotId.value === null
		? null
		: props.slots.find((slot) => slot.id === compositionHintSlotId.value) ?? null
));

const currentViewComponent = computed(() => {
	if (props.view === 'role') {
		return ActivityRosterRoleView;
	}

	if (props.view === 'list') {
		return ActivityRosterListView;
	}

	return ActivityRosterPartyView;
});

const currentViewProps = computed(() => (
	props.view === 'party'
		? {
			groupSlug: props.groupSlug,
			activityId: props.activityId,
		}
		: {}
));

const handleDragStart = (slotId: number) => {
	draggedSlotId.value = slotId;
};

const handleDragEnd = () => {
	draggedSlotId.value = null;
	dropTargetSlotId.value = null;
};

const canMoveBetweenSlots = (sourceSlotId: number, targetSlotId: number) => {
	const sourceSlot = props.slots.find((slot) => slot.id === sourceSlotId);
	const targetSlot = props.slots.find((slot) => slot.id === targetSlotId);

	return Boolean(
		sourceSlot
		&& targetSlot
		&& !(sourceSlot.assigned_character_id && targetSlot.assigned_character_id && sourceSlot.slot_kind !== targetSlot.slot_kind),
	);
};

const handleDragEnter = (slotId: number) => {
	if (draggedSlotId.value === slotId) {
		dropTargetSlotId.value = null;
		return;
	}

	if (draggedSlotId.value !== null && !canMoveBetweenSlots(draggedSlotId.value, slotId)) {
		dropTargetSlotId.value = null;
		return;
	}

	dropTargetSlotId.value = slotId;
};

const handleDragLeave = (slotId: number) => {
	if (dropTargetSlotId.value === slotId) {
		dropTargetSlotId.value = null;
	}
};

const handleDropSlot = (targetSlotId: number) => {
	if (
		draggedSlotId.value === null
		|| draggedSlotId.value === targetSlotId
		|| props.isSwapPending
		|| !canMoveBetweenSlots(draggedSlotId.value, targetSlotId)
	) {
		handleDragEnd();
		return;
	}

	emit('swapSlots', {
		sourceSlotId: draggedSlotId.value,
		targetSlotId,
	});

	handleDragEnd();
};

const openCompositionHintModal = (slot: ActivitySlot) => {
	compositionHintSlotId.value = slot.id;
	compositionHintModalOpen.value = true;
};

const replaceSlotCompositionHints = async (payload: { slotId: number, compositionHints: ActivitySlotCompositionHintInput[] }) => {
	if (isCompositionHintPending.value) {
		return;
	}

	isCompositionHintPending.value = true;

	try {
		const response = await axios.post(route("groups.dashboard.activities.slot-composition-hints.update", {
			group: props.groupSlug,
			activity: props.activityId,
			slot: payload.slotId,
		}), {
			composition_hints: payload.compositionHints,
		});

		const updatedSlots = Array.isArray(response.data?.slots)
			? response.data.slots as ActivitySlot[]
			: [];

		if (updatedSlots.length > 0) {
			emit("slotsUpdated", updatedSlots);
		}

		compositionHintModalOpen.value = false;
		compositionHintSlotId.value = null;
	} catch {
		toast.add({
			title: t("general.error"),
			description: t("groups.activities.management.roster.composition_hint_update_failed"),
			color: "error",
			icon: "i-lucide-octagon-alert",
		});
	} finally {
		isCompositionHintPending.value = false;
	}
};
</script>

<template>
	<section class="flex flex-col gap-4 transition-all duration-300 ease-in-out">
		<h2 class="font-semibold text-lg text-toned">
			{{ t('groups.activities.management.roster.title') }}
		</h2>

		<ActivityFillInSlotsSection
			v-if="canShowListFillIns"
			:slots="fillInSlots"
			:dragged-slot-id="draggedSlotId"
			:drop-target-slot-id="dropTargetSlotId"
			:is-swap-pending="isSwapPending || isCompositionHintPending"
			:is-creating="isFillInPending"
			:can-create="canReturnToQueue"
			:pending-swap-slot-ids="pendingSwapSlotIds"
			:cut-slot-id="cutSlotId"
			:cut-slot-is-bench="cutSlotIsBench"
			:can-return-to-queue="canReturnToQueue"
			:can-move-to-bench="firstAvailableBenchSlotId !== null"
			:can-move-to-fill-in="canReturnToQueue"
			:can-mark-missing="canMarkMissing"
			:can-check-in="canCheckIn"
			@create-fill-in-slot="emit('createFillInSlot')"
			@drag-start="handleDragStart"
			@drag-end="handleDragEnd"
			@drag-enter="handleDragEnter"
			@drag-leave="handleDragLeave"
			@drop-slot="handleDropSlot"
			@drop-application="emit('assignApplicationToSlot', $event)"
			@cut-slot="emit('cutSlot', $event)"
			@paste-cut-slot="emit('pasteCutSlot', $event)"
			@clear-cut-slot="emit('clearCutSlot')"
			@click-slot="emit('clickSlot', $event)"
			@view-application="emit('viewApplication', $event)"
			@return-slot-to-queue="emit('returnSlotToQueue', $event)"
			@move-slot-to-bench="emit('moveSlotToBench', $event)"
			@move-slot-to-fill-in="emit('moveSlotToFillIn', $event)"
			@mark-slot-missing="emit('markSlotMissing', $event)"
			@check-in-slot="emit('checkInSlot', $event)"
			@mark-slot-late="emit('markSlotLate', $event)"
			@mark-slot-host="emit('markSlotHost', $event)"
			@mark-slot-raid-leader="emit('markSlotRaidLeader', $event)"
			@replace-composition-hints="replaceSlotCompositionHints"
			@customize-composition-hints="openCompositionHintModal"
		/>

		<component
			v-if="currentViewSlots.length > 0"
			:is="currentViewComponent"
			:slots="currentViewSlots"
			:dragged-slot-id="draggedSlotId"
			:drop-target-slot-id="dropTargetSlotId"
			:is-swap-pending="isSwapPending || isCompositionHintPending"
			:pending-swap-slot-ids="pendingSwapSlotIds"
			:cut-slot-id="cutSlotId"
			:cut-slot-is-bench="cutSlotIsBench"
			:can-return-to-queue="canReturnToQueue"
			:can-move-to-bench="firstAvailableBenchSlotId !== null"
			:can-move-to-fill-in="canReturnToQueue"
			:can-mark-missing="canMarkMissing"
			:can-check-in="canCheckIn"
			:fill-in-slots="fillInSlots"
			:is-creating-fill-in="isFillInPending"
			v-bind="currentViewProps"
			@drag-start="handleDragStart"
			@drag-end="handleDragEnd"
			@drag-enter="handleDragEnter"
			@drag-leave="handleDragLeave"
			@drop-slot="handleDropSlot"
			@drop-application="emit('assignApplicationToSlot', $event)"
			@cut-slot="emit('cutSlot', $event)"
			@paste-cut-slot="emit('pasteCutSlot', $event)"
			@clear-cut-slot="emit('clearCutSlot')"
			@click-slot="emit('clickSlot', $event)"
			@view-application="emit('viewApplication', $event)"
			@return-slot-to-queue="emit('returnSlotToQueue', $event)"
			@move-slot-to-bench="emit('moveSlotToBench', $event)"
			@move-slot-to-fill-in="emit('moveSlotToFillIn', $event)"
			@mark-slot-missing="emit('markSlotMissing', $event)"
			@check-in-slot="emit('checkInSlot', $event)"
			@mark-slot-late="emit('markSlotLate', $event)"
			@mark-slot-host="emit('markSlotHost', $event)"
			@mark-slot-raid-leader="emit('markSlotRaidLeader', $event)"
			@check-in-group="emit('checkInGroup', $event)"
			@create-fill-in-slot="emit('createFillInSlot')"
			@slots-updated="emit('slotsUpdated', $event)"
			@replace-composition-hints="replaceSlotCompositionHints"
			@customize-composition-hints="openCompositionHintModal"
		/>

		<div
			v-else
			class="border border-dashed border-default bg-muted/10 px-4 py-10 text-center text-sm text-muted"
		>
			{{ t('groups.activities.management.roster.empty') }}
		</div>

		<ActivitySlotCompositionCustomModal
			v-model:open="compositionHintModalOpen"
			:slot="compositionHintSlot"
			:class-options="compositionClassOptions"
			:is-submitting="isCompositionHintPending"
			@save="replaceSlotCompositionHints"
		/>
	</section>
</template>
