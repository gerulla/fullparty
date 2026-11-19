<script setup lang="ts">
import type { ActivityTypeProgPoint } from "@/Types/AdminActivityTypes";
import ActivityTypeSectionCard from "@/components/Admin/ActivityTypes/ActivityTypeSectionCard.vue";
import LocalizedTextFields from "@/components/Admin/ActivityTypes/LocalizedTextFields.vue";
import ProgPointBannerField from "@/components/Admin/ActivityTypes/ProgPointBannerField.vue";
import { slugify } from "@/utils/slugify";
import { computed } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	modelValue: ActivityTypeProgPoint[]
	locales: string[]
	errors: Record<string, string>
}>();

const emit = defineEmits<{
	'update:modelValue': [value: ActivityTypeProgPoint[]]
}>();

const { t } = useI18n();

const progPoints = computed(() => props.modelValue ?? []);

const createLocalizedRecord = () => Object.fromEntries(props.locales.map((locale) => [locale, '']));

const createProgPoint = (): ActivityTypeProgPoint => ({
	key: '',
	label: createLocalizedRecord(),
});

const updateProgPoints = (nextProgPoints: ActivityTypeProgPoint[]) => {
	emit('update:modelValue', nextProgPoints);
};

const addProgPoint = () => {
	updateProgPoints([
		...progPoints.value,
		createProgPoint(),
	]);
};

const updateProgPoint = (index: number, updates: Partial<ActivityTypeProgPoint>) => {
	updateProgPoints(progPoints.value.map((progPoint, progPointIndex) => (
		progPointIndex === index ? { ...progPoint, ...updates } : progPoint
	)));
};

const removeProgPoint = (index: number) => {
	updateProgPoints(progPoints.value.filter((_, progPointIndex) => progPointIndex !== index));
};

const moveProgPoint = (index: number, direction: -1 | 1) => {
	const targetIndex = index + direction;

	if (targetIndex < 0 || targetIndex >= progPoints.value.length) {
		return;
	}

	const nextProgPoints = [...progPoints.value];
	const [movedProgPoint] = nextProgPoints.splice(index, 1);

	nextProgPoints.splice(targetIndex, 0, movedProgPoint);
	updateProgPoints(nextProgPoints);
};

const updateProgPointLabel = (index: number, label: Record<string, string>) => {
	const currentProgPoint = progPoints.value[index];
	const fallbackLocale = props.locales[0] ?? 'en';
	const previousLabel = currentProgPoint?.label?.[fallbackLocale] ?? '';
	const nextLabel = label?.[fallbackLocale] ?? '';
	const previousGeneratedKey = slugify(previousLabel);
	const nextGeneratedKey = slugify(nextLabel);

	updateProgPoint(index, {
		label,
		key: !currentProgPoint?.key || currentProgPoint.key === previousGeneratedKey
			? nextGeneratedKey
			: currentProgPoint.key,
	});
};
</script>

<template>
	<ActivityTypeSectionCard
		:title="t('admin.activity_types.prog_points.title')"
		:description="t('admin.activity_types.prog_points.subtitle')"
	>
		<template #headerMeta>
			<UBadge color="neutral" variant="subtle" :label="t('admin.activity_types.prog_points.count', { count: progPoints.length })" />
		</template>

		<template #headerActions>
			<UButton icon="i-lucide-plus" color="neutral" variant="soft" :label="t('admin.activity_types.prog_points.add')" @click="addProgPoint" />
		</template>

		<div class="flex flex-col gap-4">
			<section
				v-for="(progPoint, index) in progPoints"
				:key="`prog-point-${index}`"
				class="admin-inset min-w-0 p-3 sm:p-4"
			>
				<div class="flex flex-col gap-4">
					<div class="flex flex-wrap items-start justify-between gap-3">
						<div>
							<h3 class="font-semibold">{{ t('admin.activity_types.prog_points.item_title', { index: index + 1 }) }}</h3>
							<p class="text-sm text-muted">{{ t('admin.activity_types.prog_points.item_hint') }}</p>
						</div>

						<div class="flex flex-wrap items-center gap-2">
							<UButton
								color="neutral"
								variant="ghost"
								icon="i-lucide-arrow-up"
								:aria-label="t('forms.move_up')"
								:disabled="index === 0"
								@click="moveProgPoint(index, -1)"
							/>
							<UButton
								color="neutral"
								variant="ghost"
								icon="i-lucide-arrow-down"
								:aria-label="t('forms.move_down')"
								:disabled="index === progPoints.length - 1"
								@click="moveProgPoint(index, 1)"
							/>
							<UButton
								color="error"
								variant="ghost"
								icon="i-lucide-trash-2"
								:label="t('general.remove')"
								@click="removeProgPoint(index)"
							/>
						</div>
					</div>

					<UFormField :label="t('admin.activity_types.prog_points.key')" required>
						<UInput
							:model-value="progPoint.key"
							class="w-full"
							:placeholder="t('admin.activity_types.prog_points.key_placeholder')"
							@update:model-value="(value) => updateProgPoint(index, { key: value })"
						/>
					</UFormField>

					<LocalizedTextFields
						rounded
						:model-value="progPoint.label"
						:locales="locales"
						:label="t('admin.activity_types.prog_points.label')"
						:description="t('admin.activity_types.prog_points.label_help')"
						:placeholder-prefix="t('admin.activity_types.prog_points.label_placeholder')"
						@update:model-value="(value) => updateProgPointLabel(index, value)"
					/>

					<ProgPointBannerField
						:file="progPoint.banner_image"
						:image-url="progPoint.banner_image_url"
						:error="errors[`draft_prog_points.${index}.banner_image`] || errors[`draft_prog_points.${index}.banner_image_url`]"
						@select="file => updateProgPoint(index, { banner_image: file })"
						@remove="updateProgPoint(index, { banner_image: null, banner_image_url: null })"
					/>
				</div>
			</section>

			<UAlert
				v-if="progPoints.length === 0"
				color="neutral"
				variant="soft"
				icon="i-lucide-list-tree"
				:title="t('admin.activity_types.prog_points.empty_title')"
				:description="t('admin.activity_types.prog_points.empty_description')"
			/>
		</div>
	</ActivityTypeSectionCard>
</template>
