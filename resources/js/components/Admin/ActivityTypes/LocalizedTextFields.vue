<script setup lang="ts">
import { computed, ref } from "vue";

const props = defineProps<{
	label: string
	modelValue: Record<string, string | null | undefined>
	locales: string[]
	description?: string
	multiline?: boolean
	rows?: number
	placeholderPrefix?: string
}>();

const emit = defineEmits<{
	'update:modelValue': [value: Record<string, string>]
}>();

const isModalOpen = ref(false);
const primaryLocale = computed(() => props.locales[0] ?? 'en');

const localeStatuses = computed(() => props.locales.map((locale) => {
	const value = props.modelValue?.[locale];

	return {
		locale,
		isFilled: typeof value === 'string' && value.trim().length > 0,
	};
}));

const updateLocale = (locale: string, value: string) => {
	emit('update:modelValue', {
		...props.modelValue,
		[locale]: value,
	});
};
</script>

<template>
	<div class="flex flex-col gap-3">
		<div>
			<p class="text-sm font-medium text-highlighted">{{ label }}</p>
			<p v-if="description" class="text-sm text-muted">{{ description }}</p>
		</div>

		<div class="flex flex-col gap-2">
			<div class="flex flex-wrap items-center justify-between gap-2">
				<div class="flex flex-wrap items-center gap-2 text-xs text-muted">
					<div
						v-for="status in localeStatuses"
						:key="status.locale"
						class="flex items-center gap-1 rounded-full border border-default px-2 py-1"
					>
						<span class="font-semibold uppercase">{{ status.locale }}</span>
						<UIcon
							:name="status.isFilled ? 'i-lucide-circle-check-big' : 'i-lucide-circle-x'"
							:class="status.isFilled ? 'text-success' : 'text-error'"
						/>
					</div>
				</div>

				<UButton
					color="neutral"
					variant="soft"
					icon="i-lucide-languages"
					label="All locales"
					@click="isModalOpen = true"
				/>
			</div>

			<UTextarea
				v-if="multiline"
				:model-value="modelValue?.[primaryLocale] ?? ''"
				:rows="rows ?? 3"
				class="w-full"
				:placeholder="placeholderPrefix ? `${placeholderPrefix} (${primaryLocale})` : primaryLocale"
				@update:model-value="(value) => updateLocale(primaryLocale, value)"
			/>
			<UInput
				v-else
				:model-value="modelValue?.[primaryLocale] ?? ''"
				class="w-full"
				:placeholder="placeholderPrefix ? `${placeholderPrefix} (${primaryLocale})` : primaryLocale"
				@update:model-value="(value) => updateLocale(primaryLocale, value)"
			/>
		</div>

		<UModal
			v-model:open="isModalOpen"
			:title="label"
			:description="description"
			:ui="{ content: 'rounded-sm' }"
		>
			<template #body>
				<div class="grid gap-3 md:grid-cols-2">
					<div
						v-for="locale in locales"
						:key="locale"
						class="rounded-lg border border-default p-3"
					>
						<div class="mb-2 flex items-center justify-between">
							<span class="text-xs font-semibold uppercase tracking-wide text-muted">{{ locale }}</span>
							<UBadge v-if="locale === primaryLocale" color="primary" variant="subtle" label="Required" />
						</div>

						<UTextarea
							v-if="multiline"
							:model-value="modelValue?.[locale] ?? ''"
							:rows="rows ?? 3"
							class="w-full"
							:placeholder="placeholderPrefix ? `${placeholderPrefix} (${locale})` : locale"
							@update:model-value="(value) => updateLocale(locale, value)"
						/>
						<UInput
							v-else
							:model-value="modelValue?.[locale] ?? ''"
							class="w-full"
							:placeholder="placeholderPrefix ? `${placeholderPrefix} (${locale})` : locale"
							@update:model-value="(value) => updateLocale(locale, value)"
						/>
					</div>
				</div>
			</template>
		</UModal>
	</div>
</template>
