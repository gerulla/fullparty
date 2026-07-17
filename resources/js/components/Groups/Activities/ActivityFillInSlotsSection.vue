<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import ActivityRosterSlotCard from "@/components/Groups/Activities/ActivityRosterSlotCard.vue";
import type { QueueApplication } from "@/Types/ActivityQueue";
import type { ActivitySlot, ActivitySlotCompositionHintInput } from "@/Types/ActivityRoster";

const props = defineProps<{
	slots: ActivitySlot[]
	draggedSlotId?: number | null
	dropTargetSlotId?: number | null
	isSwapPending?: boolean
	isCreating?: boolean
	canCreate?: boolean
	pendingSwapSlotIds?: number[]
	cutSlotId?: number | null
	cutSlotIsBench?: boolean | null
	canReturnToQueue?: boolean
	canMoveToBench?: boolean
	canMarkMissing?: boolean
	canCheckIn?: boolean
}>();

const emit = defineEmits<{
	createFillInSlot: []
	dragStart: [slotId: number]
	dragEnd: []
	dragEnter: [slotId: number]
	dragLeave: [slotId: number]
	dropSlot: [slotId: number]
	dropApplication: [payload: { slotId: number, application: QueueApplication }]
	clickSlot: [slotId: number]
	viewApplication: [slotId: number]
	returnSlotToQueue: [slotId: number]
	moveSlotToBench: [slotId: number]
	markSlotMissing: [slotId: number]
	checkInSlot: [slotId: number]
	markSlotLate: [slotId: number]
	markSlotHost: [slotId: number]
	markSlotRaidLeader: [slotId: number]
	cutSlot: [slotId: number]
	pasteCutSlot: [slotId: number]
	clearCutSlot: []
	replaceCompositionHints: [payload: { slotId: number, compositionHints: ActivitySlotCompositionHintInput[] }]
	customizeCompositionHints: [slot: ActivitySlot]
}>();

const { t } = useI18n();

const sortedSlots = computed(() => [...props.slots].sort((left, right) => left.sort_order - right.sort_order));
const assignedCount = computed(() => props.slots.filter((slot) => slot.assigned_character_id !== null).length);
</script>

<template>
	<section class="border border-default bg-muted shadow-sm transition-all duration-300 ease-in-out dark:bg-elevated/50">
		<header class="border-b border-default px-5 py-4">
			<div class="flex items-center justify-between gap-3">
				<div class="flex items-center gap-3">
					<div class="flex h-9 w-9 items-center justify-center rounded-sm bg-info text-inverted">
						<UIcon name="i-lucide-user-plus" class="size-4" />
					</div>

					<div class="flex flex-wrap items-center gap-3">
						<h3 class="font-semibold text-lg text-toned">
							{{ t("groups.activities.management.roster.fill_ins.title") }}
						</h3>

						<UBadge
							color="neutral"
							variant="outline"
							:label="`${assignedCount}/${slots.length}`"
						/>
					</div>
				</div>
			</div>
		</header>

		<div class="grid grid-cols-1 gap-3 px-5 py-5 transition-all duration-300 ease-in-out md:grid-cols-2 xl:grid-cols-4">
			<div
				v-for="slot in sortedSlots"
				:key="slot.id"
				class="flex min-w-0 flex-col gap-2"
			>
				<ActivityRosterSlotCard
					:slot="slot"
					:dragged-slot-id="draggedSlotId"
					:drop-target-slot-id="dropTargetSlotId"
					:is-swap-pending="isSwapPending"
					:is-pending-swap="pendingSwapSlotIds?.includes(slot.id)"
					:cut-slot-id="cutSlotId"
					:cut-slot-is-bench="cutSlotIsBench"
					:can-return-to-queue="canReturnToQueue"
					:can-move-to-bench="canMoveToBench"
					:can-mark-missing="canMarkMissing"
					:can-check-in="canCheckIn"
					@drag-start="emit('dragStart', $event)"
					@drag-end="emit('dragEnd')"
					@drag-enter="emit('dragEnter', $event)"
					@drag-leave="emit('dragLeave', $event)"
					@drop-slot="emit('dropSlot', $event)"
					@drop-application="emit('dropApplication', $event)"
					@cut-slot="emit('cutSlot', $event)"
					@paste-cut-slot="emit('pasteCutSlot', $event)"
					@clear-cut-slot="emit('clearCutSlot')"
					@click-slot="emit('clickSlot', $event)"
					@view-application="emit('viewApplication', $event)"
					@return-slot-to-queue="emit('returnSlotToQueue', $event)"
					@move-slot-to-bench="emit('moveSlotToBench', $event)"
					@mark-slot-missing="emit('markSlotMissing', $event)"
					@check-in-slot="emit('checkInSlot', $event)"
					@mark-slot-late="emit('markSlotLate', $event)"
					@mark-slot-host="emit('markSlotHost', $event)"
					@mark-slot-raid-leader="emit('markSlotRaidLeader', $event)"
					@replace-composition-hints="emit('replaceCompositionHints', $event)"
					@customize-composition-hints="emit('customizeCompositionHints', $event)"
				/>
			</div>

			<button
				type="button"
				class="flex min-h-36 items-center justify-center border border-dashed border-default bg-elevated/60 text-muted transition hover:border-primary hover:bg-primary/10 hover:text-primary disabled:cursor-not-allowed disabled:opacity-60"
				:disabled="!canCreate || isSwapPending || isCreating"
				:aria-label="t('groups.activities.management.roster.fill_ins.add')"
				@click="emit('createFillInSlot')"
			>
				<UIcon
					:name="isCreating ? 'i-lucide-loader-circle' : 'i-lucide-plus'"
					class="size-8"
					:class="isCreating ? 'animate-spin' : ''"
				/>
			</button>
		</div>
	</section>
</template>
