<script setup lang="ts">
import LocalizedTextFields from "@/components/Admin/ActivityTypes/LocalizedTextFields.vue";
import { slugify } from "@/utils/slugify";
import { computed } from "vue";
import { useI18n } from "vue-i18n";

type SchemaOption = {
	value: string
	label: Record<string, string>
}

type SchemaField = {
	key: string
	type: string
	source?: string | null
	required?: boolean
	label: Record<string, string>
	help_text?: Record<string, string>
	options?: SchemaOption[]
}

const props = defineProps<{
	modelValue: SchemaField[]
	locales: string[]
	title: string
	description: string
	fieldKind: 'slot' | 'application'
	supportedFieldTypes: string[]
	supportedOptionSources: string[]
}>();

const emit = defineEmits<{
	'update:modelValue': [value: SchemaField[]]
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

const createField = (): SchemaField => ({
	key: '',
	type: 'text',
	source: props.fieldKind === 'slot' ? 'character_classes' : null,
	required: true,
	label: createLocalizedRecord(),
	help_text: createLocalizedRecord(),
	options: [],
});

const createOption = (): SchemaOption => ({
	value: '',
	label: createLocalizedRecord(),
});

const addField = () => {
	emit('update:modelValue', [...props.modelValue, createField()]);
};

const updateField = (index: number, updates: Partial<SchemaField>) => {
	emit('update:modelValue', props.modelValue.map((field, fieldIndex) => (
		fieldIndex === index ? { ...field, ...updates } : field
	)));
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

const updateOption = (fieldIndex: number, optionIndex: number, updates: Partial<SchemaOption>) => {
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
	const previousEnglishLabel = currentField?.label?.en ?? '';
	const nextEnglishLabel = label?.en ?? '';
	const previousGeneratedKey = slugify(previousEnglishLabel);
	const nextGeneratedKey = slugify(nextEnglishLabel);

	updateField(index, {
		label,
		key: !currentField?.key || currentField.key === previousGeneratedKey
			? nextGeneratedKey
			: currentField.key,
	});
};
</script>

<template>
	<UCard class="dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
				<div>
					<h2 class="text-lg font-semibold">{{ title }}</h2>
					<p class="text-sm text-muted">{{ description }}</p>
				</div>

				<div class="flex items-center gap-2">
					<UBadge color="neutral" variant="subtle" :label="t('admin.activity_types.schema.fields_count', { count: modelValue.length })" />
					<UButton icon="i-lucide-plus" color="neutral" variant="soft" :label="t('admin.activity_types.schema.add_field')" @click="addField" />
				</div>
			</div>
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
								@update:model-value="(value) => updateField(index, { type: value })"
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
	</UCard>
</template>
