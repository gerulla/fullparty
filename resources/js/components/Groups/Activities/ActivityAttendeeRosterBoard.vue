<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import { displayActivityPartyLabel } from "@/utils/activityPartyLabels";
import ActivityAttendeeRosterSlot from "@/components/Groups/Activities/ActivityAttendeeRosterSlot.vue";
import type { ActivitySlot } from "@/Types/ActivityRoster";
import type { LocalizedText } from "@/Types/Common";

const props = defineProps<{
	slots: ActivitySlot[]
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? "en"));
const scrollContainer = ref<HTMLElement | null>(null);
const groupElementRefs = new Map<string, HTMLElement>();
const roleHighlightsEnabled = ref(false);
const numberedSecondaryPartiesEnabled = ref(false);

type SlotGroup = {
	key: string
	label: string
	slots: ActivitySlot[]
}

const localizedText = (value: LocalizedText, fallback: string) => (
	localizedValue(value, locale.value, fallbackLocale.value) || fallback
);

const mainSlotGroups = computed<SlotGroup[]>(() => {
	const groups = new Map<string, SlotGroup>();

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

const benchSlots = computed(() => (
	[...props.slots]
		.filter((slot) => slot.is_bench)
		.sort((left, right) => left.sort_order - right.sort_order)
));

const maxGroupSize = computed(() => (
	mainSlotGroups.value.reduce((largest, group) => Math.max(largest, group.slots.length), 0)
));

const assignedMainSlotCount = computed(() => (
	mainSlotGroups.value.flatMap((group) => group.slots).filter((slot) => slot.assigned_character_id !== null).length
));

const openMainSlotCount = computed(() => (
	mainSlotGroups.value.flatMap((group) => group.slots).filter((slot) => slot.assigned_character_id === null).length
));

const viewerUserId = computed(() => {
	const userId = page.props.auth?.user?.id;

	return typeof userId === "number" ? userId : null;
});

const focusedGroupKey = computed(() => {
	if (viewerUserId.value === null) {
		return null;
	}

	return mainSlotGroups.value.find((group) => group.slots.some((slot) => (
		slot.assigned_character?.user_id === viewerUserId.value
	)))?.key ?? null;
});

const boardGridStyle = computed(() => ({
	gridTemplateColumns: `repeat(${Math.max(mainSlotGroups.value.length, 1)}, minmax(13rem, 24rem))`,
	minWidth: `calc(${Math.max(mainSlotGroups.value.length, 1)} * 13rem + ${Math.max(mainSlotGroups.value.length - 1, 0)} * 0.25rem)`,
}));

const displayedGroupLabel = (group: SlotGroup): string => {
	return displayActivityPartyLabel(
		group.key,
		group.label,
		numberedSecondaryPartiesEnabled.value,
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
	() => `${focusedGroupKey.value ?? ""}:${props.slots.map((slot) => `${slot.id}:${slot.assigned_character?.user_id ?? ""}:${slot.group_key}`).join("|")}`,
	focusAssignedParty,
);
</script>

<template>
	<section class="border border-default bg-muted/20">
		<div class="flex flex-col gap-3 border-b border-default px-4 py-3 lg:flex-row lg:items-end lg:justify-between">
			<p class="font-semibold text-base text-toned">{{ t("groups.activities.overview.board.title") }}</p>

			<div class="flex flex-wrap items-center gap-2">
				<UBadge
					size="sm"
					color="neutral"
					variant="subtle"
					:label="t('groups.activities.overview.board.party_count', { count: mainSlotGroups.length })"
				/>
				<UBadge
					size="sm"
					color="primary"
					variant="subtle"
					:label="t('groups.activities.overview.board.filled_count', { count: assignedMainSlotCount })"
				/>
				<UBadge
					size="sm"
					color="neutral"
					variant="outline"
					:label="t('groups.activities.overview.board.open_count', { count: openMainSlotCount })"
				/>

				<div class="hidden h-5 w-px bg-default sm:block"></div>

				<label class="inline-flex cursor-pointer items-center gap-2 text-xs font-medium text-muted">
					<span>{{ t("groups.activities.overview.board.role_highlights") }}</span>
					<USwitch v-model="roleHighlightsEnabled" size="sm" />
				</label>

				<label class="inline-flex cursor-pointer items-center gap-2 text-xs font-medium text-muted">
					<span>{{ t("groups.activities.overview.board.party_label_mode") }}</span>
					<USwitch v-model="numberedSecondaryPartiesEnabled" size="sm" />
				</label>
			</div>
		</div>

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
					class="flex snap-x snap-mandatory gap-3 overflow-x-auto scroll-smooth px-4 py-4 [scroll-padding-inline:1rem] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
				>
					<div
						v-for="group in mainSlotGroups"
						:key="group.key"
						:ref="(element) => setGroupElementRef(group.key, element)"
						class="flex min-w-0 flex-none basis-full snap-start sm:basis-[48%] lg:basis-[31%]"
					>
						<div class="flex w-full flex-col gap-2">
							<div class="border border-default bg-muted/20 px-3 py-2.5">
								<div class="flex items-center justify-between gap-2">
									<div class="min-w-0">
										<p class="text-[10px] uppercase tracking-[0.22em] text-muted">{{ t("groups.activities.overview.board.party_label") }}</p>
										<h3 class="font-semibold text-sm text-toned break-words [overflow-wrap:anywhere]">{{ displayedGroupLabel(group) }}</h3>
									</div>

									<UBadge
										size="sm"
										color="neutral"
										variant="outline"
										:label="`${group.slots.filter((slot) => slot.assigned_character_id !== null).length}/${group.slots.length}`"
									/>
								</div>
							</div>

							<ActivityAttendeeRosterSlot
								v-for="slot in group.slots"
								:key="slot.id"
								:slot="slot"
								:role-highlights="roleHighlightsEnabled"
							/>
						</div>
					</div>
				</div>
			</div>

			<div class="hidden overflow-x-auto px-4 py-4 xl:block">
				<div class="grid justify-evenly gap-1" :style="boardGridStyle">
					<div
						v-for="group in mainSlotGroups"
						:key="`${group.key}-header`"
						class="border border-default bg-background px-3 py-2.5"
					>
						<div class="flex items-center justify-between gap-2">
							<div class="min-w-0">
								<p class="text-[10px] uppercase tracking-[0.22em] text-muted">{{ t("groups.activities.overview.board.party_label") }}</p>
								<h3 class="font-semibold text-sm text-toned break-words [overflow-wrap:anywhere]">{{ displayedGroupLabel(group) }}</h3>
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
						>
							<ActivityAttendeeRosterSlot
								v-if="group.slots[rowIndex - 1]"
								:slot="group.slots[rowIndex - 1]"
								:role-highlights="roleHighlightsEnabled"
							/>

							<div
								v-else
								class="flex h-24 items-center justify-center border border-dashed border-default/70 bg-background/60 px-3 py-2"
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

		<div v-if="benchSlots.length > 0" class="border-t border-default px-4 py-4">
			<div class="mb-3 flex items-center justify-between gap-3">
				<p class="font-semibold text-sm text-toned">{{ t("groups.activities.overview.board.bench_title") }}</p>
				<UBadge
					size="sm"
					color="neutral"
					variant="outline"
					:label="t('groups.activities.overview.board.bench_count', { count: benchSlots.length })"
				/>
			</div>

			<div class="grid gap-2.5 md:grid-cols-2 xl:grid-cols-4">
				<ActivityAttendeeRosterSlot
					v-for="slot in benchSlots"
					:key="slot.id"
					:slot="slot"
					:role-highlights="roleHighlightsEnabled"
				/>
			</div>
		</div>
	</section>
</template>
