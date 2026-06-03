<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	modelValue: boolean
	error?: string
}>();

const emit = defineEmits<{
	"update:modelValue": [value: boolean]
}>();

const { t } = useI18n();

const visibilityState = computed(() => props.modelValue
	? t('groups.activities.create.fields.visibility.public')
	: t('groups.activities.create.fields.visibility.members_only'));

const visibilityLabel = computed(() => t('groups.activities.create.fields.visibility.state_label', {
	visibility: visibilityState.value,
}));

const visibilityDescription = computed(() => props.modelValue
	? t('groups.activities.create.fields.visibility.public_description')
	: t('groups.activities.create.fields.visibility.members_only_description'));
</script>

<template>
	<UFormField
		:label="visibilityLabel"
		:description="visibilityDescription"
		:error="error"
		orientation="horizontal"
		class="rounded-lg border border-default px-4 py-4"
	>
		<USwitch
			:model-value="modelValue"
			:aria-label="t('groups.activities.create.fields.visibility.toggle_label')"
			@update:model-value="(value) => emit('update:modelValue', Boolean(value))"
		/>
	</UFormField>
</template>
