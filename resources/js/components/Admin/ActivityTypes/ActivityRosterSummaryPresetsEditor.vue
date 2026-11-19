<script setup lang="ts">
import type {
	ActivityTypeLayoutGroup,
	ActivityTypeRosterSummaryPreset,
	ActivityTypeRosterSummaryReference,
	ActivityTypeRosterSummaryRequirement,
	ActivityTypeRosterSummarySourceOption,
} from "@/Types/AdminActivityTypes";
import type { LocalizedStringRecord } from "@/Types/Common";
import ActivityTypeSectionCard from "@/components/Admin/ActivityTypes/ActivityTypeSectionCard.vue";
import LocalizedTextFields from "@/components/Admin/ActivityTypes/LocalizedTextFields.vue";
import { slugify } from "@/utils/slugify";
import { computed } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	modelValue: ActivityTypeRosterSummaryPreset[]
	locales: string[]
	layoutGroups: ActivityTypeLayoutGroup[]
	summaryReference: ActivityTypeRosterSummaryReference
}>();

const emit = defineEmits<{
	'update:modelValue': [value: ActivityTypeRosterSummaryPreset[]]
}>();

const { t } = useI18n();

const sourceOptions = computed(() => props.summaryReference.supportedSources.map((source) => ({
		label: t(`admin.activity_types.roster_summary.sources.${source}`),
		value: source,
	})));

const comparisonOptions = computed(() => props.summaryReference.supportedComparisons.map((comparison) => ({
		label: t(`admin.activity_types.roster_summary.comparisons.${comparison}`),
		value: comparison,
	})));

const scopeTypeOptions = computed(() => props.summaryReference.supportedScopeTypes.map((scopeType) => ({
		label: t(`admin.activity_types.roster_summary.scope_types.${scopeType}`),
		value: scopeType,
	})));

const layoutGroupOptions = computed(() => props.layoutGroups.map((group) => ({
		label: resolveLayoutGroupLabel(group),
		value: group.key,
	})));

const createLocalizedRecord = (): LocalizedStringRecord => Object.fromEntries(props.locales.map((locale) => [locale, ""]));

const firstSource = computed(() => props.summaryReference.supportedSources[0] ?? "phantom_jobs");
const firstComparison = computed(() => props.summaryReference.supportedComparisons[0] ?? "at_least");
const defaultScopeType = computed(() => props.summaryReference.supportedScopeTypes[0] ?? "all_slots");

const optionsForSource = (source: string): ActivityTypeRosterSummarySourceOption[] => props.summaryReference.sourceOptions[source] ?? [];
const firstLayoutGroupKey = computed(() => layoutGroupOptions.value[0]?.value ?? null);

const resolveLayoutGroupLabel = (group: ActivityTypeLayoutGroup): string => {
	for (const locale of props.locales) {
		const localizedLabel = group.label?.[locale];

		if (localizedLabel?.trim()) {
			return localizedLabel.trim();
		}
	}

	return group.key;
};

const createRequirement = (source = firstSource.value): ActivityTypeRosterSummaryRequirement => ({
	source,
	source_id: optionsForSource(source)[0]?.value ?? null,
	comparison: firstComparison.value,
	target_count: 1,
	scope_type: defaultScopeType.value,
	scope_group_keys: [],
});

const createPreset = (): ActivityTypeRosterSummaryPreset => ({
	key: "",
	label: createLocalizedRecord(),
	description: createLocalizedRecord(),
	requirements: [createRequirement()],
});

const updateValue = (value: ActivityTypeRosterSummaryPreset[]) => {
	emit("update:modelValue", value);
};

const addPreset = () => {
	updateValue([...props.modelValue, createPreset()]);
};

const updatePreset = (index: number, updates: Partial<ActivityTypeRosterSummaryPreset>) => {
	updateValue(props.modelValue.map((preset, presetIndex) => (
		presetIndex === index ? { ...preset, ...updates } : preset
	)));
};

const removePreset = (index: number) => {
	updateValue(props.modelValue.filter((_, presetIndex) => presetIndex !== index));
};

const updatePresetLabel = (index: number, label: LocalizedStringRecord) => {
	const preset = props.modelValue[index];
	const primaryLocale = props.locales[0] ?? "en";
	const previousPrimaryLabel = preset?.label?.[primaryLocale] ?? "";
	const nextPrimaryLabel = label?.[primaryLocale] ?? "";
	const previousGeneratedKey = slugify(previousPrimaryLabel);
	const nextGeneratedKey = slugify(nextPrimaryLabel);

	updatePreset(index, {
		label,
		key: !preset?.key || preset.key === previousGeneratedKey
			? nextGeneratedKey
			: preset.key,
	});
};

const addRequirement = (presetIndex: number) => {
	const preset = props.modelValue[presetIndex];

	updatePreset(presetIndex, {
		requirements: [...(preset.requirements ?? []), createRequirement()],
	});
};

const updateRequirement = (presetIndex: number, requirementIndex: number, updates: Partial<ActivityTypeRosterSummaryRequirement>) => {
	const preset = props.modelValue[presetIndex];
	const nextRequirements = (preset.requirements ?? []).map((requirement, currentIndex) => (
		currentIndex === requirementIndex ? { ...requirement, ...updates } : requirement
	));

	updatePreset(presetIndex, { requirements: nextRequirements });
};

const updateRequirementSource = (presetIndex: number, requirementIndex: number, source: string) => {
	const nextOptions = optionsForSource(source);

	updateRequirement(presetIndex, requirementIndex, {
		source,
		source_id: nextOptions[0]?.value ?? null,
	});
};

const updateRequirementScopeType = (presetIndex: number, requirementIndex: number, scopeType: string) => {
	const scopeGroupKeys = scopeType === "slot_group"
		? (firstLayoutGroupKey.value ? [firstLayoutGroupKey.value] : [])
		: [];

	updateRequirement(presetIndex, requirementIndex, {
		scope_type: scopeType,
		scope_group_keys: scopeGroupKeys,
	});
};

const updateRequirementScopeGroup = (presetIndex: number, requirementIndex: number, groupKey?: string) => {
	updateRequirement(presetIndex, requirementIndex, {
		scope_group_keys: groupKey ? [groupKey] : [],
	});
};

const updateRequirementScopeGroupSet = (presetIndex: number, requirementIndex: number, groupKeys: string[] | undefined) => {
	updateRequirement(presetIndex, requirementIndex, {
		scope_group_keys: Array.isArray(groupKeys) ? groupKeys : [],
	});
};

const removeRequirement = (presetIndex: number, requirementIndex: number) => {
	const preset = props.modelValue[presetIndex];
	updatePreset(presetIndex, {
		requirements: (preset.requirements ?? []).filter((_, currentIndex) => currentIndex !== requirementIndex),
	});
};
</script>

<template>
	<ActivityTypeSectionCard
		:title="t('admin.activity_types.roster_summary.title')"
		:description="t('admin.activity_types.roster_summary.subtitle')"
	>
		<template #headerMeta>
			<UBadge
				color="neutral"
				variant="subtle"
				:label="t('admin.activity_types.roster_summary.presets_count', { count: modelValue.length })"
			/>
		</template>

		<template #headerActions>
			<UButton
				icon="i-lucide-plus"
				color="neutral"
				variant="soft"
				:label="t('admin.activity_types.roster_summary.add_preset')"
				@click="addPreset"
			/>
		</template>

		<div class="flex flex-col gap-4">
			<section
				v-for="(preset, presetIndex) in modelValue"
				:key="`roster-summary-preset-${presetIndex}`"
				class="admin-inset min-w-0 p-3 sm:p-4"
			>
				<div class="flex flex-col gap-4">
					<div class="flex flex-wrap items-start justify-between gap-3">
						<div>
							<h3 class="font-semibold">{{ t('admin.activity_types.roster_summary.preset_title', { index: presetIndex + 1 }) }}</h3>
							<p class="text-sm text-muted">{{ t('admin.activity_types.roster_summary.preset_hint') }}</p>
						</div>

						<UButton
							color="error"
							variant="ghost"
							icon="i-lucide-trash-2"
							:label="t('general.remove')"
							@click="removePreset(presetIndex)"
						/>
					</div>

					<UFormField :label="t('admin.activity_types.roster_summary.key')" required>
						<UInput
							:model-value="preset.key"
							class="w-full"
							:placeholder="t('admin.activity_types.roster_summary.key_placeholder')"
							@update:model-value="(value) => updatePreset(presetIndex, { key: value })"
						/>
					</UFormField>

					<LocalizedTextFields
						rounded
						:model-value="preset.label"
						:locales="locales"
						:label="t('admin.activity_types.roster_summary.label')"
						:description="t('admin.activity_types.roster_summary.label_help')"
						:placeholder-prefix="t('admin.activity_types.roster_summary.label_placeholder')"
						@update:model-value="(value) => updatePresetLabel(presetIndex, value)"
					/>

					<LocalizedTextFields
						rounded
						:model-value="preset.description ?? createLocalizedRecord()"
						:locales="locales"
						:label="t('admin.activity_types.roster_summary.description')"
						:description="t('admin.activity_types.roster_summary.description_help')"
						:placeholder-prefix="t('admin.activity_types.roster_summary.description_placeholder')"
						multiline
						@update:model-value="(value) => updatePreset(presetIndex, { description: value })"
					/>

					<div class="rounded-xl border border-default p-3 sm:p-4">
						<div class="mb-4 flex flex-wrap items-start justify-between gap-3">
							<div>
								<h4 class="font-semibold">{{ t('admin.activity_types.roster_summary.requirements_title') }}</h4>
								<p class="text-sm text-muted">{{ t('admin.activity_types.roster_summary.requirements_subtitle') }}</p>
							</div>

							<UButton
								icon="i-lucide-plus"
								color="neutral"
								variant="soft"
								:label="t('admin.activity_types.roster_summary.add_requirement')"
								@click="addRequirement(presetIndex)"
							/>
						</div>

						<div class="flex flex-col gap-4">
							<section
								v-for="(requirement, requirementIndex) in preset.requirements"
								:key="`roster-summary-requirement-${presetIndex}-${requirementIndex}`"
								class="admin-inset min-w-0 p-3 sm:p-4"
							>
								<div class="flex flex-col gap-4">
									<div class="flex flex-wrap items-start justify-between gap-3">
										<div>
											<h5 class="font-medium">{{ t('admin.activity_types.roster_summary.requirement_title', { index: requirementIndex + 1 }) }}</h5>
											<p class="text-sm text-muted">{{ t('admin.activity_types.roster_summary.requirement_hint') }}</p>
										</div>

										<UButton
											color="error"
											variant="ghost"
											icon="i-lucide-trash-2"
											:label="t('general.remove')"
											@click="removeRequirement(presetIndex, requirementIndex)"
										/>
									</div>

									<div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
										<UFormField :label="t('admin.activity_types.roster_summary.requirement_source')" required>
											<USelect
												:model-value="requirement.source"
												:items="sourceOptions"
												value-key="value"
												class="w-full"
												@update:model-value="(value) => updateRequirementSource(presetIndex, requirementIndex, value)"
											/>
										</UFormField>

										<UFormField :label="t('admin.activity_types.roster_summary.requirement_item')" required class="lg:col-span-2">
											<USelect
												:model-value="requirement.source_id ?? undefined"
												:items="optionsForSource(requirement.source)"
												value-key="value"
												class="w-full"
												@update:model-value="(value) => updateRequirement(presetIndex, requirementIndex, { source_id: value ? Number(value) : null })"
											/>
										</UFormField>

										<UFormField :label="t('admin.activity_types.roster_summary.requirement_comparison')" required>
											<USelect
												:model-value="requirement.comparison"
												:items="comparisonOptions"
												value-key="value"
												class="w-full"
												@update:model-value="(value) => updateRequirement(presetIndex, requirementIndex, { comparison: value })"
											/>
										</UFormField>
									</div>

									<div class="grid gap-4 lg:grid-cols-[minmax(0,220px)_minmax(0,1fr)_minmax(0,160px)]">
										<UFormField :label="t('admin.activity_types.roster_summary.requirement_scope')" required>
											<USelect
												:model-value="requirement.scope_type ?? defaultScopeType"
												:items="scopeTypeOptions"
												value-key="value"
												class="w-full"
												@update:model-value="(value) => updateRequirementScopeType(presetIndex, requirementIndex, value)"
											/>
										</UFormField>

										<UFormField
											v-if="requirement.scope_type === 'slot_group'"
											:label="t('admin.activity_types.roster_summary.requirement_scope_groups')"
											required
										>
											<USelect
												:model-value="requirement.scope_group_keys?.[0] ?? undefined"
												:items="layoutGroupOptions"
												value-key="value"
												class="w-full"
												:placeholder="t('admin.activity_types.roster_summary.requirement_scope_group_placeholder')"
												@update:model-value="(value) => updateRequirementScopeGroup(presetIndex, requirementIndex, value)"
											/>
										</UFormField>

										<UFormField
											v-else-if="requirement.scope_type === 'slot_group_set'"
											:label="t('admin.activity_types.roster_summary.requirement_scope_groups')"
											required
										>
											<USelectMenu
												:model-value="requirement.scope_group_keys ?? []"
												:items="layoutGroupOptions"
												value-key="value"
												multiple
												class="w-full"
												:placeholder="t('admin.activity_types.roster_summary.requirement_scope_group_set_placeholder')"
												@update:model-value="(value) => updateRequirementScopeGroupSet(presetIndex, requirementIndex, value)"
											/>
										</UFormField>

										<div v-else class="rounded-lg border border-dashed border-default px-4 py-3 text-sm text-muted">
											{{ t('admin.activity_types.roster_summary.requirement_scope_all_hint') }}
										</div>

										<UFormField :label="t('admin.activity_types.roster_summary.requirement_target_count')" required class="max-w-xs">
											<UInput
												:model-value="requirement.target_count"
												type="number"
												min="1"
												class="w-full"
												:placeholder="t('admin.activity_types.roster_summary.requirement_target_count_placeholder')"
												@update:model-value="(value) => updateRequirement(presetIndex, requirementIndex, { target_count: Number(value) || 1 })"
											/>
										</UFormField>
									</div>
								</div>
							</section>
						</div>
					</div>
				</div>
			</section>
		</div>
	</ActivityTypeSectionCard>
</template>
