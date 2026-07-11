<script setup lang="ts">
import type { ApplicationQuestion } from "@/Types/ActivityApplications";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import ApplicationClassSelector from "@/components/Groups/Activities/ApplicationClassSelector.vue";
import ApplicationPhantomJobSelector from "@/components/Groups/Activities/ApplicationPhantomJobSelector.vue";
import { activityTextLimits } from "@/utils/activityTextLimits";
import { translateRaidPositionName } from "@/utils/characterJobTranslations";

const props = defineProps<{
	question: ApplicationQuestion
	modelValue: unknown
	error?: string
	disabled?: boolean
	favoriteOptionKeys?: string[]
	booleanControl?: 'checkbox' | 'toggle'
}>();

const emit = defineEmits<{
	'update:modelValue': [value: unknown]
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));

const label = computed(() => localizedValue(props.question.label, locale.value, fallbackLocale.value) || props.question.key);
const helpText = computed(() => localizedValue(props.question.help_text ?? null, locale.value, fallbackLocale.value) || undefined);
const showsRequiredIndicator = computed(() => Boolean(props.question.required) && props.question.type !== 'boolean');
const isRaidPositionQuestion = computed(() => props.question.source === 'raid_positions' || props.question.key.includes('raid_position'));

const optionLabel = (option: ApplicationQuestion["options"][number]) => {
	const fallback = localizedValue(option.label, locale.value, fallbackLocale.value) || option.key;

	if (isRaidPositionQuestion.value) {
		return translateRaidPositionName(t, { key: option.key, name: fallback }, fallback);
	}

	return fallback;
};

const optionItems = computed(() => props.question.options
	.filter((option) => option.key !== '')
	.map((option) => ({
		label: optionLabel(option),
		value: option.key,
	})));

const isClassSelector = computed(() => props.question.source === 'character_classes'
	&& (props.question.type === 'single_select' || props.question.type === 'multi_select'));
const isPhantomJobSelector = computed(() => props.question.source === 'phantom_jobs'
	&& (props.question.type === 'single_select' || props.question.type === 'multi_select'));
const isIconSelector = computed(() => isClassSelector.value || isPhantomJobSelector.value);

const singleSelectValue = computed({
	get: () => typeof props.modelValue === 'string' ? props.modelValue : undefined,
	set: (value: string | undefined) => emit('update:modelValue', value ?? ''),
});

const multiSelectValue = computed({
	get: () => Array.isArray(props.modelValue)
		? props.modelValue.filter((value): value is string => typeof value === 'string')
		: [],
	set: (value: string[] | undefined) => emit('update:modelValue', value ?? []),
});

const textValue = computed({
	get: () => typeof props.modelValue === 'string' ? props.modelValue : '',
	set: (value: string | number) => emit('update:modelValue', String(value ?? '')),
});

const textMaxLength = computed(() => {
	if (props.question.type === 'textarea') {
		return activityTextLimits.applicationAnswerTextarea;
	}

	if (props.question.type === 'url') {
		return activityTextLimits.applicationAnswerUrl;
	}

	return activityTextLimits.applicationAnswerText;
});

const numberValue = computed({
	get: () => typeof props.modelValue === 'number'
		? String(props.modelValue)
		: typeof props.modelValue === 'string'
			? props.modelValue
			: '',
	set: (value: string | number) => emit('update:modelValue', String(value ?? '')),
});

const booleanValue = computed({
	get: () => Boolean(props.modelValue),
	set: (value: boolean) => emit('update:modelValue', value),
});
</script>

<template>
	<UFormField
		v-if="!isIconSelector"
		:label="label"
		:description="helpText"
		:error="error"
		:required="showsRequiredIndicator"
	>
		<UInput
			v-if="question.type === 'text' || question.type === 'url'"
			v-model="textValue"
			size="lg"
			class="w-full"
			:type="question.type === 'url' ? 'url' : 'text'"
			:maxlength="textMaxLength"
			:disabled="disabled"
		/>

		<UTextarea
			v-else-if="question.type === 'textarea'"
			v-model="textValue"
			size="lg"
			class="w-full"
			:rows="5"
			:maxlength="textMaxLength"
			:disabled="disabled"
		/>

		<UInput
			v-else-if="question.type === 'number'"
			v-model="numberValue"
			size="lg"
			class="w-full"
			type="number"
			:disabled="disabled"
		/>

		<template v-else-if="question.type === 'boolean'">
			<USwitch
				v-if="booleanControl === 'toggle'"
				v-model="booleanValue"
				:disabled="disabled"
			/>

			<UCheckbox
				v-else
				v-model="booleanValue"
				:disabled="disabled"
			/>
		</template>

		<USelectMenu
			v-else-if="question.type === 'single_select'"
			v-model="singleSelectValue"
			size="lg"
			class="w-full"
			:items="optionItems"
			value-key="value"
			:disabled="disabled"
		/>

		<USelectMenu
			v-else-if="question.type === 'multi_select'"
			v-model="multiSelectValue"
			size="lg"
			class="w-full"
			multiple
			:items="optionItems"
			value-key="value"
			:disabled="disabled"
		/>

		<UInput
			v-else
			v-model="textValue"
			size="lg"
			class="w-full"
			:maxlength="textMaxLength"
			:disabled="disabled"
		/>
	</UFormField>

	<ApplicationClassSelector
		v-else-if="isClassSelector"
		:label="label"
		:description="helpText"
		:error="error"
		:required="Boolean(question.required)"
		:options="question.options"
		:model-value="modelValue"
		:multiple="question.type === 'multi_select'"
		:disabled="disabled"
		:favorite-option-keys="favoriteOptionKeys ?? []"
		@update:model-value="emit('update:modelValue', $event)"
	/>

	<ApplicationPhantomJobSelector
		v-else
		:label="label"
		:description="helpText"
		:error="error"
		:required="Boolean(question.required)"
		:options="question.options"
		:model-value="modelValue"
		:multiple="question.type === 'multi_select'"
		:disabled="disabled"
		:favorite-option-keys="favoriteOptionKeys ?? []"
		@update:model-value="emit('update:modelValue', $event)"
	/>
</template>
