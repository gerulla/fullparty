<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import type { LocalizedText } from "@/Types/Common";
import type { QueueApplication, QueueFilterField } from "@/Types/ActivityQueue";
import type { ActivitySlot } from "@/Types/ActivityRoster";
import { translateCharacterClassName, translatePhantomJobName, translateRaidPositionName } from "@/utils/characterJobTranslations";

const props = defineProps<{
	open: boolean
	slot: ActivitySlot | null
	application: QueueApplication | null
	slotFieldDefinitions: QueueFilterField[]
	mode?: 'assign' | 'edit'
	isSubmitting?: boolean
}>();

const emit = defineEmits<{
	'update:open': [value: boolean]
	confirm: [payload: { applicationId: number, slotId: number, fieldValues: Record<string, string | string[]> }]
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));
const selections = ref<Record<string, string | string[]>>({});
const ANY_OPTION_KEY = 'any';

type CompatibleOption = {
	label: string
	value: string
	isFavorite: boolean
}

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

const localizedText = (value: LocalizedText, fallback: string) => (
	localizedValue(value, locale.value, fallbackLocale.value) || fallback
);

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
		const compatibleOptions = selectedAnyOption
			? field.options.filter((option) => option.key !== ANY_OPTION_KEY)
			: field.options.filter((option) => rawValues.includes(option.key));

		map[field.key] = compatibleOptions
			.map((option) => ({
				label: optionLabel(field, option),
				value: option.key,
				isFavorite: submittedValueSet.has(option.key) && preferredOptionKeys.has(option.key),
			}));
	}

	return map;
});

const hasCompatibleOptions = computed(() => targetFieldDefinitions.value.every((field) => {
	return (compatibleOptionsByField.value[field.key] ?? []).length > 0;
}));

const canSubmit = computed(() => {
	if (!props.slot || !props.application?.selected_character || !hasCompatibleOptions.value) {
		return false;
	}

	return targetFieldDefinitions.value.every((field) => {
		const selectedValue = selections.value[field.key];

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
	() => [props.open, props.slot?.id, props.application?.id] as const,
	() => {
		if (!props.open) {
			return;
		}

		if (!props.slot || !props.application) {
			selections.value = {};
			return;
		}

		const defaults: Record<string, string | string[]> = {};

		for (const field of targetFieldDefinitions.value) {
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

const updateFieldSelection = (fieldKey: string, value: string | string[] | undefined) => {
	selections.value = {
		...selections.value,
		[fieldKey]: value ?? '',
	};
};

const submit = () => {
	if (!props.slot || !props.application || !canSubmit.value) {
		return;
	}

	emit('confirm', {
		applicationId: props.application.id,
		slotId: props.slot.id,
		fieldValues: selections.value,
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
						<USelectMenu
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
