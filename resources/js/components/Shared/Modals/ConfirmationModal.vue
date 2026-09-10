<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import type { ConfirmationModalInput, ConfirmationModalSeverity } from "@/Types/Shared";
import { isConfirmationInputValid } from "@/utils/confirmationInput";

const props = withDefaults(defineProps<{
	open?: boolean
	title: string
	description: string
	severity?: ConfirmationModalSeverity
	warningText: string
	confirmLabel: string
	confirmLoading?: boolean
	confirmIcon?: string
	input?: ConfirmationModalInput
	onConfirm?: (payload: { inputValue: string }) => boolean | void | Promise<boolean | void>
}>(), {
	severity: 'error',
	confirmLoading: false,
	open: false,
});

const emit = defineEmits<{
	'update:open': [open: boolean]
	close: [value?: boolean]
	'after:leave': []
}>();

const { t } = useI18n();
const inputValue = ref(props.input?.initialValue ?? '');

const alertIcon = computed(() => ({
	error: 'i-lucide-triangle-alert',
	warning: 'i-lucide-triangle-alert',
	info: 'i-lucide-info',
	success: 'i-lucide-circle-check',
	neutral: 'i-lucide-circle-alert',
}[props.severity]));

const confirmColor = computed(() => ({
	error: 'error',
	warning: 'warning',
	info: 'info',
	success: 'success',
	neutral: 'neutral',
}[props.severity]));

const showInput = computed(() => Boolean(props.input));
const confirmDisabled = computed(() => props.confirmLoading || !isConfirmationInputValid(inputValue.value, props.input));

watch(() => props.open, (open) => {
	if (open) {
		inputValue.value = props.input?.initialValue ?? '';
	}
});

const handleOpenChange = (open: boolean) => {
	if (!open && props.confirmLoading) return;
	emit('update:open', open);

	if (!open) {
		emit('close', false);
	}
};

const handleConfirm = async () => {
	if (confirmDisabled.value) return;
	if (!props.onConfirm) {
		emit('close', true);
		return;
	}

	const result = await props.onConfirm({
		inputValue: inputValue.value,
	});

	if (result === false) {
		return;
	}

	emit('close', result ?? true);
};
</script>

<template>
	<UModal
		:open="open"
		:title="title"
		:description="description"
		:dismissible="!confirmLoading"
		:close="!confirmLoading"
		:ui="{ content: 'rounded-sm', header: 'border-0' }"
		@update:open="handleOpenChange"
		@after:leave="emit('after:leave')"
	>
		<template #body>
			<div class="flex flex-col gap-4">
				<UAlert
					:color="severity"
					variant="subtle"
					:icon="alertIcon"
					:title="warningText"
				/>

				<UFormField
					v-if="showInput"
					:label="input?.label"
					:help="input?.help"
					:error="input?.error"
				>
					<UInput
						v-if="input?.type === 'text'"
						v-model="inputValue"
						class="w-full"
						:placeholder="input?.placeholder"
						:maxlength="input?.maxlength"
						:disabled="confirmLoading"
						autocomplete="off"
						@keydown.enter.prevent="handleConfirm"
					/>
					<UTextarea
						v-else
						v-model="inputValue"
						class="w-full"
						:rows="input?.rows ?? 4"
						:placeholder="input?.placeholder"
						:maxlength="input?.maxlength"
						:disabled="confirmLoading"
					/>
				</UFormField>

				<div class="flex justify-end gap-2">
					<UButton
						color="neutral"
						variant="ghost"
						:label="t('general.cancel')"
						:disabled="confirmLoading"
						@click="emit('close', false)"
					/>
					<UButton
						:color="confirmColor"
						:icon="confirmIcon"
						:label="confirmLabel"
						:loading="confirmLoading"
						:disabled="confirmDisabled"
						@click="handleConfirm"
					/>
				</div>
			</div>
		</template>
	</UModal>
</template>
