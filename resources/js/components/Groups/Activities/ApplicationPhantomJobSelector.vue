<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import type { ApplicationQuestionOption } from "@/Types/ActivityApplications";
import { localizedValue } from "@/utils/localizedValue";
import { translatePhantomJobName } from "@/utils/characterJobTranslations";

const props = defineProps<{
	label: string
	description?: string
	required?: boolean
	error?: string
	options: ApplicationQuestionOption[]
	modelValue: unknown
	multiple?: boolean
	disabled?: boolean
	favoriteOptionKeys?: string[]
}>();

const emit = defineEmits<{
	"update:modelValue": [value: unknown]
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? "en"));
const isOpen = ref(false);
let lastTouchToggleAt = 0;
let touchStartPosition: { x: number, y: number } | null = null;

const selectedKeys = computed<string[]>({
	get: () => {
		if (props.multiple) {
			return Array.isArray(props.modelValue)
				? props.modelValue.filter((value): value is string => typeof value === "string")
				: [];
		}

		return typeof props.modelValue === "string" && props.modelValue !== ""
			? [props.modelValue]
			: [];
	},
	set: (value) => {
		if (props.multiple) {
			emit("update:modelValue", value);

			return;
		}

		emit("update:modelValue", value[0] ?? "");
	},
});

const selectedItems = computed(() => props.options.filter((option) => selectedKeys.value.includes(option.key)));
const favoriteOptions = computed(() => {
	const favoriteKeys = new Set(props.favoriteOptionKeys ?? []);

	return props.options.filter((option) => favoriteKeys.has(option.key));
});

const summaryLabel = computed(() => {
	if (selectedItems.value.length === 0) {
		return t("groups.activities.application.phantom_picker.empty");
	}

	if (!props.multiple && selectedItems.value[0]) {
		return localizedValue(selectedItems.value[0].label, locale.value, fallbackLocale.value) || selectedItems.value[0].key;
	}

	return t("groups.activities.application.phantom_picker.selected_count", { count: selectedItems.value.length });
});

const optionIconUrl = (option: ApplicationQuestionOption) => option.meta?.transparent_icon_url
	|| option.meta?.icon_url
	|| option.meta?.sprite_url
	|| null;

const rawOptionLabel = (option: ApplicationQuestionOption) => localizedValue(option.label, locale.value, fallbackLocale.value) || option.key;
const optionLabel = (option: ApplicationQuestionOption) => translatePhantomJobName(
	t,
	{ name: rawOptionLabel(option) },
	rawOptionLabel(option),
);

const toggleOption = (optionKey: string) => {
	if (props.disabled) {
		return;
	}

	if (props.multiple) {
		selectedKeys.value = selectedKeys.value.includes(optionKey)
			? selectedKeys.value.filter((key) => key !== optionKey)
			: [...selectedKeys.value, optionKey];

		return;
	}

	emit("update:modelValue", selectedKeys.value.includes(optionKey) ? "" : optionKey);
	isOpen.value = false;
};

const handlePointerToggle = (optionKey: string, event: PointerEvent) => {
	if (event.pointerType === "mouse") {
		return;
	}

	const startPosition = touchStartPosition;
	touchStartPosition = null;

	if (!startPosition || Math.abs(event.clientX - startPosition.x) > 12 || Math.abs(event.clientY - startPosition.y) > 12) {
		return;
	}

	event.preventDefault();
	event.stopPropagation();
	lastTouchToggleAt = Date.now();
	toggleOption(optionKey);
};

const handlePointerStart = (event: PointerEvent) => {
	if (event.pointerType !== "mouse") {
		touchStartPosition = { x: event.clientX, y: event.clientY };
	}
};

const handleClickToggle = (optionKey: string) => {
	if (Date.now() - lastTouchToggleAt < 450) {
		return;
	}

	toggleOption(optionKey);
};

const toggleOptionGroup = (options: ApplicationQuestionOption[]) => {
	if (props.disabled || !props.multiple) {
		return;
	}

	if (areOptionsSelected(options)) {
		const optionKeys = new Set(options.map((option) => option.key));

		selectedKeys.value = selectedKeys.value.filter((key) => !optionKeys.has(key));

		return;
	}

	const nextKeys = [...selectedKeys.value];
	options.forEach((option) => {
		if (!nextKeys.includes(option.key)) {
			nextKeys.push(option.key);
		}
	});

	selectedKeys.value = nextKeys;
};

const areOptionsSelected = (options: ApplicationQuestionOption[]) => options.length > 0 && options
	.every((option) => selectedKeys.value.includes(option.key));

const isSelected = (optionKey: string) => selectedKeys.value.includes(optionKey);
</script>

<template>
	<UFormField
		:label="label"
		:description="description"
		:error="error"
		:required="required"
	>
		<UButton
			color="neutral"
			variant="outline"
			size="lg"
			class="w-full justify-between"
			:disabled="disabled"
			:label="summaryLabel"
			trailing-icon="i-lucide-chevron-down"
			@click="isOpen = true"
		/>

		<div
			v-if="selectedItems.length > 0"
			class="mt-3 flex flex-wrap gap-2"
		>
			<div
				v-for="item in selectedItems"
				:key="item.key"
				class="inline-flex items-center gap-2 rounded-sm border border-default bg-muted/20 px-2.5 py-1 text-xs font-medium text-toned"
			>
				<img
					v-if="optionIconUrl(item)"
					:src="optionIconUrl(item) || undefined"
					:alt="optionLabel(item)"
					class="size-5 rounded-sm object-contain"
				>
				<span>{{ optionLabel(item) }}</span>
			</div>
		</div>
	</UFormField>

	<UModal v-model:open="isOpen">
		<template #content>
			<div class="flex max-h-[calc(100dvh-2rem)] flex-col gap-5 overflow-y-auto p-4">
				<div class="flex items-start justify-between gap-4">
					<div class="space-y-1">
						<h3 class="text-lg font-semibold text-toned">{{ label }}</h3>
						<p v-if="description" class="text-sm text-muted">{{ description }}</p>
					</div>

					<UButton
						color="neutral"
						variant="ghost"
						icon="i-lucide-x"
						@click="isOpen = false"
					/>
				</div>

				<div class="grid grid-cols-3 gap-2 sm:grid-cols-3 sm:gap-3 md:grid-cols-4">
					<button
						v-for="option in options"
						:key="option.key"
						type="button"
						class="application-phantom-option"
						:class="isSelected(option.key)
							? 'application-phantom-option--selected'
							: 'application-phantom-option--idle'"
						@pointerdown="handlePointerStart"
						@pointerup="handlePointerToggle(option.key, $event)"
						@click="handleClickToggle(option.key)"
					>
						<img
							v-if="optionIconUrl(option)"
							:src="optionIconUrl(option) || undefined"
							:alt="optionLabel(option)"
							class="size-9 rounded-sm object-contain sm:size-12"
						>
						<div
							v-else
							class="flex size-9 items-center justify-center rounded-sm bg-muted text-xs font-semibold text-toned sm:size-12 sm:text-sm"
						>
							{{ optionLabel(option).slice(0, 2) }}
						</div>

						<span class="max-w-full break-words text-[11px] font-medium leading-tight sm:text-xs">
							{{ optionLabel(option) }}
						</span>
					</button>
				</div>

				<div
					v-if="multiple && options.length > 0"
					class="border-t border-default pt-4"
				>
					<p class="mb-2 text-xs font-medium uppercase text-muted">
						{{ t("groups.activities.application.quick_select.title") }}
					</p>

					<div class="flex flex-wrap gap-2">
						<UButton
							color="neutral"
							:variant="areOptionsSelected(options) ? 'solid' : 'soft'"
							size="sm"
							icon="i-lucide-check-check"
							:label="t('groups.activities.application.quick_select.all')"
							:disabled="disabled"
							@click="toggleOptionGroup(options)"
						/>

						<UButton
							color="neutral"
							:variant="areOptionsSelected(favoriteOptions) ? 'solid' : 'soft'"
							size="sm"
							icon="i-lucide-star"
							:label="t('groups.activities.application.quick_select.favorites')"
							:disabled="disabled || favoriteOptions.length === 0"
							@click="toggleOptionGroup(favoriteOptions)"
						/>
					</div>
				</div>

				<div class="flex justify-end">
					<UButton
						color="neutral"
						variant="outline"
						:label="t('general.close')"
						@click="isOpen = false"
					/>
				</div>
			</div>
		</template>
	</UModal>
</template>

<style scoped>
@reference '../../../../css/app.css';

.application-phantom-option {
	@apply flex min-h-20 touch-manipulation select-none flex-col items-center justify-center gap-1.5 rounded-sm border p-2 text-center transition duration-150 ease-out sm:min-h-28 sm:gap-2 sm:p-3;
}

.application-phantom-option--selected {
	@apply border-primary bg-primary/10 text-toned shadow-sm shadow-primary/10;
}

.application-phantom-option--idle {
	@apply border-default bg-muted/10 text-muted;
}

@media (hover: hover) and (pointer: fine) {
	.application-phantom-option {
		@apply hover:-translate-y-0.5;
	}

	.application-phantom-option--idle {
		@apply hover:border-primary hover:text-toned;
	}
}
</style>
