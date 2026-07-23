<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import ActivityFillInSlotsSection from "@/components/Groups/Activities/ActivityFillInSlotsSection.vue";
import ActivityPartyCompositionApplyAllButton from "@/components/Groups/Activities/ActivityPartyCompositionApplyAllButton.vue";
import ActivityPartyCompositionPresetSelect from "@/components/Groups/Activities/ActivityPartyCompositionPresetSelect.vue";
import ActivityRosterSlotCard from "@/components/Groups/Activities/ActivityRosterSlotCard.vue";
import type { LocalizedText } from "@/Types/Common";
import type { QueueApplication } from "@/Types/ActivityQueue";
import type { ActivitySlot, ActivitySlotCompositionHintInput } from "@/Types/ActivityRoster";

const props = defineProps<{
	slots: ActivitySlot[]
	draggedSlotId?: number | null
	dropTargetSlotId?: number | null
	isSwapPending?: boolean
	pendingSwapSlotIds?: number[]
	cutSlotId?: number | null
	cutSlotIsBench?: boolean | null
	canReturnToQueue?: boolean
	canMoveToBench?: boolean
	canMoveToFillIn?: boolean
	canMarkMissing?: boolean
	canCheckIn?: boolean
	fillInSlots?: ActivitySlot[]
	isCreatingFillIn?: boolean
	groupSlug: string
	activityId: number
}>();

const emit = defineEmits<{
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
	replaceCompositionHints: [payload: { slotId: number, compositionHints: ActivitySlotCompositionHintInput[] }]
	customizeCompositionHints: [slot: ActivitySlot]
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));

const localizedText = (value: LocalizedText, fallback: string) => (
	localizedValue(value, locale.value, fallbackLocale.value) || fallback
);

const slotGroups = computed(() => {
	const groups = new Map<string, {
		key: string
		label: string
		slots: ActivitySlot[]
	}>();

	for (const slot of [...props.slots]
		.filter((currentSlot) => !currentSlot.is_bench && !currentSlot.is_fill_in)
		.sort((left, right) => left.sort_order - right.sort_order)) {
		const existingGroup = groups.get(slot.group_key);

		if (existingGroup) {
			existingGroup.slots.push(slot);
			continue;
		}

		groups.set(slot.group_key, {
			key: slot.group_key,
			label: localizedText(slot.group_label, slot.group_key),
			slots: [slot],
		});
	}

	return Array.from(groups.values());
});

const mainSlotGroups = computed(() => slotGroups.value.filter((group) => group.key !== "bench"));
const firstMainSlotGroupKey = computed(() => mainSlotGroups.value[0]?.key ?? null);
const hasMultipleMainSlotGroups = computed(() => mainSlotGroups.value.length > 1);
const benchGroup = computed(() => {
	const benchSlots = [...props.slots]
		.filter((slot) => slot.is_bench)
		.sort((left, right) => left.sort_order - right.sort_order);

	return benchSlots.length > 0
		? {
			key: "bench",
			label: t("groups.activities.management.roster.bench"),
			slots: benchSlots,
		}
		: null;
});
const canShowFillIns = computed(() => (props.fillInSlots?.length ?? 0) > 0 || Boolean(props.canReturnToQueue));
const hasRosterSections = computed(() => slotGroups.value.length > 0 || canShowFillIns.value || benchGroup.value !== null);
</script>

<template>
	<div v-if="hasRosterSections" class="flex flex-col gap-4">
		<section
			v-for="group in slotGroups"
			:key="group.key"
			class="border border-default bg-muted shadow-sm transition-all duration-300 ease-in-out dark:bg-elevated/50"
		>
			<header class="border-b border-default px-5 py-4">
				<div class="flex items-center justify-between gap-3">
					<div class="flex items-center gap-3">
						<div class="flex h-9 w-9 items-center justify-center rounded-sm bg-primary text-sm font-semibold text-inverted">
							{{ group.label.charAt(0) }}
						</div>

						<div class="flex flex-wrap items-center gap-3">
							<h3 class="font-semibold text-lg text-toned">
								{{ group.label }}
							</h3>

							<UBadge
								color="neutral"
								variant="outline"
								:label="`${group.slots.filter((slot) => slot.assigned_character_id !== null).length}/${group.slots.length}`"
							/>

							<ActivityPartyCompositionPresetSelect
								v-if="group.key !== 'bench'"
								:group-slug="groupSlug"
								:activity-id="activityId"
								:group-key="group.key"
								:slots="group.slots"
								:disabled="isSwapPending || !canReturnToQueue"
								@slots-updated="emit('slotsUpdated', $event)"
							/>

							<ActivityPartyCompositionApplyAllButton
								v-if="group.key === firstMainSlotGroupKey && hasMultipleMainSlotGroups"
								:group-slug="groupSlug"
								:activity-id="activityId"
								:source-group-key="group.key"
								:disabled="isSwapPending || !canReturnToQueue"
								@slots-updated="emit('slotsUpdated', $event)"
							/>
						</div>
					</div>

					<UButton
						v-if="group.key !== 'bench'"
						color="neutral"
						variant="ghost"
						icon="i-lucide-user-check"
						:label="t('groups.activities.management.roster.check_in_all')"
						:disabled="!canCheckIn || isSwapPending || !group.slots.some((slot) => slot.assigned_character_id !== null && slot.attendance_status !== 'checked_in')"
						@click="emit('checkInGroup', group.key)"
					/>
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
					:cut-slot-id="cutSlotId"
					:cut-slot-is-bench="cutSlotIsBench"
					:can-return-to-queue="canReturnToQueue"
					:can-move-to-bench="canMoveToBench"
					:can-move-to-fill-in="canMoveToFillIn"
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
					@move-slot-to-fill-in="emit('moveSlotToFillIn', $event)"
					@mark-slot-missing="emit('markSlotMissing', $event)"
					@check-in-slot="emit('checkInSlot', $event)"
					@mark-slot-late="emit('markSlotLate', $event)"
					@mark-slot-host="emit('markSlotHost', $event)"
					@mark-slot-raid-leader="emit('markSlotRaidLeader', $event)"
					@replace-composition-hints="emit('replaceCompositionHints', $event)"
					@customize-composition-hints="emit('customizeCompositionHints', $event)"
				/>
			</div>
		</section>

		<ActivityFillInSlotsSection
			v-if="canShowFillIns"
			:slots="fillInSlots ?? []"
			:dragged-slot-id="draggedSlotId"
			:drop-target-slot-id="dropTargetSlotId"
			:is-swap-pending="isSwapPending"
			:is-creating="isCreatingFillIn"
			:can-create="canReturnToQueue"
			:pending-swap-slot-ids="pendingSwapSlotIds"
			:cut-slot-id="cutSlotId"
			:cut-slot-is-bench="cutSlotIsBench"
			:can-return-to-queue="canReturnToQueue"
			:can-move-to-bench="canMoveToBench"
			:can-move-to-fill-in="canMoveToFillIn"
			:can-mark-missing="canMarkMissing"
			:can-check-in="canCheckIn"
			@create-fill-in-slot="emit('createFillInSlot')"
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
			@move-slot-to-fill-in="emit('moveSlotToFillIn', $event)"
			@mark-slot-missing="emit('markSlotMissing', $event)"
			@check-in-slot="emit('checkInSlot', $event)"
			@mark-slot-late="emit('markSlotLate', $event)"
			@mark-slot-host="emit('markSlotHost', $event)"
			@mark-slot-raid-leader="emit('markSlotRaidLeader', $event)"
			@replace-composition-hints="emit('replaceCompositionHints', $event)"
			@customize-composition-hints="emit('customizeCompositionHints', $event)"
		/>

		<section
			v-if="benchGroup"
			:key="benchGroup.key"
			class="border border-default bg-muted shadow-sm transition-all duration-300 ease-in-out dark:bg-elevated/50"
		>
			<header class="border-b border-default px-5 py-4">
				<div class="flex items-center justify-between gap-3">
					<div class="flex items-center gap-3">
						<div class="flex h-9 w-9 items-center justify-center rounded-sm bg-primary text-sm font-semibold text-inverted">
							{{ benchGroup.label.charAt(0) }}
						</div>

						<div class="flex flex-wrap items-center gap-3">
							<h3 class="font-semibold text-lg text-toned">
								{{ benchGroup.label }}
							</h3>

							<UBadge
								color="neutral"
								variant="outline"
								:label="`${benchGroup.slots.filter((slot) => slot.assigned_character_id !== null).length}/${benchGroup.slots.length}`"
							/>
						</div>
					</div>
				</div>
			</header>

			<div class="grid grid-cols-1 gap-3 px-5 py-5 transition-all duration-300 ease-in-out md:grid-cols-2 xl:grid-cols-4">
				<ActivityRosterSlotCard
					v-for="slot in benchGroup.slots"
					:key="slot.id"
					:slot="slot"
					:dragged-slot-id="draggedSlotId"
					:drop-target-slot-id="dropTargetSlotId"
					:is-swap-pending="isSwapPending"
					:is-pending-swap="pendingSwapSlotIds?.includes(slot.id)"
					:cut-slot-id="cutSlotId"
					:cut-slot-is-bench="cutSlotIsBench"
					:can-return-to-queue="canReturnToQueue"
					:can-move-to-bench="canMoveToBench"
					:can-move-to-fill-in="canMoveToFillIn"
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
					@move-slot-to-fill-in="emit('moveSlotToFillIn', $event)"
					@mark-slot-missing="emit('markSlotMissing', $event)"
					@check-in-slot="emit('checkInSlot', $event)"
					@mark-slot-late="emit('markSlotLate', $event)"
					@mark-slot-host="emit('markSlotHost', $event)"
					@mark-slot-raid-leader="emit('markSlotRaidLeader', $event)"
					@replace-composition-hints="emit('replaceCompositionHints', $event)"
					@customize-composition-hints="emit('customizeCompositionHints', $event)"
				/>
			</div>
		</section>
	</div>
</template>
