<script setup lang="ts">
import type { ActivityTypeSchemaField, ActivityTypeSchemaOption } from "@/Types/AdminActivityTypes";
import ActivityTypeSectionCard from "@/components/Admin/ActivityTypes/ActivityTypeSectionCard.vue";
import LocalizedTextFields from "@/components/Admin/ActivityTypes/LocalizedTextFields.vue";
import { slugify } from "@/utils/slugify";
import { computed } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	modelValue: ActivityTypeSchemaField[]
	locales: string[]
	title: string
	description: string
	fieldKind: 'slot' | 'application'
	supportedFieldTypes: string[]
	supportedOptionSources: string[]
}>();

const emit = defineEmits<{
	'update:modelValue': [value: ActivityTypeSchemaField[]]
}>();

const { t } = useI18n();

const fieldTypeOptions = computed(() => props.supportedFieldTypes.map((type) => ({
	label: t(`admin.activity_types.schema.field_types.${type}`),
	value: type,
})));

const optionSourceOptions = computed(() => props.supportedOptionSources.map((source) => ({
	label: t(`admin.activity_types.schema.option_sources.${source}`),
	value: source,
})));

const createLocalizedRecord = () => Object.fromEntries(props.locales.map((locale) => [locale, '']));
const createAnyLabelRecord = () => Object.fromEntries(props.locales.map((locale) => [locale, locale === 'en' ? 'Any' : '']));
const isSelectionType = (type: string) => type === 'single_select' || type === 'multi_select';
const isApplicationSelectionField = (field: ActivityTypeSchemaField) => props.fieldKind === 'application' && isSelectionType(field.type);

const createField = (): ActivityTypeSchemaField => ({
	key: '',
	type: 'text',
	source: props.fieldKind === 'slot' ? 'character_classes' : null,
	required: true,
	label: createLocalizedRecord(),
	help_text: createLocalizedRecord(),
	options: [],
});

const createOption = (): ActivityTypeSchemaOption => ({
	value: '',
	label: createLocalizedRecord(),
});

const addField = () => {
	emit('update:modelValue', [...props.modelValue, createField()]);
};

const updateField = (index: number, updates: Partial<ActivityTypeSchemaField>) => {
	emit('update:modelValue', props.modelValue.map((field, fieldIndex) => (
		fieldIndex === index ? { ...field, ...updates } : field
	)));
};

const updateFieldType = (index: number, type: string) => {
	updateField(index, isSelectionType(type)
		? { type }
		: { type, accepts_any: false });
};

const updateAcceptsAny = (index: number, acceptsAny: boolean) => {
	const field = props.modelValue[index];

	updateField(index, {
		accepts_any: acceptsAny,
		any_label: acceptsAny ? field.any_label ?? createAnyLabelRecord() : field.any_label,
	});
};

const removeField = (index: number) => {
	emit('update:modelValue', props.modelValue.filter((_, fieldIndex) => fieldIndex !== index));
};

const addOption = (index: number) => {
	const field = props.modelValue[index];

	updateField(index, {
		options: [...(field.options ?? []), createOption()],
	});
};

const updateOption = (fieldIndex: number, optionIndex: number, updates: Partial<ActivityTypeSchemaOption>) => {
	const field = props.modelValue[fieldIndex];
	const nextOptions = (field.options ?? []).map((option, currentOptionIndex) => (
		currentOptionIndex === optionIndex ? { ...option, ...updates } : option
	));

	updateField(fieldIndex, { options: nextOptions });
};

const removeOption = (fieldIndex: number, optionIndex: number) => {
	const field = props.modelValue[fieldIndex];
	updateField(fieldIndex, {
		options: (field.options ?? []).filter((_, currentOptionIndex) => currentOptionIndex !== optionIndex),
	});
};

const updateFieldLabel = (index: number, label: Record<string, string>) => {
	const currentField = props.modelValue[index];
	const fallbackLocale = props.locales[0] ?? 'en';
	const previousPrimaryLabel = currentField?.label?.[fallbackLocale] ?? '';
	const nextPrimaryLabel = label?.[fallbackLocale] ?? '';
	const previousGeneratedKey = slugify(previousPrimaryLabel);
	const nextGeneratedKey = slugify(nextPrimaryLabel);

	updateField(index, {
		label,
		key: !currentField?.key || currentField.key === previousGeneratedKey
			? nextGeneratedKey
			: currentField.key,
	});
};
</script>

<template>
	<ActivityTypeSectionCard
		:title="title"
		:description="description"
	>
		<template #headerMeta>
			<UBadge color="neutral" variant="subtle" :label="t('admin.activity_types.schema.fields_count', { count: modelValue.length })" />
		</template>

		<template #headerActions>
			<UButton icon="i-lucide-plus" color="neutral" variant="soft" :label="t('admin.activity_types.schema.add_field')" @click="addField" />
		</template>

		<div class="flex flex-col gap-4">
			<UCard
				v-for="(field, index) in modelValue"
				:key="`${fieldKind}-field-${index}`"
				class="border border-default"
			>
				<div class="flex flex-col gap-4">
					<div class="flex items-center justify-between">
						<div>
							<h3 class="font-semibold">{{ t('admin.activity_types.schema.field_title', { index: index + 1 }) }}</h3>
							<p class="text-sm text-muted">{{ t('admin.activity_types.schema.field_hint') }}</p>
						</div>

						<UButton
							color="error"
							variant="ghost"
							icon="i-lucide-trash-2"
							:label="t('general.remove')"
							@click="removeField(index)"
						/>
					</div>

					<div class="grid gap-4 md:grid-cols-3">
						<UFormField :label="t('admin.activity_types.schema.key')" required>
							<UInput
								:model-value="field.key"
								class="w-full"
								:placeholder="t('admin.activity_types.schema.key_placeholder')"
								@update:model-value="(value) => updateField(index, { key: value })"
							/>
						</UFormField>

						<UFormField :label="t('admin.activity_types.schema.type')" required>
							<USelect
								:model-value="field.type"
								:items="fieldTypeOptions"
								value-key="value"
								class="w-full"
								@update:model-value="(value) => updateFieldType(index, String(value))"
							/>
						</UFormField>

						<UFormField :label="t('admin.activity_types.schema.source')">
							<USelect
								:model-value="field.source ?? undefined"
								:items="optionSourceOptions"
								value-key="value"
								class="w-full"
								@update:model-value="(value) => updateField(index, { source: value })"
							/>
						</UFormField>
					</div>

					<UFormField
						:label="t('admin.activity_types.schema.required')"
						orientation="horizontal"
						class="max-w-xs"
					>
						<USwitch
							:model-value="Boolean(field.required)"
							@update:model-value="(value) => updateField(index, { required: value })"
						/>
					</UFormField>

					<div
						v-if="isApplicationSelectionField(field)"
						class="grid gap-4 md:grid-cols-[minmax(0,20rem)_1fr]"
					>
						<UFormField
							:label="t('admin.activity_types.schema.accepts_any')"
							:description="t('admin.activity_types.schema.accepts_any_help')"
							orientation="horizontal"
						>
							<USwitch
								:model-value="Boolean(field.accepts_any)"
								@update:model-value="(value) => updateAcceptsAny(index, value)"
							/>
						</UFormField>

						<LocalizedTextFields
							v-if="field.accepts_any"
							:model-value="field.any_label ?? createAnyLabelRecord()"
							:locales="locales"
							:label="t('admin.activity_types.schema.any_label')"
							:description="t('admin.activity_types.schema.any_label_help')"
							:placeholder-prefix="t('admin.activity_types.schema.any_label_placeholder')"
							@update:model-value="(value) => updateField(index, { any_label: value })"
						/>
					</div>

					<LocalizedTextFields
						:model-value="field.label"
						:locales="locales"
						:label="t('admin.activity_types.schema.label')"
						:description="t('admin.activity_types.schema.label_help')"
						:placeholder-prefix="t('admin.activity_types.schema.label_placeholder')"
						@update:model-value="(value) => updateFieldLabel(index, value)"
					/>

					<LocalizedTextFields
						:model-value="field.help_text ?? createLocalizedRecord()"
						:locales="locales"
						:label="t('admin.activity_types.schema.help_text')"
						:description="t('admin.activity_types.schema.help_text_help')"
						:placeholder-prefix="t('admin.activity_types.schema.help_text_placeholder')"
						multiline
						@update:model-value="(value) => updateField(index, { help_text: value })"
					/>

					<div
						v-if="field.source === 'static_options'"
						class="rounded-lg border border-default p-4"
					>
						<div class="mb-4 flex items-center justify-between">
							<div>
								<h4 class="font-semibold">{{ t('admin.activity_types.schema.options_title') }}</h4>
								<p class="text-sm text-muted">{{ t('admin.activity_types.schema.options_subtitle') }}</p>
							</div>

							<UButton icon="i-lucide-plus" color="neutral" variant="soft" :label="t('admin.activity_types.schema.add_option')" @click="addOption(index)" />
						</div>

						<div class="flex flex-col gap-4">
							<UCard
								v-for="(option, optionIndex) in field.options ?? []"
								:key="`option-${index}-${optionIndex}`"
								class="border border-default"
							>
								<div class="flex flex-col gap-4">
									<div class="flex items-center justify-between">
										<h5 class="font-medium">{{ t('admin.activity_types.schema.option_title', { index: optionIndex + 1 }) }}</h5>
										<UButton
											color="error"
											variant="ghost"
											icon="i-lucide-trash-2"
											:label="t('general.remove')"
											@click="removeOption(index, optionIndex)"
										/>
									</div>

									<UFormField :label="t('admin.activity_types.schema.option_value')" required>
										<UInput
											:model-value="option.value"
											class="w-full"
											:placeholder="t('admin.activity_types.schema.option_value_placeholder')"
											@update:model-value="(value) => updateOption(index, optionIndex, { value })"
										/>
									</UFormField>

									<LocalizedTextFields
										:model-value="option.label"
										:locales="locales"
										:label="t('admin.activity_types.schema.option_label')"
										:placeholder-prefix="t('admin.activity_types.schema.option_label_placeholder')"
										@update:model-value="(value) => updateOption(index, optionIndex, { label: value })"
									/>
								</div>
							</UCard>
						</div>
					</div>
				</div>
			</UCard>
		</div>
	</ActivityTypeSectionCard>
</template>
