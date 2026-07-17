<script setup lang="ts">
import type { LocalizedText } from "@/Types/Common";
import type { ActivityRosterSummaryPreset, ActivityRosterSummaryRequirementRow, ActivitySlot } from "@/Types/ActivityRoster";
import { localizedValue } from "@/utils/localizedValue";
import { computed, ref, watch } from "vue";
import { usePage } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	presets: ActivityRosterSummaryPreset[]
	slots: ActivitySlot[]
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? "en"));
const selectedPresetKey = ref<string | null>(props.presets[0]?.key ?? null);
const open = ref(false);

const localizedText = (value: LocalizedText, fallback: string) => (
	localizedValue(value, locale.value, fallbackLocale.value) || fallback
);

const presetOptions = computed(() => props.presets.map((preset) => ({
	label: localizedText(preset.label, preset.key),
	value: preset.key,
})));

const assignedRosterSlots = computed(() => props.slots.filter((slot) => !slot.is_bench && !slot.is_fill_in && slot.assigned_character_id !== null));

const selectedPreset = computed(() => (
	props.presets.find((preset) => preset.key === selectedPresetKey.value)
		?? props.presets[0]
		?? null
));

const selectedPresetDescription = computed(() => {
	if (!selectedPreset.value) {
		return "";
	}

	return localizedText(selectedPreset.value.description, "");
});

const requirementRows = computed(() => {
	if (!selectedPreset.value) {
		return [];
	}

	return selectedPreset.value.requirements.map((requirement) => {
		const matchingSlots = assignedRosterSlots.value.filter((slot) => {
			if (requirement.scope_type !== "all_slots" && !requirement.scope_group_keys.includes(slot.group_key)) {
				return false;
			}

			return slot.field_values.some((fieldValue) => {
				if (fieldValue.source !== requirement.source) {
					return false;
				}

				return extractComparableFieldValues(fieldValue.value)
					.includes(String(requirement.source_id));
			});
		});

		const currentCount = matchingSlots.length;
		const targetCount = requirement.target_count;
		const state = resolveRequirementState(requirement.comparison, currentCount, targetCount);

		return {
			key: `${selectedPreset.value?.key}-${requirement.source}-${requirement.source_id}-${requirement.scope_type}-${requirement.scope_group_keys.join("|")}`,
			scopeKey: `${requirement.scope_type}:${requirement.scope_group_keys.join("|")}`,
			itemLabel: localizedText(requirement.item.label, String(requirement.source_id)),
			itemIconUrl: requirement.item.meta?.transparent_icon_url
				|| requirement.item.meta?.flaticon_url
				|| requirement.item.meta?.icon_url
				|| requirement.item.meta?.sprite_url
				|| null,
			currentCount,
			targetCount,
			comparisonLabel: t(`groups.activities.management.overview.roster_summary.comparisons.${requirement.comparison}`),
			comparisonShortLabel: formatComparisonShortLabel(requirement.comparison),
			scopeLabel: formatScopeLabel(requirement),
			state,
		};
	});
});

const groupedRequirementRows = computed(() => {
	const groups = new Map<string, {
		key: string
		label: string
		requirements: ActivityRosterSummaryRequirementRow[]
	}>();

	for (const requirement of requirementRows.value) {
		const existingGroup = groups.get(requirement.scopeKey);

		if (existingGroup) {
			existingGroup.requirements.push(requirement);
			continue;
		}

		groups.set(requirement.scopeKey, {
			key: requirement.scopeKey,
			label: requirement.scopeLabel,
			requirements: [requirement],
		});
	}

	return Array.from(groups.values());
});

watch(() => props.presets, (presets) => {
	if (presets.length === 0) {
		selectedPresetKey.value = null;
		return;
	}

	if (!presets.some((preset) => preset.key === selectedPresetKey.value)) {
		selectedPresetKey.value = presets[0]?.key ?? null;
	}
}, { immediate: true, deep: true });

function formatScopeLabel(requirement: ActivityRosterSummaryPreset["requirements"][number]) {
	if (requirement.scope_type === "all_slots") {
		return t("groups.activities.management.overview.roster_summary.scope_all");
	}

	if (requirement.scope_type === "slot_group") {
		const group = requirement.scope_groups[0];

		return group
			? localizedText(group.label, group.key)
			: t("groups.activities.management.overview.roster_summary.scope_unknown");
	}

	const labels = requirement.scope_groups
		.map((group) => localizedText(group.label, group.key))
		.filter((label) => label.length > 0);

	return labels.length > 0
		? labels.join(" / ")
		: t("groups.activities.management.overview.roster_summary.scope_unknown");
}

function resolveRequirementState(
	comparison: ActivityRosterSummaryPreset["requirements"][number]["comparison"],
	currentCount: number,
	targetCount: number,
) {
	if (comparison === "at_least") {
		return currentCount >= targetCount
			? { color: "success" as const, toneClass: "border-success/30 bg-success/10", badgeVariant: "soft" as const }
			: { color: "error" as const, toneClass: "border-error/30 bg-error/10", badgeVariant: "soft" as const };
	}

	if (comparison === "at_most") {
		return currentCount <= targetCount
			? { color: "success" as const, toneClass: "border-success/30 bg-success/10", badgeVariant: "soft" as const }
			: { color: "warning" as const, toneClass: "border-warning/30 bg-warning/10", badgeVariant: "soft" as const };
	}

	if (currentCount === targetCount) {
		return { color: "success" as const, toneClass: "border-success/30 bg-success/10", badgeVariant: "soft" as const };
	}

	return currentCount < targetCount
		? { color: "error" as const, toneClass: "border-error/30 bg-error/10", badgeVariant: "soft" as const }
		: { color: "warning" as const, toneClass: "border-warning/30 bg-warning/10", badgeVariant: "soft" as const };
}

function formatComparisonShortLabel(
	comparison: ActivityRosterSummaryPreset["requirements"][number]["comparison"],
) {
	if (comparison === "at_least") {
		return ">=";
	}

	if (comparison === "at_most") {
		return "<=";
	}

	return "=";
}

function extractComparableFieldValues(value: unknown): string[] {
	if (value === null || value === undefined || value === "") {
		return [];
	}

	if (Array.isArray(value)) {
		return value.flatMap((entry) => extractComparableFieldValues(entry));
	}

	if (typeof value === "object") {
		const record = value as Record<string, unknown>;

		if (record.id !== undefined && record.id !== null) {
			return [String(record.id)];
		}

		if (record.key !== undefined && record.key !== null) {
			return [String(record.key)];
		}

		return [];
	}

	return [String(value)];
}
</script>

<template>
	<UCollapsible
		v-if="selectedPreset"
		v-model:open="open"
		class="flex flex-col gap-3 pt-2"
	>
		<div class="flex items-start gap-3">
			<div class="flex min-w-0 flex-1 flex-col gap-1.5">
				<p class="text-xs font-medium uppercase tracking-wide text-muted">
					{{ t('groups.activities.management.overview.roster_summary.eyebrow') }}
				</p>

				<div class="flex flex-wrap items-center gap-2">
					<USelectMenu
						v-if="presetOptions.length > 1"
						v-model="selectedPresetKey"
						:items="presetOptions"
						value-key="value"
						class="max-w-sm"
						@click.stop
					/>
					<h3 v-else class="font-semibold text-toned">
						{{ localizedText(selectedPreset.label, selectedPreset.key) }}
					</h3>

					<UBadge
						color="neutral"
						variant="subtle"
						size="sm"
						:label="t('groups.activities.management.overview.roster_summary.requirements_count', { count: requirementRows.length })"
					/>
				</div>

				<p v-if="selectedPresetDescription && open" class="text-sm text-muted">
					{{ selectedPresetDescription }}
				</p>
			</div>

			<UButton
				@click.stop="open = !open"
				color="neutral"
				variant="ghost"
				size="sm"
				trailing-icon="i-lucide-chevron-down"
				:ui="{ trailingIcon: 'transition-transform duration-200' + (open ? ' rotate-180' : '') }"
			/>
		</div>

		<template #content>
			<div class="grid gap-2">
				<div
					v-for="group in groupedRequirementRows"
					:key="group.key"
					class="flex flex-col gap-2 border border-default bg-muted/40 px-3 py-2"
				>
					<div class="flex items-center justify-between gap-2">
						<p class="truncate text-xs font-semibold uppercase tracking-wide text-toned">
							{{ group.label }}
						</p>
					</div>

					<div class="flex flex-wrap gap-2">
						<div
							v-for="requirement in group.requirements"
							:key="requirement.key"
							class="inline-flex min-w-0 items-center gap-2 border px-2.5 py-1.5"
							:class="requirement.state.toneClass"
						>
							<div
								v-if="requirement.itemIconUrl"
								class="flex h-5 w-5 shrink-0 items-center justify-center"
							>
								<img
									:src="requirement.itemIconUrl"
									:alt="requirement.itemLabel"
									class="h-5 w-5 object-contain"
								>
							</div>

							<div class="flex min-w-0 items-baseline gap-2">
								<span class="truncate text-xs font-medium text-toned">
									{{ requirement.itemLabel }}
								</span>
							</div>

							<UBadge
								:color="requirement.state.color"
								:variant="requirement.state.badgeVariant"
								size="sm"
								:label="`${requirement.currentCount}/${requirement.targetCount}`"
							/>
						</div>
					</div>
				</div>
			</div>
		</template>
	</UCollapsible>
</template>
