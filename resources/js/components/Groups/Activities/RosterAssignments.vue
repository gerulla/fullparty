<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import ActivityRosterPartyView from "@/components/Groups/Activities/ActivityRosterPartyView.vue";
import ActivityRosterRoleView from "@/components/Groups/Activities/ActivityRosterRoleView.vue";
import ActivityRosterListView from "@/components/Groups/Activities/ActivityRosterListView.vue";
import type { QueueApplication } from "@/components/Groups/Activities/queueTypes";
import type { ActivitySlot } from "@/components/Groups/Activities/rosterTypes";

const props = defineProps<{
	view: 'party' | 'role' | 'list'
	slots: ActivitySlot[]
	isSwapPending?: boolean
	pendingSwapSlotIds?: number[]
	canReturnToQueue?: boolean
	canMarkMissing?: boolean
	canCheckIn?: boolean
}>();

const emit = defineEmits<{
	swapSlots: [payload: { sourceSlotId: number, targetSlotId: number }]
	assignApplicationToSlot: [payload: { slotId: number, application: QueueApplication }]
	clickSlot: [slotId: number]
	returnSlotToQueue: [slotId: number]
	moveSlotToBench: [slotId: number]
	markSlotMissing: [slotId: number]
	checkInSlot: [slotId: number]
	markSlotLate: [slotId: number]
	checkInGroup: [groupKey: string]
}>();

const { t } = useI18n();
const draggedSlotId = ref<number | null>(null);
const dropTargetSlotId = ref<number | null>(null);
const firstAvailableBenchSlotId = computed(() => (
	props.slots.find((slot) => slot.is_bench && slot.assigned_character_id === null)?.id ?? null
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

const handleDragStart = (slotId: number) => {
	draggedSlotId.value = slotId;
};

const handleDragEnd = () => {
	draggedSlotId.value = null;
	dropTargetSlotId.value = null;
};

const handleDragEnter = (slotId: number) => {
	if (draggedSlotId.value === slotId) {
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
	if (draggedSlotId.value === null || draggedSlotId.value === targetSlotId || props.isSwapPending) {
		handleDragEnd();
		return;
	}

	emit('swapSlots', {
		sourceSlotId: draggedSlotId.value,
		targetSlotId,
	});

	handleDragEnd();
};
</script>

<template>
	<section class="flex flex-col gap-4 transition-all duration-300 ease-in-out">
		<h2 class="font-semibold text-lg text-toned">
			{{ t('groups.activities.management.roster.title') }}
		</h2>

		<component
			v-if="slots.length > 0"
			:is="currentViewComponent"
			:slots="slots"
			:dragged-slot-id="draggedSlotId"
			:drop-target-slot-id="dropTargetSlotId"
			:is-swap-pending="isSwapPending"
			:pending-swap-slot-ids="pendingSwapSlotIds"
			:can-return-to-queue="canReturnToQueue"
			:can-move-to-bench="firstAvailableBenchSlotId !== null"
			:can-mark-missing="canMarkMissing"
			:can-check-in="canCheckIn"
			@drag-start="handleDragStart"
			@drag-end="handleDragEnd"
			@drag-enter="handleDragEnter"
			@drag-leave="handleDragLeave"
			@drop-slot="handleDropSlot"
			@drop-application="emit('assignApplicationToSlot', $event)"
			@click-slot="emit('clickSlot', $event)"
			@return-slot-to-queue="emit('returnSlotToQueue', $event)"
			@move-slot-to-bench="emit('moveSlotToBench', $event)"
			@mark-slot-missing="emit('markSlotMissing', $event)"
			@check-in-slot="emit('checkInSlot', $event)"
			@mark-slot-late="emit('markSlotLate', $event)"
			@check-in-group="emit('checkInGroup', $event)"
		/>

		<div
			v-else
			class="border border-dashed border-default bg-muted/10 px-4 py-10 text-center text-sm text-muted"
		>
			{{ t('groups.activities.management.roster.empty') }}
		</div>
	</section>
</template>
