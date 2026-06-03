<script setup lang="ts">
import { computed, ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import PageHeader from "@/components/PageHeader.vue";
import { useI18n } from "vue-i18n";
import ActivityCreateForm from "@/components/Groups/Activities/ActivityCreateForm.vue";
import ActivityCreateSummaryCard from "@/components/Groups/Activities/ActivityCreateSummaryCard.vue";
import ActivityCreateStepTimeline from "@/components/Groups/Activities/ActivityCreateStepTimeline.vue";
import type { ActivityMetadataOptions, ActivityTypeOption, OrganizerCharacterOption } from "@/Types/ActivityCore";

const props = defineProps<{
	group: {
		id: number
		name: string
		slug: string
		datacenter: string | null
		current_user_role: string | null
		permissions: {
			can_manage_activities: boolean
		}
	}
	activityTypes: ActivityTypeOption[]
	organizerCharacters: OrganizerCharacterOption[]
	activityOptions: ActivityMetadataOptions
	prefilledStartsAt: string | null
}>();

const { t } = useI18n();
const defaultOrganizerCharacter = props.organizerCharacters[0] ?? null;
const defaultActivityType = props.activityTypes[0] ?? null;
const currentStep = ref(0);

const form = useForm({
	activity_type_id: defaultActivityType?.id ?? null,
	organized_by_user_id: defaultOrganizerCharacter?.user_id ?? null,
	organized_by_character_id: defaultOrganizerCharacter?.id ?? null,
	status: 'draft',
	title: '',
	notes: '',
	starts_at: props.prefilledStartsAt,
	duration_hours: 2,
	datacenter: props.group.datacenter,
	intensity: props.activityOptions.intensities[0] ?? 'casual',
	min_item_level: defaultActivityType?.default_min_item_level ?? null,
	beginner_friendly: false,
	run_style: props.activityOptions.runStyles[0] ?? 'progression',
	target_prog_point_key: null as string | null,
	is_public: false,
	needs_application: true,
	allow_guest_applications: false,
});

const goBack = () => {
	router.get(route('groups.dashboard.activities.index', props.group.slug));
};

const hasActivityTypes = computed(() => props.activityTypes.length > 0);
const isReviewStep = computed(() => currentStep.value === 3);

const createSteps = computed(() => [
	{
		title: t('groups.activities.create.steps.schedule.title'),
		description: t('groups.activities.create.steps.schedule.description'),
		value: 0,
	},
	{
		title: t('groups.activities.create.steps.activity.title'),
		description: t('groups.activities.create.steps.activity.description'),
		value: 1,
	},
	{
		title: t('groups.activities.create.steps.requirements.title'),
		description: t('groups.activities.create.steps.requirements.description'),
		value: 2,
	},
	{
		title: t('groups.activities.create.steps.review.title'),
		description: t('groups.activities.create.steps.review.description'),
		value: 3,
	},
]);

const fieldStepMap: Record<string, number> = {
	starts_at: 0,
	duration_hours: 0,
	datacenter: 0,
	is_public: 0,
	needs_application: 0,
	allow_guest_applications: 0,
	activity_type_id: 1,
	organized_by_user_id: 1,
	organized_by_character_id: 1,
	status: 1,
	title: 1,
	target_prog_point_key: 1,
	min_item_level: 2,
	beginner_friendly: 2,
	run_style: 2,
	intensity: 2,
	notes: 2,
};

const submit = () => {
	form.post(route('groups.dashboard.activities.store', { group: props.group.slug }), {
		onError: (errors) => {
			const firstErrorField = Object.keys(errors)[0] ?? null;

			if (firstErrorField && fieldStepMap[firstErrorField] !== undefined) {
				currentStep.value = fieldStepMap[firstErrorField];
			}
		},
	});
};
</script>

<template>
	<div class="w-full">
		<UButton
			:label="t('groups.activities.back')"
			icon="i-lucide-arrow-left"
			variant="ghost"
			color="neutral"
			@click.stop="goBack"
		/>
		<PageHeader
			:title="t('groups.activities.create.title')"
			:subtitle="t('groups.activities.create.subtitle')"
		>
			<div v-if="hasActivityTypes" class="w-full xl:w-[62vw] xl:max-w-[64rem]">
				<ActivityCreateStepTimeline
					v-model="currentStep"
					:steps="createSteps"
				/>
			</div>
		</PageHeader>

		<UAlert
			v-if="!hasActivityTypes"
			class="mt-4"
			color="warning"
			variant="subtle"
			icon="i-lucide-triangle-alert"
			:title="t('groups.activities.create.no_types_title')"
			:description="t('groups.activities.create.no_types_description')"
		/>

		<div
			v-else
			class="mt-4"
			:class="isReviewStep
				? 'flex flex-col items-center gap-4'
				: 'mx-auto grid w-full max-w-5xl grid-cols-1 gap-6'"
		>
			<ActivityCreateForm
				v-if="!isReviewStep"
				v-model:step="currentStep"
				:form="form"
				:activity-types="activityTypes"
				:organizer-characters="organizerCharacters"
				:activity-options="activityOptions"
			/>
			<ActivityCreateSummaryCard
				v-if="isReviewStep"
				class="w-full max-w-3xl"
				:form="form"
				:activity-types="activityTypes"
				:organizer-characters="organizerCharacters"
			/>

			<div
				v-if="isReviewStep"
				class="flex w-full max-w-3xl flex-col-reverse gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between"
			>
				<UButton
					type="button"
					color="neutral"
					variant="soft"
					size="lg"
					icon="i-lucide-arrow-left"
					:label="t('groups.activities.create.navigation.back')"
					@click="currentStep = 2"
				/>

				<UButton
					type="button"
					color="primary"
					size="lg"
					icon="i-lucide-plus"
					:label="t('groups.activities.create.submit')"
					:loading="form.processing"
					:disabled="form.processing"
					@click="submit"
				/>
			</div>
		</div>
	</div>
</template>
