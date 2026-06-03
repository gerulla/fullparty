<script setup lang="ts">
import type { ActivityMetadataOptions, ActivityTypeOption, OrganizerCharacterOption } from "@/Types/ActivityCore";
import { computed, ref, toRef, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useActivityFormFields } from "@/components/Groups/Activities/useActivityFormFields";
import { usePage } from "@inertiajs/vue3";
import { activityTextLimits } from "@/utils/activityTextLimits";
import ActivityVisibilityPicker from "@/components/Groups/Activities/ActivityVisibilityPicker.vue";

const props = defineProps<{
	step: number
	activityTypes: ActivityTypeOption[]
	organizerCharacters: OrganizerCharacterOption[]
	activityOptions: ActivityMetadataOptions
	lockActivityType?: boolean
	form: {
		activity_type_id: number | null
		organized_by_user_id: number | null
		organized_by_character_id: number | null
		status: string
		title: string
		notes: string
		starts_at: string | null
		duration_hours: number
		datacenter: string | null
		intensity: string
		min_item_level: number | null
		beginner_friendly: boolean
		run_style: string
		target_prog_point_key: string | null
		is_public: boolean
		needs_application: boolean
		allow_guest_applications: boolean
		errors: Record<string, string | undefined>
		processing: boolean
	}
}>();

const emit = defineEmits<{
	"update:step": [value: number]
}>();

const { t } = useI18n();
const page = usePage();
const datacenterOptions = computed(() => page.props.lookups?.datacenters ?? []);
const minimumItemLevelTouched = ref(false);
const minimumItemLevelEnabledState = ref(props.form.min_item_level !== null && props.form.min_item_level !== undefined);
const activeStep = computed({
	get: () => props.step,
	set: (value: number) => emit('update:step', Math.min(3, Math.max(0, value))),
});
const {
	activityDifficultyFilter,
	activityDifficultyItems,
	activityTypeItems,
	organizerCharacterItems,
	selectedOrganizerCharacter,
	selectedActivityType,
	progPointItems,
	statusItems,
	updateOrganizerCharacter,
	startDate,
	startHour,
	startMinute,
	hourItems,
	minuteItems,
	durationPresets,
	selectedDurationOption,
	isCustomDuration,
	normalizeDurationHours,
} = useActivityFormFields(
	toRef(props, 'activityTypes'),
	toRef(props, 'organizerCharacters'),
	props.form,
	{ mode: 'create' },
);

const intensityItems = computed(() => props.activityOptions.intensities.map((value) => ({
	label: t(`groups.activities.intensities.${value}`),
	value,
})));

const runStyleItems = computed(() => props.activityOptions.runStyles.map((value) => ({
	label: t(`groups.activities.run_styles.${value}`),
	value,
})));

const minimumItemLevelEnabled = computed({
	get: () => minimumItemLevelEnabledState.value,
	set: (enabled: boolean) => {
		minimumItemLevelTouched.value = true;
		minimumItemLevelEnabledState.value = enabled;

		if (!enabled) {
			props.form.min_item_level = null;

			return;
		}

		if (props.form.min_item_level === null || props.form.min_item_level === undefined) {
			props.form.min_item_level = selectedActivityType.value?.default_min_item_level ?? 1;
		}
	},
});

const updateMinimumItemLevel = (value: unknown) => {
	minimumItemLevelTouched.value = true;
	props.form.min_item_level = value === '' || value === null
		? null
		: Math.min(9999, Math.max(1, Number(value) || 1));
};

watch(selectedActivityType, (activityType, previousActivityType) => {
	if (minimumItemLevelTouched.value) {
		return;
	}

	const previousDefault = previousActivityType?.default_min_item_level ?? null;

	if (props.form.min_item_level === previousDefault) {
		const nextDefault = activityType?.default_min_item_level ?? null;

		props.form.min_item_level = nextDefault;
		minimumItemLevelEnabledState.value = nextDefault !== null && nextDefault !== undefined;
	}
}, { immediate: true });

watch(() => props.form.needs_application, (needsApplication) => {
	if (!needsApplication) {
		props.form.allow_guest_applications = false;
	}
}, { immediate: true });

watch(() => props.form.is_public, (isPublic) => {
	if (!isPublic) {
		props.form.allow_guest_applications = false;
	}
}, { immediate: true });

const stepCopy = computed(() => {
	const key = activeStep.value === 0
		? 'schedule'
		: activeStep.value === 1
			? 'activity'
			: 'requirements';

	return {
		title: t(`groups.activities.create.steps.${key}.title`),
		description: t(`groups.activities.create.steps.${key}.description`),
	};
});

const canContinue = computed(() => {
	if (activeStep.value === 0) {
		return Boolean(props.form.starts_at && props.form.duration_hours && props.form.datacenter);
	}

	if (activeStep.value === 1) {
		return Boolean(props.form.activity_type_id && props.form.organized_by_character_id && props.form.status);
	}

	return Boolean(props.form.run_style && props.form.intensity);
});

const goPrevious = () => {
	activeStep.value -= 1;
};

const goNext = () => {
	if (!canContinue.value) {
		return;
	}

	activeStep.value += 1;
};
</script>

<template>
	<UCard class="dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-col gap-1">
				<p class="font-semibold text-md">{{ stepCopy.title }}</p>
				<p class="text-sm text-muted">{{ stepCopy.description }}</p>
			</div>
		</template>

		<form class="flex flex-col gap-6" @submit.prevent="goNext">
			<section v-if="activeStep === 0" class="space-y-5">
				<div class="space-y-1">
					<p class="font-medium text-sm">{{ t('groups.activities.create.sections.schedule.title') }}</p>
					<p class="text-sm text-muted">{{ t('groups.activities.create.sections.schedule.subtitle') }}</p>
					<p class="text-xs text-muted">{{ t('groups.activities.create.fields.starts_at.server_time_hint') }}</p>
				</div>

				<div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,260px)_minmax(0,1fr)]">
					<UFormField
						:label="t('groups.activities.create.fields.start_date.label')"
						:error="form.errors.starts_at"
					>
						<UInput
							v-model="startDate"
							type="date"
							size="lg"
							class="w-full"
						/>
					</UFormField>

					<UFormField
						:label="t('groups.activities.create.fields.start_time.label')"
						:error="form.errors.starts_at"
					>
						<div class="grid grid-cols-[minmax(0,1fr)_24px_minmax(0,1fr)] items-center gap-3">
							<USelect
								v-model="startHour"
								size="lg"
								class="w-full"
								:items="hourItems"
								value-key="value"
								:placeholder="t('groups.activities.create.fields.start_time.hour_placeholder')"
							/>

							<div class="text-center font-medium text-muted">:</div>

							<USelect
								v-model="startMinute"
								size="lg"
								class="w-full"
								:items="minuteItems"
								value-key="value"
								:placeholder="t('groups.activities.create.fields.start_time.minute_placeholder')"
							/>
						</div>
					</UFormField>
				</div>

				<UFormField
					:label="t('groups.activities.create.fields.duration.label')"
					:error="form.errors.duration_hours"
					required
				>
					<div class="flex flex-col gap-3 xl:flex-row xl:items-center">
						<div class="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4">
							<UButton
								v-for="hours in durationPresets"
								:key="hours"
								type="button"
								size="lg"
								:variant="selectedDurationOption === String(hours) ? 'solid' : 'soft'"
								color="neutral"
								:label="`${hours}h`"
								@click="selectedDurationOption = String(hours)"
							/>

							<UButton
								type="button"
								size="lg"
								:variant="isCustomDuration ? 'solid' : 'soft'"
								color="neutral"
								:label="t('groups.activities.create.fields.duration.custom')"
								@click="selectedDurationOption = 'custom'"
							/>
						</div>

						<UInput
							:model-value="String(form.duration_hours ?? '')"
							type="number"
							min="1"
							max="24"
							step="0.5"
							size="lg"
							class="w-full xl:w-32"
							:disabled="!isCustomDuration"
							:placeholder="t('groups.activities.create.fields.duration.placeholder')"
							@focus="selectedDurationOption = 'custom'"
							@update:model-value="(value) => form.duration_hours = normalizeDurationHours(value)"
						/>
					</div>
				</UFormField>

				<div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
					<UFormField
						:label="t('groups.activities.create.fields.datacenter.label')"
						:error="form.errors.datacenter"
						required
					>
						<USelect
							v-model="form.datacenter"
							size="lg"
							class="w-full"
							:items="datacenterOptions"
							value-key="value"
							:placeholder="t('groups.activities.create.fields.datacenter.placeholder')"
						/>
					</UFormField>
				</div>

				<div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
					<ActivityVisibilityPicker
						v-model="form.is_public"
						:error="form.errors.is_public"
					/>

					<UFormField
						:label="t('groups.activities.create.fields.needs_application.label')"
						:description="t('groups.activities.create.fields.needs_application.help')"
						:error="form.errors.needs_application"
						orientation="horizontal"
						class="rounded-lg border border-default px-4 py-4"
					>
						<USwitch v-model="form.needs_application" />
					</UFormField>

					<UFormField
						v-if="form.needs_application && form.is_public"
						:label="t('groups.activities.create.fields.allow_guest_applications.label')"
						:description="t('groups.activities.create.fields.allow_guest_applications.help')"
						:error="form.errors.allow_guest_applications"
						orientation="horizontal"
						class="rounded-lg border border-default px-4 py-4"
					>
						<USwitch v-model="form.allow_guest_applications" />
					</UFormField>
				</div>
			</section>

			<section v-else-if="activeStep === 1" class="space-y-5">
				<div class="space-y-1">
					<p class="font-medium text-sm">{{ t('groups.activities.create.sections.basics.title') }}</p>
					<p class="text-sm text-muted">{{ t('groups.activities.create.sections.basics.subtitle') }}</p>
				</div>

				<div class="grid grid-cols-1 gap-5 xl:grid-cols-[220px_minmax(0,1fr)_minmax(0,1fr)]">
					<UFormField
						:label="t('groups.activities.create.fields.activity_type_filter.label')"
					>
						<USelect
							v-model="activityDifficultyFilter"
							size="lg"
							class="w-full"
							:items="activityDifficultyItems"
							value-key="value"
							:disabled="lockActivityType"
						/>
					</UFormField>

					<UFormField
						:label="t('groups.activities.create.fields.activity_type.label')"
						:error="form.errors.activity_type_id"
						required
					>
						<USelectMenu
							v-model="form.activity_type_id"
							size="lg"
							class="w-full"
							:items="activityTypeItems"
							value-key="value"
							:disabled="lockActivityType"
							:placeholder="t('groups.activities.create.fields.activity_type.placeholder')"
						/>
					</UFormField>

					<UFormField
						:label="t('groups.activities.create.fields.organizer.label')"
						:error="form.errors.organized_by_character_id || form.errors.organized_by_user_id"
						required
					>
						<USelectMenu
							:model-value="selectedOrganizerCharacter"
							class="w-full"
							size="lg"
							:avatar="{
								src: selectedOrganizerCharacter?.avatar_url,
								loading: 'lazy'
							}"
							:items="organizerCharacterItems"
							:placeholder="t('groups.activities.create.fields.organizer.placeholder')"
							@update:model-value="updateOrganizerCharacter"
						/>
					</UFormField>
				</div>

				<div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_220px]">
					<UFormField
						:label="t('groups.activities.create.fields.title.label')"
						:error="form.errors.title"
					>
						<UInput
							v-model="form.title"
							size="lg"
							class="w-full"
							:maxlength="activityTextLimits.title"
							:placeholder="t('groups.activities.create.fields.title.placeholder')"
						/>
					</UFormField>

					<UFormField
						:label="t('groups.activities.create.fields.status.label')"
						:error="form.errors.status"
						required
					>
						<USelect
							v-model="form.status"
							size="lg"
							class="w-full"
							:items="statusItems"
							value-key="value"
							:placeholder="t('groups.activities.create.fields.status.placeholder')"
						/>
					</UFormField>
				</div>

				<UFormField
					v-if="progPointItems.length > 0"
					:label="t('groups.activities.create.fields.prog_point.label')"
					:error="form.errors.target_prog_point_key"
				>
					<USelectMenu
						v-model="form.target_prog_point_key"
						size="lg"
						class="w-full"
						:items="progPointItems"
						value-key="value"
						:placeholder="t('groups.activities.create.fields.prog_point.placeholder')"
					/>
				</UFormField>
			</section>

			<section v-else class="space-y-5">
				<div class="space-y-1">
					<p class="font-medium text-sm">{{ t('groups.activities.create.sections.run_details.title') }}</p>
					<p class="text-sm text-muted">{{ t('groups.activities.create.sections.run_details.subtitle') }}</p>
				</div>

				<div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
					<div class="flex flex-col gap-3 rounded-lg border border-default px-4 py-4">
						<UFormField
							:label="t('groups.activities.create.fields.min_item_level.enabled_label')"
							:description="t('groups.activities.create.fields.min_item_level.enabled_help')"
							orientation="horizontal"
						>
							<USwitch v-model="minimumItemLevelEnabled" />
						</UFormField>

						<UFormField
							:label="t('groups.activities.create.fields.min_item_level.label')"
							:error="form.errors.min_item_level"
						>
							<UInput
								:model-value="form.min_item_level ?? ''"
								type="number"
								min="1"
								max="9999"
								size="lg"
								class="w-full"
								:disabled="!minimumItemLevelEnabled"
								:placeholder="t('groups.activities.create.fields.min_item_level.placeholder')"
								@update:model-value="updateMinimumItemLevel"
							/>
						</UFormField>
					</div>

					<UFormField
						:label="t('groups.activities.create.fields.beginner_friendly.label')"
						:description="t('groups.activities.create.fields.beginner_friendly.help')"
						:error="form.errors.beginner_friendly"
						orientation="horizontal"
						class="rounded-lg border border-default px-4 py-4"
					>
						<USwitch v-model="form.beginner_friendly" />
					</UFormField>

					<UFormField
						:label="t('groups.activities.create.fields.run_style.label')"
						:error="form.errors.run_style"
						required
					>
						<USelect
							v-model="form.run_style"
							size="lg"
							class="w-full"
							:items="runStyleItems"
							value-key="value"
							:placeholder="t('groups.activities.create.fields.run_style.placeholder')"
						/>
					</UFormField>

					<UFormField
						:label="t('groups.activities.create.fields.intensity.label')"
						:error="form.errors.intensity"
						required
					>
						<USelect
							v-model="form.intensity"
							size="lg"
							class="w-full"
							:items="intensityItems"
							value-key="value"
							:placeholder="t('groups.activities.create.fields.intensity.placeholder')"
						/>
					</UFormField>
				</div>

				<UFormField
					:label="t('groups.activities.create.fields.notes.label')"
					:error="form.errors.notes"
				>
					<UTextarea
						v-model="form.notes"
						size="lg"
						class="w-full"
						:rows="5"
						:maxlength="activityTextLimits.notes"
						:placeholder="t('groups.activities.create.fields.notes.placeholder')"
					/>
				</UFormField>
			</section>

			<div class="flex flex-col-reverse gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between">
				<UButton
					type="button"
					color="neutral"
					variant="soft"
					size="lg"
					icon="i-lucide-arrow-left"
					:label="t('groups.activities.create.navigation.back')"
					:disabled="activeStep === 0"
					@click="goPrevious"
				/>

				<UButton
					type="submit"
					color="primary"
					size="lg"
					trailing-icon="i-lucide-arrow-right"
					:label="activeStep === 2 ? t('groups.activities.create.navigation.review') : t('groups.activities.create.navigation.next')"
					:disabled="!canContinue"
				/>
			</div>
		</form>
	</UCard>
</template>
