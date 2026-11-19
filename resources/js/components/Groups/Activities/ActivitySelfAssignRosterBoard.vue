<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import { displayActivityPartyLabel } from "@/utils/activityPartyLabels";
import RunOverviewRosterCard from "@/components/Groups/Activities/RunOverviewRosterCard.vue";
import type { AttendeeActivity } from "@/Types/ActivityAttendee";
import ActivitySelfAssignRosterSlot from "@/components/Groups/Activities/ActivitySelfAssignRosterSlot.vue";
import type { ActivitySlot } from "@/Types/ActivityRoster";
import type { LocalizedText } from "@/Types/Common";

const props = defineProps<{
	activity: AttendeeActivity
	groupSlug: string
	canSelfAssign: boolean
	previewOnly: boolean
	hasVerifiedCharacters: boolean
	viewerAssignedSlotId: number | null
	pendingSlotId: number | null
}>()

const emit = defineEmits<{
	assign: [slot: ActivitySlot]
	remove: [slot: ActivitySlot]
}>()

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? "en"));
const scrollContainer = ref<HTMLElement | null>(null);
const groupElementRefs = new Map<string, HTMLElement>();

type SlotGroup = {
	key: string
	label: string
	slots: ActivitySlot[]
}

const localizedTextValue = (value: LocalizedText, fallback: string) => (
	localizedValue(value, locale.value, fallbackLocale.value) || fallback
);

const mainSlotGroups = computed<SlotGroup[]>(() => {
	const groups = new Map<string, SlotGroup>();

	for (const slot of [...props.activity.slots]
		.filter((currentSlot) => !currentSlot.is_bench && !currentSlot.is_fill_in)
		.sort((left, right) => left.sort_order - right.sort_order)) {
		const existingGroup = groups.get(slot.group_key);

		if (existingGroup) {
			existingGroup.slots.push(slot);
			continue;
		}

		groups.set(slot.group_key, {
			key: slot.group_key,
			label: localizedTextValue(slot.group_label, slot.group_key),
			slots: [slot],
		});
	}

	return Array.from(groups.values());
});

const maxGroupSize = computed(() => (
	mainSlotGroups.value.reduce((largest, group) => Math.max(largest, group.slots.length), 0)
));

const focusedGroupKey = computed(() => {
	if (props.viewerAssignedSlotId === null) {
		return null;
	}

	return mainSlotGroups.value.find((group) => group.slots.some((slot) => (
		slot.id === props.viewerAssignedSlotId
	)))?.key ?? null;
});

const boardGridStyle = computed(() => ({
	gridTemplateColumns: `repeat(${Math.max(mainSlotGroups.value.length, 1)}, minmax(13rem, 24rem))`,
	minWidth: `calc(${Math.max(mainSlotGroups.value.length, 1)} * 13rem + ${Math.max(mainSlotGroups.value.length - 1, 0)} * 0.25rem)`,
}));

const displayedGroupLabel = (group: SlotGroup, numberedSecondaryParties: boolean): string => {
	return displayActivityPartyLabel(
		group.key,
		group.label,
		numberedSecondaryParties,
		t("groups.activities.overview.board.party_label"),
	);
};

const scrollByDirection = (direction: "left" | "right") => {
	if (!scrollContainer.value) {
		return;
	}

	const amount = Math.round(scrollContainer.value.clientWidth * 0.82);

	scrollContainer.value.scrollBy({
		left: direction === "left" ? -amount : amount,
		behavior: "smooth",
	});
};

const setGroupElementRef = (key: string, element: Element | null) => {
	if (element instanceof HTMLElement) {
		groupElementRefs.set(key, element);
		return;
	}

	groupElementRefs.delete(key);
};

const focusAssignedParty = async () => {
	await nextTick();

	if (!scrollContainer.value || !focusedGroupKey.value) {
		return;
	}

	const targetElement = groupElementRefs.get(focusedGroupKey.value);

	if (!targetElement) {
		return;
	}

	const containerRect = scrollContainer.value.getBoundingClientRect();
	const targetRect = targetElement.getBoundingClientRect();
	const targetLeft = scrollContainer.value.scrollLeft
		+ targetRect.left
		- containerRect.left
		- ((scrollContainer.value.clientWidth - targetRect.width) / 2);

	scrollContainer.value.scrollTo({
		left: Math.max(0, targetLeft),
		behavior: "auto",
	});
};

onMounted(focusAssignedParty);

watch(
	() => `${focusedGroupKey.value ?? ""}:${props.viewerAssignedSlotId ?? ""}:${props.activity.slots.map((slot) => `${slot.id}:${slot.group_key}`).join("|")}`,
	focusAssignedParty,
);
</script>

<template>
	<RunOverviewRosterCard
		:activity="activity"
		:group-slug="groupSlug"
		:party-count="mainSlotGroups.length"
		:show-self="viewerAssignedSlotId !== null"
	>
		<template #default="{ plainDpsEnabled, numberedSecondaryPartiesEnabled, progressStatus, compositionTone }">
			<div v-if="mainSlotGroups.length > 0">
				<div class="relative xl:hidden">
					<UButton
						class="absolute top-1/2 left-2 z-10 inline-flex -translate-y-1/2 border border-white/10 bg-neutral-950/85 shadow-xl shadow-neutral-950/40 backdrop-blur-sm xl:hidden"
						color="neutral"
						variant="soft"
						icon="i-lucide-chevron-left"
						:aria-label="t('groups.activities.calendar.previous')"
						@click="scrollByDirection('left')"
					/>
					<UButton
						class="absolute top-1/2 right-2 z-10 inline-flex -translate-y-1/2 border border-white/10 bg-neutral-950/85 shadow-xl shadow-neutral-950/40 backdrop-blur-sm xl:hidden"
						color="neutral"
						variant="soft"
						icon="i-lucide-chevron-right"
						:aria-label="t('groups.activities.calendar.next')"
						@click="scrollByDirection('right')"
					/>

					<div
						ref="scrollContainer"
						class="mx-3 grid auto-cols-[100%] grid-flow-col snap-x snap-mandatory gap-x-3 gap-y-2 overflow-x-auto scroll-smooth [scrollbar-width:none] sm:auto-cols-[48%] lg:auto-cols-[31%] [&::-webkit-scrollbar]:hidden"
						:style="{ gridTemplateRows: `auto repeat(${maxGroupSize}, 1fr)` }"
					>
						<div
							v-for="group in mainSlotGroups"
							:key="group.key"
							:ref="(element) => setGroupElementRef(group.key, element)"
							class="row-span-full grid min-w-0 snap-start grid-rows-subgrid"
						>
							<div class="contents">
								<div class="px-3 py-2.5">
									<div class="flex items-center justify-between gap-2">
										<div class="min-w-0">
											<p class="text-[10px] uppercase tracking-[0.22em] text-muted">{{ t("groups.activities.overview.board.party_label") }}</p>
											<h3 class="break-words [overflow-wrap:anywhere] font-semibold text-sm text-toned">{{ displayedGroupLabel(group, numberedSecondaryPartiesEnabled) }}</h3>
										</div>

										<UBadge
											size="sm"
											color="neutral"
											variant="outline"
											:label="`${group.slots.filter((slot) => slot.assigned_character_id !== null).length}/${group.slots.length}`"
										/>
									</div>
								</div>

								<ActivitySelfAssignRosterSlot
									v-for="slot in group.slots"
									:key="slot.id"
									:slot="slot"
									:progress-status="progressStatus(slot)"
									:composition-tone="compositionTone(slot)"
									:role-highlights="!plainDpsEnabled"
									:can-self-assign="canSelfAssign"
									:preview-only="previewOnly"
									:has-verified-characters="hasVerifiedCharacters"
									:viewer-assigned-slot-id="viewerAssignedSlotId"
									:is-pending="pendingSlotId === slot.id"
									@assign="emit('assign', $event)"
									@remove="emit('remove', $event)"
								/>
							</div>
						</div>
					</div>
				</div>

				<div class="mx-4 hidden overflow-x-auto xl:block">
					<div class="grid auto-rows-fr grid-rows-[auto] justify-evenly gap-4" :style="boardGridStyle">
						<div
							v-for="group in mainSlotGroups"
							:key="`${group.key}-header`"
							class="px-3 py-2.5"
						>
							<div class="flex items-center justify-between gap-2">
								<div class="min-w-0">
									<p class="text-[10px] uppercase tracking-[0.22em] text-muted">{{ t("groups.activities.overview.board.party_label") }}</p>
									<h3 class="break-words [overflow-wrap:anywhere] font-semibold text-sm text-toned">{{ displayedGroupLabel(group, numberedSecondaryPartiesEnabled) }}</h3>
								</div>

								<UBadge
									size="sm"
									color="neutral"
									variant="outline"
									:label="`${group.slots.filter((slot) => slot.assigned_character_id !== null).length}/${group.slots.length}`"
								/>
							</div>
						</div>

						<template v-for="rowIndex in maxGroupSize" :key="`row-${rowIndex}`">
							<div
								v-for="group in mainSlotGroups"
								:key="`${group.key}-${rowIndex}`"
								class="grid min-w-0"
							>
								<ActivitySelfAssignRosterSlot
									v-if="group.slots[rowIndex - 1]"
									:slot="group.slots[rowIndex - 1]"
									:progress-status="progressStatus(group.slots[rowIndex - 1])"
									:composition-tone="compositionTone(group.slots[rowIndex - 1])"
									:role-highlights="!plainDpsEnabled"
									:can-self-assign="canSelfAssign"
									:preview-only="previewOnly"
									:has-verified-characters="hasVerifiedCharacters"
									:viewer-assigned-slot-id="viewerAssignedSlotId"
									:is-pending="pendingSlotId === group.slots[rowIndex - 1].id"
									@assign="emit('assign', $event)"
									@remove="emit('remove', $event)"
								/>

								<div
									v-else
									class="flex items-center justify-center rounded-md border border-dashed border-default/70 bg-background/60 px-3 py-2"
								>
									<p class="text-xs text-muted">{{ t("groups.activities.overview.board.no_slot") }}</p>
								</div>
							</div>
						</template>
					</div>
				</div>
			</div>

			<div v-else class="px-4 py-6">
				<p class="text-sm text-muted">{{ t("groups.activities.management.roster.empty") }}</p>
			</div>
		</template>

		<template #bench="{ slot, progressStatus, roleHighlights, compositionTone }">
			<ActivitySelfAssignRosterSlot
				:slot="slot"
				:progress-status="progressStatus"
				:composition-tone="compositionTone"
				:role-highlights="roleHighlights"
				:can-self-assign="canSelfAssign"
				:preview-only="previewOnly"
				:has-verified-characters="hasVerifiedCharacters"
				:viewer-assigned-slot-id="viewerAssignedSlotId"
				:is-pending="pendingSlotId === slot.id"
				@assign="emit('assign', $event)"
				@remove="emit('remove', $event)"
			/>
		</template>
	</RunOverviewRosterCard>
</template>
