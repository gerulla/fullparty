<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import type { LocalizedText } from "@/Types/Common";
import type { QueueApplication, QueueFilterField } from "@/Types/ActivityQueue";
import type { ActivityFillInPartyOption, ActivitySlot } from "@/Types/ActivityRoster";
import type { ActivitySlotFieldSelection, HolsterPairValue } from '@/Types/ActivityHolsters'
import HolsterPairSelector from '@/components/Groups/Activities/HolsterPairSelector.vue'
import { translateCharacterClassName, translatePhantomJobName, translateRaidPositionName } from "@/utils/characterJobTranslations";

const props = defineProps<{
	open: boolean
	slot: ActivitySlot | null
	application: QueueApplication | null
	slotFieldDefinitions: QueueFilterField[]
	fillInPartyOptions: ActivityFillInPartyOption[]
	mode?: 'assign' | 'edit'
	isSubmitting?: boolean
}>();

const emit = defineEmits<{
	'update:open': [value: boolean]
	confirm: [payload: {
		applicationId: number
		slotId: number
		fieldValues: Record<string, ActivitySlotFieldSelection>
		ignoreApplicationChoices: boolean
		filledGroupKey?: string | null
	}]
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));
const selections = ref<Record<string, ActivitySlotFieldSelection>>({});
const ignoreApplicationChoices = ref(false);
const selectedFilledGroupKey = ref<string | null>(null);
const ANY_OPTION_KEY = 'any';

type CompatibleOption = {
	label: string
	value: string
	isFavorite: boolean
}
type AvailablePhantomJob = NonNullable<QueueApplication['selected_character']>['available_phantom_jobs'][number]

const isOpen = computed({
	get: () => props.open,
	set: (value: boolean) => emit('update:open', value),
});
const modalMode = computed(() => props.mode ?? 'assign');
const modalTitle = computed(() => modalMode.value === 'edit'
	? t('groups.activities.management.queue.edit_slot_fields')
	: t('groups.activities.management.queue.assign_to_roster'));
const submitLabel = computed(() => modalMode.value === 'edit'
	? t('groups.activities.management.queue.save_slot_fields')
	: t('groups.activities.management.queue.assign_to_roster'));
const isFillInSlot = computed(() => Boolean(props.slot?.is_fill_in));

const localizedText = (value: LocalizedText, fallback: string) => (
	localizedValue(value, locale.value, fallbackLocale.value) || fallback
);

const fillInPartyItems = computed(() => props.fillInPartyOptions.map((option) => ({
	label: localizedText(option.label, option.key),
	value: option.key,
})));
const hasFilledPartySelection = computed(() => !isFillInSlot.value || Boolean(selectedFilledGroupKey.value));

const optionLabel = (field: QueueFilterField, option: QueueFilterField["options"][number]) => {
	const fallback = localizedText(option.label, option.key);

	if (field.source === 'character_classes') {
		return translateCharacterClassName(t, {
			shorthand: option.meta?.shorthand ?? null,
			name: fallback,
		}, fallback);
	}

	if (field.source === 'phantom_jobs') {
		return translatePhantomJobName(t, { name: fallback }, fallback);
	}

	if (field.source === 'raid_positions' || field.application_key.toLowerCase().includes('position')) {
		return translateRaidPositionName(t, { key: option.key, name: fallback }, fallback);
	}

	return fallback;
};

const isRaidPositionField = (field: QueueFilterField) => (
	field.source === 'raid_positions'
	|| (field.source === 'static_options' && field.application_key.toLowerCase().includes('position'))
);
const isHolsterPairField = (field: QueueFilterField) => field.source === 'bozja_holsters'
	&& field.type === 'holster_pair';

const normalizeHolsterPair = (value: unknown): HolsterPairValue | null => {
	if (!value || typeof value !== 'object' || Array.isArray(value)) {
		return null;
	}

	const pair = value as Record<string, unknown>;
	const prepopId = pair.prepop_id == null ? '' : String(pair.prepop_id);
	const refillId = pair.refill_id == null ? '' : String(pair.refill_id);

	return prepopId && refillId ? { prepop_id: prepopId, refill_id: refillId } : null;
};

const holsterPairKey = (pair: HolsterPairValue) => `${pair.prepop_id}:${pair.refill_id}`;

const allValidHolsterPairs = (field: QueueFilterField): HolsterPairValue[] => {
	const prepopIds = new Set(field.options
		.filter(option => option.meta?.holster_type === 'prepop')
		.map(option => option.key));

	return field.options
		.filter(option => option.meta?.holster_type === 'refill')
		.map((option): HolsterPairValue => ({
			prepop_id: String(option.meta?.parent_holster_id ?? ''),
			refill_id: option.key,
		}))
		.filter(pair => pair.prepop_id !== ''
			&& prepopIds.has(pair.prepop_id));
};

const compatibleHolsterPairs = (field: QueueFilterField): HolsterPairValue[] => {
	if (ignoreApplicationChoices.value) {
		return allValidHolsterPairs(field);
	}

	const answer = props.application?.answers.find(entry => entry.question_key === field.application_key);
	const prepopIds = new Set(field.options
		.filter(option => option.meta?.holster_type === 'prepop')
		.map(option => option.key));
	const refillParents = new Map(field.options
		.filter(option => option.meta?.holster_type === 'refill')
		.map(option => [option.key, String(option.meta?.parent_holster_id ?? '')]));

	return (Array.isArray(answer?.raw_value) ? answer.raw_value : [])
		.map(normalizeHolsterPair)
		.filter((pair): pair is HolsterPairValue => Boolean(pair
			&& prepopIds.has(pair.prepop_id)
			&& refillParents.get(pair.refill_id) === pair.prepop_id));
};

const canIgnoreChoicesForField = (field: QueueFilterField) => (
	field.source === 'character_classes'
	|| field.source === 'phantom_jobs'
	|| isRaidPositionField(field)
	|| isHolsterPairField(field)
);

const optionAllowedWhenIgnoringChoices = (
	field: QueueFilterField,
	option: QueueFilterField["options"][number],
	availableClassLevels: Map<string, number>,
	availablePhantomJobs: Map<string, AvailablePhantomJob>,
) => {
	if (field.source === 'character_classes') {
		return availableClassLevels.has(option.key);
	}

	if (field.source === 'phantom_jobs') {
		return availablePhantomJobs.has(option.key);
	}

	return option.key !== ANY_OPTION_KEY;
};

const targetFieldDefinitions = computed(() => {
	if (!props.slot) {
		return [];
	}

	return props.slot.field_values
		.map((fieldValue) => props.slotFieldDefinitions.find((field) => field.key === fieldValue.field_key))
		.filter((field): field is QueueFilterField => Boolean(field && field.application_key && field.options.length > 0));
});

const compatibleOptionsByField = computed(() => {
	const map: Record<string, CompatibleOption[]> = {};

	for (const field of targetFieldDefinitions.value) {
		if (isHolsterPairField(field)) {
			map[field.key] = [];
			continue;
		}

		const answer = props.application?.answers.find((entry) => entry.question_key === field.application_key);
		const rawValues = Array.isArray(answer?.raw_value)
			? answer.raw_value.map((value) => String(value))
			: answer?.raw_value !== null && answer?.raw_value !== undefined && answer?.raw_value !== ''
				? [String(answer.raw_value)]
				: [];
		const submittedValueSet = new Set(rawValues);
		const preferredOptionKeys = new Set(
			field.source === 'character_classes'
				? props.application?.selected_character?.preferred_character_class_ids ?? []
				: field.source === 'phantom_jobs'
					? props.application?.selected_character?.preferred_phantom_job_ids ?? []
					: [],
		);
		const selectedAnyOption = rawValues.includes(ANY_OPTION_KEY);
		const availableClassLevels = new Map(
			(props.application?.selected_character?.available_character_classes ?? [])
				.map((entry) => [entry.id, entry.level]),
		);
		const availablePhantomJobs = new Map(
			(props.application?.selected_character?.available_phantom_jobs ?? [])
				.map((entry) => [entry.id, entry]),
		);
		const ignoresChoicesForField = ignoreApplicationChoices.value && canIgnoreChoicesForField(field);
		const compatibleOptions = ignoresChoicesForField
			? field.options.filter(option => optionAllowedWhenIgnoringChoices(field, option, availableClassLevels, availablePhantomJobs))
			: selectedAnyOption
				? field.options.filter((option) => option.key !== ANY_OPTION_KEY)
				: field.options.filter((option) => rawValues.includes(option.key));

		map[field.key] = compatibleOptions
			.map((option) => {
				let label = optionLabel(field, option);

				if (ignoresChoicesForField && field.source === 'character_classes') {
					label = `${label} - ${t('groups.activities.management.queue.character_level', {
						level: availableClassLevels.get(option.key),
					})}`;
				}

				if (ignoresChoicesForField && field.source === 'phantom_jobs') {
					const progress = availablePhantomJobs.get(option.key);
					label = `${label} - ${progress?.is_maxed
						? t('groups.activities.management.queue.phantom_job_mastered')
						: t('groups.activities.management.queue.phantom_job_level', { level: progress?.current_level })}`;
				}

				return {
					label,
					value: option.key,
					isFavorite: submittedValueSet.has(option.key) && preferredOptionKeys.has(option.key),
				};
			});
	}

	return map;
});

const hasCompatibleOptions = computed(() => targetFieldDefinitions.value.every((field) => {
	if (isHolsterPairField(field)) {
		return compatibleHolsterPairs(field).length > 0;
	}

	return (compatibleOptionsByField.value[field.key] ?? []).length > 0;
}));

const canSubmit = computed(() => {
	if (!props.slot || !props.application?.selected_character || !hasCompatibleOptions.value || !hasFilledPartySelection.value) {
		return false;
	}

	return targetFieldDefinitions.value.every((field) => {
		const selectedValue = selections.value[field.key];

		if (isHolsterPairField(field)) {
			return normalizeHolsterPair(selectedValue) !== null;
		}

		if (Array.isArray(selectedValue)) {
			return selectedValue.length > 0;
		}

		return Boolean(selectedValue);
	});
});

const normalizeCurrentSlotValue = (
	currentSlotValue: unknown,
	compatibleOptions: CompatibleOption[],
): string | string[] => {
	if (Array.isArray(currentSlotValue)) {
		return currentSlotValue
			.map((entry) => {
				if (typeof entry === 'object' && entry !== null) {
					const record = entry as Record<string, unknown>;

					if (record.id !== undefined && record.id !== null) {
						return String(record.id);
					}

					if (record.key !== undefined && record.key !== null) {
						return String(record.key);
					}
				}

				return String(entry);
			})
			.filter((value) => value !== '' && compatibleOptions.some((option) => option.value === value));
	}

	if (typeof currentSlotValue === 'object' && currentSlotValue !== null) {
		const record = currentSlotValue as Record<string, unknown>;

		if (record.id !== undefined && record.id !== null) {
			const normalizedId = String(record.id);

			if (compatibleOptions.some((option) => option.value === normalizedId)) {
				return normalizedId;
			}
		}

		if (record.key !== undefined && record.key !== null) {
			const normalizedKey = String(record.key);

			if (compatibleOptions.some((option) => option.value === normalizedKey)) {
				return normalizedKey;
			}
		}
	}

	return '';
};

watch(
	() => [props.open, props.slot?.id, props.application?.id, ignoreApplicationChoices.value] as const,
	() => {
		if (!props.open) {
			return;
		}

		if (!props.slot || !props.application) {
			selections.value = {};
			return;
		}

		const defaults: Record<string, ActivitySlotFieldSelection> = {};

		for (const field of targetFieldDefinitions.value) {
			if (isHolsterPairField(field)) {
				const compatiblePairs = compatibleHolsterPairs(field);
				if (compatiblePairs.length === 0) {
					continue;
				}

				const compatiblePairKeys = new Set(compatiblePairs.map(holsterPairKey));
				const currentPair = normalizeHolsterPair(
					props.slot.field_values.find(fieldValue => fieldValue.field_key === field.key)?.value,
				);

				defaults[field.key] = currentPair && compatiblePairKeys.has(holsterPairKey(currentPair))
					? currentPair
					: compatiblePairs[0]!;
				continue;
			}

			const compatibleOptions = compatibleOptionsByField.value[field.key] ?? [];

			if (compatibleOptions.length === 0) {
				continue;
			}

			const currentSlotValue = props.slot.field_values.find((fieldValue) => fieldValue.field_key === field.key)?.value;
			const normalizedCurrentValue = normalizeCurrentSlotValue(currentSlotValue, compatibleOptions);

			if (field.type === 'multi_select') {
				defaults[field.key] = normalizedCurrentValue.length > 0
					? normalizedCurrentValue
					: compatibleOptions.map((option) => option.value);
				continue;
			}

			defaults[field.key] = typeof normalizedCurrentValue === 'string' && normalizedCurrentValue
				? normalizedCurrentValue
				: compatibleOptions[0].value;
		}

		selections.value = defaults;
	},
	{ immediate: true },
);

watch(
	() => [props.open, props.slot?.id, props.fillInPartyOptions.length] as const,
	() => {
		if (!props.open || !props.slot?.is_fill_in) {
			selectedFilledGroupKey.value = null;
			return;
		}

		selectedFilledGroupKey.value = props.slot.filled_group_key ?? props.fillInPartyOptions[0]?.key ?? null;
	},
	{ immediate: true },
);

watch(
	() => props.open,
	(open) => {
		if (open) {
			ignoreApplicationChoices.value = false;
		}
	},
);

const updateFieldSelection = (fieldKey: string, value: ActivitySlotFieldSelection | undefined) => {
	selections.value = {
		...selections.value,
		[fieldKey]: value ?? '',
	};
};

const updateSelectedFilledGroupKey = (value: string | number | null | undefined) => {
	selectedFilledGroupKey.value = value === null || value === undefined || value === ''
		? null
		: String(value);
};

const submit = () => {
	if (!props.slot || !props.application || !canSubmit.value) {
		return;
	}

	emit('confirm', {
		applicationId: props.application.id,
		slotId: props.slot.id,
		fieldValues: selections.value,
		ignoreApplicationChoices: ignoreApplicationChoices.value,
		filledGroupKey: isFillInSlot.value ? selectedFilledGroupKey.value : null,
	});
};
</script>

<template>
	<UModal
		v-model:open="isOpen"
		:title="modalTitle"
		:description="slot ? localizedText(slot.slot_label, slot.slot_key) : undefined"
		:ui="{ content: 'sm:max-w-2xl', body: 'max-h-[calc(100dvh-12rem)] overflow-y-auto' }"
	>
		<template #body>
			<div class="space-y-5">
				<div class="flex items-center justify-between gap-4 border-b border-default pb-4">
					<div class="min-w-0">
						<p class="font-medium text-toned">
							{{ t('groups.activities.management.queue.ignore_application_choices') }}
						</p>
					</div>
					<USwitch v-model="ignoreApplicationChoices" />
				</div>

				<UAlert
					v-if="ignoreApplicationChoices"
					color="warning"
					variant="soft"
					icon="i-lucide-triangle-alert"
					:title="t('groups.activities.management.queue.ignore_application_choices_warning_title')"
					:description="t('groups.activities.management.queue.ignore_application_choices_warning')"
				/>

				<div class="grid gap-4 md:grid-cols-2">
					<div class="border border-default bg-default/60 p-4">
						<p class="text-xs uppercase tracking-[0.12em] text-muted">
							{{ t('groups.activities.management.queue.modal.applicant') }}
						</p>
						<p class="mt-2 font-medium text-toned">
							{{ application?.selected_character?.name || application?.user?.name || '—' }}
						</p>
						<p class="text-sm text-muted">
							{{ application?.selected_character?.world || application?.user?.name || '—' }}
						</p>
					</div>

					<div class="border border-default bg-default/60 p-4">
						<p class="text-xs uppercase tracking-[0.12em] text-muted">
							{{ t('groups.activities.management.roster.title') }}
						</p>
						<p class="mt-2 font-medium text-toned">
							{{ slot ? localizedText(slot.group_label, slot.group_key) : '—' }}
						</p>
						<p class="text-sm text-muted">
							{{ slot ? localizedText(slot.slot_label, slot.slot_key) : '—' }}
						</p>
					</div>
				</div>

				<UFormField
					v-if="isFillInSlot"
					:label="t('groups.activities.management.roster.fill_ins.filled_party')"
				>
					<USelectMenu
						:model-value="selectedFilledGroupKey ?? ''"
						:items="fillInPartyItems"
						value-key="value"
						label-key="label"
						size="lg"
						class="w-full"
						:disabled="isSubmitting"
						@update:model-value="updateSelectedFilledGroupKey"
					/>
				</UFormField>

				<UAlert
					v-if="slot?.assigned_character"
					color="warning"
					variant="soft"
					:title="modalTitle"
					:description="modalMode === 'edit'
						? t('groups.activities.management.queue.assignment_update_keeps_character', { character: slot.assigned_character.name })
						: t('groups.activities.management.queue.assignment_replace_character', { character: slot.assigned_character.name })"
				/>

				<UAlert
					v-if="!application?.selected_character"
					color="error"
					variant="soft"
					:title="t('general.error')"
					:description="t('groups.activities.management.queue.no_selected_character')"
				/>

				<UAlert
					v-else-if="!hasCompatibleOptions"
					color="error"
					variant="soft"
					:title="t('general.error')"
					:description="t('groups.activities.management.queue.incompatible_slot_answers')"
				/>

				<div v-else class="space-y-4">
					<UFormField
						v-for="field in targetFieldDefinitions"
						:key="field.key"
						:label="localizedText(field.label, field.key)"
					>
						<HolsterPairSelector
							v-if="isHolsterPairField(field)"
							:model-value="selections[field.key]"
							:options="field.options"
							:allowed-pairs="compatibleHolsterPairs(field)"
							@update:model-value="(value) => updateFieldSelection(field.key, value)"
						/>
						<USelectMenu
							v-else
							:model-value="selections[field.key]"
							:multiple="field.type === 'multi_select'"
							size="lg"
							class="w-full"
							:items="compatibleOptionsByField[field.key] ?? []"
							value-key="value"
							:placeholder="t('groups.activities.management.queue.filter_any')"
							@update:model-value="(value) => updateFieldSelection(field.key, value)"
						>
							<template #item-label="{ item }">
								<div class="flex min-w-0 items-center gap-2">
									<span class="truncate">{{ (item as CompatibleOption).label }}</span>
									<UIcon
										v-if="(item as CompatibleOption).isFavorite"
										name="mdi:heart"
										class="size-4 shrink-0 text-red-500"
									/>
								</div>
							</template>
						</USelectMenu>
					</UFormField>
				</div>
			</div>
		</template>

		<template #footer>
			<div class="flex w-full items-center justify-end gap-3">
				<UButton
					color="neutral"
					variant="ghost"
					:label="t('general.cancel')"
					@click="isOpen = false"
				/>
				<UButton
					color="primary"
					:icon="modalMode === 'edit' ? 'i-lucide-save' : 'i-lucide-user-plus'"
					:loading="isSubmitting"
					:disabled="!canSubmit"
					:label="submitLabel"
					@click="submit"
				/>
			</div>
		</template>
	</UModal>
</template>
