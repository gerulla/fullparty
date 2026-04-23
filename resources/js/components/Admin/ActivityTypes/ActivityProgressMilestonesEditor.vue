<script setup lang="ts">
import ActivityTypeSectionCard from "@/components/Admin/ActivityTypes/ActivityTypeSectionCard.vue";
import LocalizedTextFields from "@/components/Admin/ActivityTypes/LocalizedTextFields.vue";
import { slugify } from "@/utils/slugify";
import { computed } from "vue";
import { useI18n } from "vue-i18n";

type ProgressMilestone = {
	key: string
	label: Record<string, string>
	order: number
	fflogs_matcher: {
		type: 'encounter' | 'phase'
		encounter_id: number | null
		phase_id: number | null
	}
}

type ProgressSchema = {
	milestones: ProgressMilestone[]
}

const props = defineProps<{
	modelValue: ProgressSchema
	locales: string[]
}>();

const emit = defineEmits<{
	'update:modelValue': [value: ProgressSchema]
}>();

const { t } = useI18n();

const milestones = computed(() => props.modelValue?.milestones ?? []);
const matcherTypeOptions = computed(() => [
	{
		label: t('admin.activity_types.progress.matcher_types.encounter'),
		value: 'encounter',
	},
	{
		label: t('admin.activity_types.progress.matcher_types.phase'),
		value: 'phase',
	},
]);

const createLocalizedRecord = () => Object.fromEntries(props.locales.map((locale) => [locale, '']));

const createMilestone = (order: number): ProgressMilestone => ({
	key: '',
	label: createLocalizedRecord(),
	order,
	fflogs_matcher: {
		type: 'encounter',
		encounter_id: null,
		phase_id: null,
	},
});

const updateMilestones = (nextMilestones: ProgressMilestone[]) => {
	emit('update:modelValue', {
		milestones: nextMilestones.map((milestone, index) => ({
			...milestone,
			order: index + 1,
		})),
	});
};

const addMilestone = () => {
	updateMilestones([
		...milestones.value,
		createMilestone(milestones.value.length + 1),
	]);
};

const updateMilestone = (index: number, updates: Partial<ProgressMilestone>) => {
	updateMilestones(milestones.value.map((milestone, milestoneIndex) => (
		milestoneIndex === index ? { ...milestone, ...updates } : milestone
	)));
};

const removeMilestone = (index: number) => {
	updateMilestones(milestones.value.filter((_, milestoneIndex) => milestoneIndex !== index));
};

const moveMilestone = (index: number, direction: -1 | 1) => {
	const targetIndex = index + direction;

	if (targetIndex < 0 || targetIndex >= milestones.value.length) {
		return;
	}

	const nextMilestones = [...milestones.value];
	const [movedMilestone] = nextMilestones.splice(index, 1);

	nextMilestones.splice(targetIndex, 0, movedMilestone);
	updateMilestones(nextMilestones);
};

const updateMilestoneLabel = (index: number, label: Record<string, string>) => {
	const currentMilestone = milestones.value[index];
	const fallbackLocale = props.locales[0] ?? 'en';
	const previousLabel = currentMilestone?.label?.[fallbackLocale] ?? '';
	const nextLabel = label?.[fallbackLocale] ?? '';
	const previousGeneratedKey = slugify(previousLabel);
	const nextGeneratedKey = slugify(nextLabel);

	updateMilestone(index, {
		label,
		key: !currentMilestone?.key || currentMilestone.key === previousGeneratedKey
			? nextGeneratedKey
			: currentMilestone.key,
	});
};
</script>

<template>
	<ActivityTypeSectionCard
		:title="t('admin.activity_types.progress.title')"
		:description="t('admin.activity_types.progress.subtitle')"
	>
		<template #headerMeta>
			<UBadge color="neutral" variant="subtle" :label="t('admin.activity_types.progress.milestones_count', { count: milestones.length })" />
		</template>

		<template #headerActions>
			<UButton icon="i-lucide-plus" color="neutral" variant="soft" :label="t('admin.activity_types.progress.add_milestone')" @click="addMilestone" />
		</template>

		<div class="flex flex-col gap-4">
			<UCard
				v-for="(milestone, index) in milestones"
				:key="`progress-milestone-${index}`"
				class="border border-default"
			>
				<div class="flex flex-col gap-4">
					<div class="flex items-center justify-between gap-3">
						<div>
							<h3 class="font-semibold">{{ t('admin.activity_types.progress.milestone_title', { index: index + 1 }) }}</h3>
							<p class="text-sm text-muted">{{ t('admin.activity_types.progress.milestone_hint') }}</p>
						</div>

						<div class="flex items-center gap-2">
							<UButton
								color="neutral"
								variant="ghost"
								icon="i-lucide-arrow-up"
								:disabled="index === 0"
								@click="moveMilestone(index, -1)"
							/>
							<UButton
								color="neutral"
								variant="ghost"
								icon="i-lucide-arrow-down"
								:disabled="index === milestones.length - 1"
								@click="moveMilestone(index, 1)"
							/>
							<UButton
								color="error"
								variant="ghost"
								icon="i-lucide-trash-2"
								:label="t('general.remove')"
								@click="removeMilestone(index)"
							/>
						</div>
					</div>

					<div class="grid gap-4 md:grid-cols-2">
						<UFormField :label="t('admin.activity_types.progress.key')" required>
							<UInput
								:model-value="milestone.key"
								class="w-full"
								:placeholder="t('admin.activity_types.progress.key_placeholder')"
								@update:model-value="(value) => updateMilestone(index, { key: value })"
							/>
						</UFormField>

						<UFormField :label="t('admin.activity_types.progress.order')">
							<UInput :model-value="milestone.order" class="w-full" readonly />
						</UFormField>
					</div>

					<div class="rounded-lg border border-default p-4">
						<div class="mb-4">
							<h4 class="font-semibold">{{ t('admin.activity_types.progress.fflogs_title') }}</h4>
							<p class="text-sm text-muted">{{ t('admin.activity_types.progress.fflogs_subtitle') }}</p>
						</div>

						<div class="grid gap-4 md:grid-cols-3">
							<UFormField :label="t('admin.activity_types.progress.matcher_type')" required>
								<USelect
									:model-value="milestone.fflogs_matcher?.type ?? 'encounter'"
									:items="matcherTypeOptions"
									value-key="value"
									class="w-full"
									@update:model-value="(value) => updateMilestone(index, {
										fflogs_matcher: {
											type: value,
											encounter_id: milestone.fflogs_matcher?.encounter_id ?? null,
											phase_id: value === 'phase' ? (milestone.fflogs_matcher?.phase_id ?? 1) : null,
										},
									})"
								/>
							</UFormField>

							<UFormField :label="t('admin.activity_types.progress.encounter_id')" required>
								<UInput
									:model-value="milestone.fflogs_matcher?.encounter_id ? String(milestone.fflogs_matcher.encounter_id) : ''"
									type="number"
									min="1"
									class="w-full"
									:placeholder="t('admin.activity_types.progress.encounter_id_placeholder')"
									@update:model-value="(value) => updateMilestone(index, {
										fflogs_matcher: {
											type: milestone.fflogs_matcher?.type ?? 'encounter',
											encounter_id: Number(value) > 0 ? Number(value) : null,
											phase_id: milestone.fflogs_matcher?.phase_id ?? null,
										},
									})"
								/>
							</UFormField>

							<UFormField
								v-if="milestone.fflogs_matcher?.type === 'phase'"
								:label="t('admin.activity_types.progress.phase_id')"
								required
							>
								<UInput
									:model-value="milestone.fflogs_matcher?.phase_id ? String(milestone.fflogs_matcher.phase_id) : ''"
									type="number"
									min="1"
									class="w-full"
									:placeholder="t('admin.activity_types.progress.phase_id_placeholder')"
									@update:model-value="(value) => updateMilestone(index, {
										fflogs_matcher: {
											type: milestone.fflogs_matcher?.type ?? 'phase',
											encounter_id: milestone.fflogs_matcher?.encounter_id ?? null,
											phase_id: Number(value) > 0 ? Number(value) : null,
										},
									})"
								/>
							</UFormField>
						</div>
					</div>

					<LocalizedTextFields
						:model-value="milestone.label"
						:locales="locales"
						:label="t('admin.activity_types.progress.label')"
						:description="t('admin.activity_types.progress.label_help')"
						:placeholder-prefix="t('admin.activity_types.progress.label_placeholder')"
						@update:model-value="(value) => updateMilestoneLabel(index, value)"
					/>
				</div>
			</UCard>

			<UAlert
				v-if="milestones.length === 0"
				color="neutral"
				variant="soft"
				icon="i-lucide-flag"
				:title="t('admin.activity_types.progress.empty_title')"
				:description="t('admin.activity_types.progress.empty_description')"
			/>
		</div>
	</ActivityTypeSectionCard>
</template>
