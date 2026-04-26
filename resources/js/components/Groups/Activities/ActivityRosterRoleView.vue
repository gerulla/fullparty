<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import ActivityRosterSlotCard from "@/components/Groups/Activities/ActivityRosterSlotCard.vue";
import type { QueueApplication } from "@/components/Groups/Activities/queueTypes";
import type { ActivitySlot } from "@/components/Groups/Activities/rosterTypes";

const props = defineProps<{
	slots: ActivitySlot[]
	draggedSlotId?: number | null
	dropTargetSlotId?: number | null
	isSwapPending?: boolean
	pendingSwapSlotIds?: number[]
	canReturnToQueue?: boolean
	canMoveToBench?: boolean
	canMarkMissing?: boolean
	canCheckIn?: boolean
}>();

const emit = defineEmits<{
	dragStart: [slotId: number]
	dragEnd: []
	dragEnter: [slotId: number]
	dragLeave: [slotId: number]
	dropSlot: [slotId: number]
	dropApplication: [payload: { slotId: number, application: QueueApplication }]
	clickSlot: [slotId: number]
	returnSlotToQueue: [slotId: number]
	moveSlotToBench: [slotId: number]
	markSlotMissing: [slotId: number]
	checkInSlot: [slotId: number]
}>();

const { t } = useI18n();

const inferRoleKey = (slot: ActivitySlot) => {
	const normalized = `${slot.slot_key} ${JSON.stringify(slot.slot_label ?? {})}`.toLowerCase();

	if (normalized.includes('tank')) {
		return 'tank';
	}

	if (normalized.includes('heal')) {
		return 'healer';
	}

	if (normalized.includes('dps') || normalized.includes('melee') || normalized.includes('ranged') || normalized.includes('caster')) {
		return 'dps';
	}

	if (slot.position_in_group <= 2) {
		return 'tank';
	}

	if (slot.position_in_group <= 4) {
		return 'healer';
	}

	return 'dps';
};

const roleMeta = {
	tank: {
		labelKey: 'groups.activities.management.roster.roles.tank',
		icon: 'i-lucide-shield',
	},
	healer: {
		labelKey: 'groups.activities.management.roster.roles.healer',
		icon: 'i-lucide-heart-pulse',
	},
	dps: {
		labelKey: 'groups.activities.management.roster.roles.dps',
		icon: 'i-lucide-swords',
	},
} as const;

const roleGroups = computed(() => {
	const groups = {
		tank: [] as ActivitySlot[],
		healer: [] as ActivitySlot[],
		dps: [] as ActivitySlot[],
	};
	const benchSlots: ActivitySlot[] = [];

	for (const slot of [...props.slots].sort((left, right) => left.sort_order - right.sort_order)) {
		if (slot.is_bench) {
			benchSlots.push(slot);
			continue;
		}

		groups[inferRoleKey(slot)].push(slot);
	}

	const rosterGroups = (Object.keys(groups) as Array<keyof typeof groups>)
		.map((key) => ({
			key,
			label: t(roleMeta[key].labelKey),
			icon: roleMeta[key].icon,
			slots: groups[key],
		}))
		.filter((group) => group.slots.length > 0);

	return benchSlots.length > 0
		? [
			...rosterGroups,
			{
				key: 'bench',
				label: 'Bench',
				icon: 'i-lucide-armchair',
				slots: benchSlots,
			},
		]
		: rosterGroups;
});
</script>

<template>
	<div class="flex flex-col gap-4">
		<section
			v-for="group in roleGroups"
			:key="group.key"
			class="border border-default bg-muted shadow-sm transition-all duration-300 ease-in-out dark:bg-elevated/50"
		>
			<header class="border-b border-default px-5 py-4">
				<div class="flex items-center justify-between gap-3">
					<div class="flex items-center gap-3">
						<div class="flex h-9 w-9 items-center justify-center rounded-sm bg-primary text-inverted">
							<UIcon :name="group.icon" class="size-4" />
						</div>

						<div class="flex items-center gap-3">
							<h3 class="font-semibold text-lg text-toned">
								{{ group.label }}
							</h3>

							<UBadge
								color="neutral"
								variant="outline"
								:label="`${group.slots.filter((slot) => slot.assigned_character_id !== null).length}/${group.slots.length}`"
							/>
						</div>
					</div>
				</div>
			</header>

			<div class="grid grid-cols-1 gap-3 px-5 py-5 transition-all duration-300 ease-in-out md:grid-cols-2 xl:grid-cols-4">
				<ActivityRosterSlotCard
					v-for="slot in group.slots"
					:key="slot.id"
					:slot="slot"
					:dragged-slot-id="draggedSlotId"
					:drop-target-slot-id="dropTargetSlotId"
					:is-swap-pending="isSwapPending"
					:is-pending-swap="pendingSwapSlotIds?.includes(slot.id)"
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
					@click-slot="emit('clickSlot', $event)"
					@return-slot-to-queue="emit('returnSlotToQueue', $event)"
					@move-slot-to-bench="emit('moveSlotToBench', $event)"
					@mark-slot-missing="emit('markSlotMissing', $event)"
					@check-in-slot="emit('checkInSlot', $event)"
				/>
			</div>
		</section>
	</div>
</template>
