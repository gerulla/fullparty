<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import ApplicationClassSelector from "@/components/Groups/Activities/ApplicationClassSelector.vue";

type QuestionOption = {
	key: string
	label: Record<string, string | null | undefined>
	meta?: {
		icon_url?: string | null
		role?: string | null
		shorthand?: string | null
	} | null
}

type ApplicationQuestion = {
	key: string
	label: Record<string, string | null | undefined>
	type: string
	source: string | null
	required?: boolean
	help_text?: Record<string, string | null | undefined> | null
	options: QuestionOption[]
}

const props = defineProps<{
	question: ApplicationQuestion
	modelValue: unknown
	error?: string
	disabled?: boolean
}>();

const emit = defineEmits<{
	'update:modelValue': [value: unknown]
}>();

const { locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));

const label = computed(() => localizedValue(props.question.label, locale.value, fallbackLocale.value) || props.question.key);
const helpText = computed(() => localizedValue(props.question.help_text ?? null, locale.value, fallbackLocale.value) || undefined);

const optionItems = computed(() => props.question.options
	.filter((option) => option.key !== '')
	.map((option) => ({
		label: localizedValue(option.label, locale.value, fallbackLocale.value) || option.key,
		value: option.key,
	})));

const isClassSelector = computed(() => props.question.source === 'character_classes'
	&& (props.question.type === 'single_select' || props.question.type === 'multi_select'));

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
		v-if="!isClassSelector"
		:label="label"
		:description="helpText"
		:error="error"
		:required="Boolean(question.required)"
	>
		<UInput
			v-if="question.type === 'text' || question.type === 'url'"
			v-model="textValue"
			size="lg"
			class="w-full"
			:type="question.type === 'url' ? 'url' : 'text'"
			:disabled="disabled"
		/>

		<UTextarea
			v-else-if="question.type === 'textarea'"
			v-model="textValue"
			size="lg"
			class="w-full"
			:rows="5"
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

		<UCheckbox
			v-else-if="question.type === 'boolean'"
			v-model="booleanValue"
			:label="helpText"
			:disabled="disabled"
		/>

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
			:disabled="disabled"
		/>
	</UFormField>

	<ApplicationClassSelector
		v-else
		:label="label"
		:description="helpText"
		:error="error"
		:required="Boolean(question.required)"
		:options="question.options"
		:model-value="modelValue"
		:multiple="question.type === 'multi_select'"
		:disabled="disabled"
		@update:model-value="emit('update:modelValue', $event)"
	/>
</template>
