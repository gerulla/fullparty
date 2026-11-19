<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import type { ActivityRosterSummaryPreset, ActivitySlot } from "@/Types/ActivityRoster";
import { useRosterSummary } from "@/composables/useRosterSummary";

const props = defineProps<{
	presets: ActivityRosterSummaryPreset[]
	slots: ActivitySlot[]
	numberedSecondaryParties?: boolean
}>();

const { t } = useI18n();
const selectedPresetKey = ref<string | null>(props.presets[0]?.key ?? null);
const open = ref(false);

const selectedPreset = computed(() => (
	props.presets.find((preset) => preset.key === selectedPresetKey.value)
		?? props.presets[0]
		?? null
));

const { localizedText, requirementRows, groupedRequirementRows } = useRosterSummary(
    () => selectedPreset.value, () => props.slots, () => Boolean(props.numberedSecondaryParties),
);
const presetOptions = computed(() => props.presets.map((preset) => ({
	label: localizedText(preset.label, preset.key),
	value: preset.key,
})));

const selectedPresetDescription = computed(() => {
	if (!selectedPreset.value) {
		return "";
	}

	return localizedText(selectedPreset.value.description, "");
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
