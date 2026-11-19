<script setup lang="ts">
import type {
	ActivityTypeCompositionHint,
	ActivityTypeCompositionPreset,
	ActivityTypeLayoutGroup,
	ActivityTypeLayoutPreset,
} from "@/Types/AdminActivityTypes";
import ActivityTypeSectionCard from "@/components/Admin/ActivityTypes/ActivityTypeSectionCard.vue";
import { computed, onMounted } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	modelValue: ActivityTypeLayoutGroup[]
	locales: string[]
	layoutPresets: ActivityTypeLayoutPreset[]
	compositionPresets: ActivityTypeCompositionPreset[]
}>();

const emit = defineEmits<{
	'update:modelValue': [value: ActivityTypeLayoutGroup[]]
}>();

const { t } = useI18n();

const totalSlots = computed(() => props.modelValue.reduce((total, group) => total + Number(group.size || 0), 0));

const layoutOptions = computed(() => props.layoutPresets.map((preset) => ({
	label: t(`admin.activity_types.layout.presets.${preset.key}`),
	value: preset.key,
})));

const currentLayoutPreset = computed(() => props.layoutPresets.find((preset) => {
	return props.modelValue.length === preset.party_count
		&& props.modelValue.every((group) => Number(group.size) === preset.party_size);
}));

const compositionPresetsForLayout = computed(() => {
	const partySize = currentLayoutPreset.value?.party_size ?? props.layoutPresets[0]?.party_size ?? 8;

	return props.compositionPresets.filter((preset) => preset.party_size === partySize);
});

const compositionOptions = computed(() => compositionPresetsForLayout.value.map((preset) => ({
	label: t(`admin.activity_types.layout.composition_presets.${preset.key}`),
	value: preset.key,
})));

const currentCompositionPreset = computed(() => {
	const presets = compositionPresetsForLayout.value;
	const compositionKey = props.modelValue[0]?.composition_hint_key;

	if (compositionKey && props.modelValue.every((group) => group.composition_hint_key === compositionKey)) {
		const matchingPreset = presets.find((preset) => preset.key === compositionKey);

		if (matchingPreset) {
			return matchingPreset;
		}
	}

	return presets.find((preset) => props.modelValue.every((group) => compositionHintsEqual(group.composition_hints, preset.composition_hints)));
});

const selectedLayoutKey = computed({
	get: () => currentLayoutPreset.value?.key ?? props.layoutPresets[0]?.key ?? '',
	set: (value: string) => applyLayoutPreset(value),
});

const selectedCompositionKey = computed({
	get: () => currentCompositionPreset.value?.key ?? defaultCompositionKeyForCurrentLayout(),
	set: (value: string) => applyCompositionPreset(value),
});

const defaultCompositionKeyForCurrentLayout = () => {
	const layoutPreset = currentLayoutPreset.value ?? props.layoutPresets[0];
	const firstMatchingPreset = props.compositionPresets.find((preset) => preset.party_size === layoutPreset?.party_size);

	return firstMatchingPreset?.key ?? props.compositionPresets[0]?.key ?? '';
};

const applyLayoutPreset = (layoutKey: string) => {
	const layoutPreset = props.layoutPresets.find((preset) => preset.key === layoutKey);

	if (!layoutPreset) {
		return;
	}

	const compositionPreset = props.compositionPresets.find((preset) => preset.party_size === layoutPreset.party_size)
		?? props.compositionPresets[0];

	emit('update:modelValue', createLayoutGroups(layoutPreset, compositionPreset));
};

const applyCompositionPreset = (compositionKey: string) => {
	const compositionPreset = compositionPresetsForLayout.value.find((preset) => preset.key === compositionKey);

	if (!compositionPreset) {
		return;
	}

	emit('update:modelValue', props.modelValue.map((group) => ({
		...group,
		composition_hint_key: compositionPreset.key,
		composition_hints: cloneCompositionHints(compositionPreset.composition_hints),
	})));
};

const createLayoutGroups = (
	layoutPreset: ActivityTypeLayoutPreset,
	compositionPreset?: ActivityTypeCompositionPreset,
): ActivityTypeLayoutGroup[] => {
	return Array.from({ length: layoutPreset.party_count }, (_, index) => {
		const label = partyLabel(index);

		return {
			key: `party-${String.fromCharCode(97 + index)}`,
			label: Object.fromEntries(props.locales.map((locale) => [locale, label])),
			size: layoutPreset.party_size,
			composition_hint_key: compositionPreset?.key ?? null,
			composition_hints: compositionPreset ? cloneCompositionHints(compositionPreset.composition_hints) : [],
		};
	});
};

const compositionHintsEqual = (
	left: ActivityTypeCompositionHint[] | undefined,
	right: ActivityTypeCompositionHint[],
) => {
	if (!left || left.length !== right.length) {
		return false;
	}

	return JSON.stringify(normalizeCompositionHints(left)) === JSON.stringify(normalizeCompositionHints(right));
};

const normalizeCompositionHints = (hints: ActivityTypeCompositionHint[]) => hints
	.map((hint) => ({
		position: Number(hint.position),
		accepts: [...(hint.accepts ?? [])]
			.map((accept) => ({
				type: accept.type,
				key: accept.key,
			}))
			.sort((left, right) => `${left.type}:${left.key}`.localeCompare(`${right.type}:${right.key}`)),
	}))
	.sort((left, right) => left.position - right.position);

const cloneCompositionHints = (hints: ActivityTypeCompositionHint[]) => hints.map((hint) => ({
	position: hint.position,
	accepts: hint.accepts.map((accept) => ({ ...accept })),
}));

const partyLabel = (index: number) => `${t('admin.activity_types.layout.party_label')} ${String.fromCharCode(65 + index)}`;

const shorthandForGroup = (group: ActivityTypeLayoutGroup) => {
	const preset = props.compositionPresets.find((compositionPreset) => compositionPreset.key === group.composition_hint_key);

	if (preset) {
		return preset.shorthand;
	}

	return [...(group.composition_hints ?? [])]
		.sort((left, right) => left.position - right.position)
		.map((hint) => hint.accepts[0]?.key?.slice(0, 1)?.toUpperCase() ?? '?')
		.join('');
};

onMounted(() => {
	if (!currentLayoutPreset.value) {
		applyLayoutPreset(selectedLayoutKey.value);

		return;
	}

	if (!currentCompositionPreset.value) {
		applyCompositionPreset(defaultCompositionKeyForCurrentLayout());
	}
});
</script>

<template>
	<ActivityTypeSectionCard
		:title="t('admin.activity_types.layout.title')"
		:description="t('admin.activity_types.layout.subtitle')"
	>
		<template #headerMeta>
			<UBadge color="primary" variant="subtle" :label="t('admin.activity_types.layout.groups_count', { count: modelValue.length })" />
			<UBadge color="neutral" variant="subtle" :label="t('admin.activity_types.layout.total_slots', { count: totalSlots })" />
		</template>

		<div class="grid gap-4 md:grid-cols-2">
			<UFormField
				:label="t('admin.activity_types.layout.layout_preset')"
				:description="t('admin.activity_types.layout.layout_preset_help')"
				required
			>
				<USelect
					v-model="selectedLayoutKey"
					:items="layoutOptions"
					value-key="value"
					class="w-full"
				/>
			</UFormField>

			<UFormField
				:label="t('admin.activity_types.layout.composition_preset')"
				:description="t('admin.activity_types.layout.composition_preset_help')"
				required
			>
				<USelect
					v-model="selectedCompositionKey"
					:items="compositionOptions"
					value-key="value"
					class="w-full"
				/>
			</UFormField>
		</div>

		<div class="admin-inset min-w-0">
			<div
				v-for="group in modelValue"
				:key="group.key"
				class="flex flex-col gap-2 border-b border-default p-3 last:border-b-0 md:flex-row md:items-center md:justify-between"
			>
				<div>
					<p class="text-sm font-medium">{{ group.label?.en ?? group.key }}</p>
					<p class="text-xs text-muted">
						{{ group.key }} · {{ t('admin.activity_types.layout.slots_per_party', { count: group.size }) }}
					</p>
				</div>

				<div class="flex flex-wrap items-center gap-2">
					<UBadge color="neutral" variant="subtle" :label="shorthandForGroup(group)" />
				</div>
			</div>
		</div>
	</ActivityTypeSectionCard>
</template>
