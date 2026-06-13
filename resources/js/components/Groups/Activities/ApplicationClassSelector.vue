<script setup lang="ts">
import type { ApplicationQuestionOption } from "@/Types/ActivityApplications";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";

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
	'update:modelValue': [value: unknown]
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));
const isOpen = ref(false);
let lastTouchToggleAt = 0;
let touchStartPosition: { x: number, y: number } | null = null;

const roleGroups = [
	{ key: 'tank', icon: 'i-lucide-shield', label: computed(() => t('groups.activities.application.class_picker.categories.tank')) },
	{ key: 'healer', icon: 'i-lucide-heart-pulse', label: computed(() => t('groups.activities.application.class_picker.categories.healer')) },
	{ key: 'melee dps', icon: 'i-lucide-swords', label: computed(() => t('groups.activities.application.class_picker.categories.melee')) },
	{ key: 'physical ranged dps', icon: 'i-lucide-crosshair', label: computed(() => t('groups.activities.application.class_picker.categories.phys')) },
	{ key: 'magic ranged dps', icon: 'i-lucide-sparkles', label: computed(() => t('groups.activities.application.class_picker.categories.magic')) },
];

const selectedKeys = computed<string[]>({
	get: () => {
		if (props.multiple) {
			return Array.isArray(props.modelValue)
				? props.modelValue.filter((value): value is string => typeof value === 'string')
				: [];
		}

		return typeof props.modelValue === 'string' && props.modelValue !== ''
			? [props.modelValue]
			: [];
	},
	set: (value) => {
		if (props.multiple) {
			emit('update:modelValue', value);

			return;
		}

		emit('update:modelValue', value[0] ?? '');
	},
});

const selectedItems = computed(() => props.options.filter((option) => selectedKeys.value.includes(option.key)));
const favoriteOptions = computed(() => {
	const favoriteKeys = new Set(props.favoriteOptionKeys ?? []);

	return props.options.filter((option) => favoriteKeys.has(option.key));
});

const groupedOptions = computed(() => roleGroups
	.map((group) => ({
		key: group.key,
		icon: group.icon,
		label: group.label.value,
		options: props.options.filter((option) => option.meta?.role === group.key),
	}))
	.filter((group) => group.options.length > 0));
const ungroupedOptions = computed(() => props.options.filter((option) => !option.meta?.role));
const ungroupedOptionLabel = computed(() => t('groups.activities.application.class_picker.categories.other'));

const summaryLabel = computed(() => {
	if (selectedItems.value.length === 0) {
		return t('groups.activities.application.class_picker.empty');
	}

	if (!props.multiple && selectedItems.value[0]) {
		return localizedValue(selectedItems.value[0].label, locale.value, fallbackLocale.value) || selectedItems.value[0].key;
	}

	return t('groups.activities.application.class_picker.selected_count', { count: selectedItems.value.length });
});

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

	emit('update:modelValue', selectedKeys.value.includes(optionKey) ? '' : optionKey);
	isOpen.value = false;
};

const handlePointerToggle = (optionKey: string, event: PointerEvent) => {
	if (event.pointerType === 'mouse') {
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
	if (event.pointerType !== 'mouse') {
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
			<UBadge
				v-for="item in selectedItems"
				:key="item.key"
				color="neutral"
				variant="soft"
				:label="localizedValue(item.label, locale, fallbackLocale) || item.key"
			/>
		</div>
	</UFormField>

	<UModal v-model:open="isOpen">
		<template #content>
			<div class="flex max-h-[calc(100dvh-2rem)] flex-col gap-5 overflow-y-auto p-4">
				<div class="flex items-start justify-between gap-4">
					<div class="space-y-1">
						<h3 class="font-semibold text-lg text-toned">{{ label }}</h3>
						<p v-if="description" class="text-sm text-muted">{{ description }}</p>
					</div>

					<UButton
						color="neutral"
						variant="ghost"
						icon="i-lucide-x"
						@click="isOpen = false"
					/>
				</div>

				<div class="flex flex-row flex-wrap gap-5">
					<section
						v-for="group in groupedOptions"
						:key="group.key"
						class="space-y-2"
					>
						<div class="flex items-center gap-3">
							<p class="font-medium text-sm text-toned">{{ group.label }}</p>
							<div class="h-px flex-1 bg-default"></div>
						</div>

						<div class="w-full flex flex-row gap-2 ">
							<button
								v-for="option in group.options"
								:key="option.key"
								type="button"
								class="application-class-option"
								:class="isSelected(option.key)
									? 'application-class-option--selected'
									: 'application-class-option--idle'"
								@pointerdown="handlePointerStart"
								@pointerup="handlePointerToggle(option.key, $event)"
								@click="handleClickToggle(option.key)"
							>
								<img
									v-if="option.meta?.icon_url"
									:src="option.meta.icon_url"
									:alt="localizedValue(option.label, locale, fallbackLocale) || option.key"
									class="size-10 rounded-sm"
								/>
								<div
									v-else
									class="flex size-10 items-center justify-center rounded-sm bg-muted text-xs font-semibold text-toned"
								>
									{{ option.meta?.shorthand || localizedValue(option.label, locale, fallbackLocale)?.slice(0, 2) || option.key.slice(0, 2) }}
								</div>
							</button>
						</div>
					</section>

					<section
						v-if="ungroupedOptions.length > 0"
						class="space-y-2"
					>
						<div class="flex items-center gap-3">
							<p class="font-medium text-sm text-toned">{{ ungroupedOptionLabel }}</p>
							<div class="h-px flex-1 bg-default"></div>
						</div>

						<div class="w-full flex flex-row gap-2 ">
							<button
								v-for="option in ungroupedOptions"
								:key="option.key"
								type="button"
								class="application-class-option"
								:class="isSelected(option.key)
									? 'application-class-option--selected'
									: 'application-class-option--idle'"
								@pointerdown="handlePointerStart"
								@pointerup="handlePointerToggle(option.key, $event)"
								@click="handleClickToggle(option.key)"
							>
								<img
									v-if="option.meta?.icon_url"
									:src="option.meta.icon_url"
									:alt="localizedValue(option.label, locale, fallbackLocale) || option.key"
									class="size-10 rounded-sm"
								/>
								<div
									v-else
									class="flex size-10 items-center justify-center rounded-sm bg-muted text-xs font-semibold text-toned"
								>
									{{ option.meta?.shorthand || localizedValue(option.label, locale, fallbackLocale)?.slice(0, 2) || option.key.slice(0, 2) }}
								</div>
							</button>
						</div>
					</section>
				</div>

				<div
					v-if="multiple && options.length > 0"
					class="border-t border-default pt-4"
				>
					<p class="mb-2 text-xs font-medium uppercase text-muted">
						{{ t('groups.activities.application.quick_select.title') }}
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

						<UButton
							v-for="group in groupedOptions"
							:key="`shortcut-${group.key}`"
							color="neutral"
							:variant="areOptionsSelected(group.options) ? 'solid' : 'soft'"
							size="sm"
							:icon="group.icon"
							:label="group.label"
							:disabled="disabled"
							@click="toggleOptionGroup(group.options)"
						/>

						<UButton
							v-if="ungroupedOptions.length > 0"
							color="neutral"
							:variant="areOptionsSelected(ungroupedOptions) ? 'solid' : 'soft'"
							size="sm"
							icon="i-lucide-asterisk"
							:label="ungroupedOptionLabel"
							:disabled="disabled"
							@click="toggleOptionGroup(ungroupedOptions)"
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

.application-class-option {
	@apply flex touch-manipulation select-none items-center justify-center rounded-lg border-2 transition-transform duration-150 ease-out;
}

.application-class-option--selected {
	@apply border-primary bg-primary/10 text-toned;
}

.application-class-option--idle {
	@apply border-default bg-muted/10 text-muted;
}

@media (hover: hover) and (pointer: fine) {
	.application-class-option {
		@apply hover:scale-105;
	}

	.application-class-option--idle {
		@apply hover:border-primary;
	}
}
</style>
