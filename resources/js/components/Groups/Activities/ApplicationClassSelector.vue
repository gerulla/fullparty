<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";

type ClassOption = {
	key: string
	label: Record<string, string | null | undefined>
	meta?: {
		icon_url?: string | null
		role?: string | null
		shorthand?: string | null
	} | null
}

const props = defineProps<{
	label: string
	description?: string
	required?: boolean
	error?: string
	options: ClassOption[]
	modelValue: unknown
	multiple?: boolean
	disabled?: boolean
}>();

const emit = defineEmits<{
	'update:modelValue': [value: unknown]
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));
const isOpen = ref(false);

const roleGroups = [
	{ key: 'tank', label: computed(() => t('groups.activities.application.class_picker.categories.tank')) },
	{ key: 'healer', label: computed(() => t('groups.activities.application.class_picker.categories.healer')) },
	{ key: 'melee dps', label: computed(() => t('groups.activities.application.class_picker.categories.melee')) },
	{ key: 'physical ranged dps', label: computed(() => t('groups.activities.application.class_picker.categories.phys')) },
	{ key: 'magic ranged dps', label: computed(() => t('groups.activities.application.class_picker.categories.magic')) },
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

const groupedOptions = computed(() => roleGroups
	.map((group) => ({
		key: group.key,
		label: group.label.value,
		options: props.options.filter((option) => option.meta?.role === group.key),
	}))
	.filter((group) => group.options.length > 0));

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
			<div class="flex flex-col gap-5 p-4">
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
								class="flex items-center justify-center rounded-lg border-2 transition-transform duration-150 ease-out hover:scale-105"
								:class="isSelected(option.key)
									? 'border-primary bg-primary/10 text-toned'
									: 'border-default bg-muted/10 text-muted hover:border-primary'"
								@click="toggleOption(option.key)"
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
