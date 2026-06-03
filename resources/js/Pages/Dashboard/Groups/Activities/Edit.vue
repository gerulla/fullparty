<script setup lang="ts">
import { computed, ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import PageHeader from "@/components/PageHeader.vue";
import { useI18n } from "vue-i18n";
import ActivityEditForm from "@/components/Groups/Activities/ActivityEditForm.vue";
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
	activity: {
		id: number
		activity_type_id: number | null
		organized_by_user_id: number | null
		organized_by_character_id: number | null
		status: string
		title: string | null
		notes: string | null
		starts_at: string | null
		duration_hours: number | null
		datacenter: string | null
		intensity: string
		min_item_level: number | null
		beginner_friendly: boolean
		run_style: string
		target_prog_point_key: string | null
		is_public: boolean
		needs_application: boolean
		allow_guest_applications: boolean
	}
	activityTypes: ActivityTypeOption[]
	organizerCharacters: OrganizerCharacterOption[]
	activityOptions: ActivityMetadataOptions
}>();

const { t } = useI18n();
const currentStep = ref(0);

const form = useForm({
	activity_type_id: props.activity.activity_type_id,
	organized_by_user_id: props.activity.organized_by_user_id,
	organized_by_character_id: props.activity.organized_by_character_id,
	status: props.activity.status,
	title: props.activity.title ?? '',
	notes: props.activity.notes ?? '',
	starts_at: props.activity.starts_at,
	duration_hours: props.activity.duration_hours ?? 2,
	datacenter: props.activity.datacenter ?? props.group.datacenter,
	intensity: props.activity.intensity,
	min_item_level: props.activity.min_item_level,
	beginner_friendly: props.activity.beginner_friendly,
	run_style: props.activity.run_style,
	target_prog_point_key: props.activity.target_prog_point_key,
	is_public: props.activity.is_public,
	needs_application: props.activity.needs_application,
	allow_guest_applications: props.activity.allow_guest_applications,
});

const goBack = () => {
	router.get(route('groups.dashboard.activities.show', {
		group: props.group.slug,
		activity: props.activity.id,
	}));
};

const isReviewStep = computed(() => currentStep.value === 3);

const editSteps = computed(() => [
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
	allow_guest_applications: 0,
	organized_by_user_id: 1,
	organized_by_character_id: 1,
	title: 1,
	target_prog_point_key: 1,
	min_item_level: 2,
	beginner_friendly: 2,
	run_style: 2,
	intensity: 2,
	notes: 2,
};

const submit = () => {
	form.transform((data) => ({
		organized_by_user_id: data.organized_by_user_id,
		organized_by_character_id: data.organized_by_character_id,
		title: data.title,
		notes: data.notes,
		starts_at: data.starts_at,
		duration_hours: data.duration_hours,
		datacenter: data.datacenter,
		intensity: data.intensity,
		min_item_level: data.min_item_level,
		beginner_friendly: data.beginner_friendly,
		run_style: data.run_style,
		target_prog_point_key: data.target_prog_point_key,
		is_public: data.is_public,
		allow_guest_applications: data.allow_guest_applications,
	})).put(route('groups.dashboard.activities.update', {
		group: props.group.slug,
		activity: props.activity.id,
	}), {
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
			:label="t('groups.activities.edit.back')"
			icon="i-lucide-arrow-left"
			variant="ghost"
			color="neutral"
			@click.stop="goBack"
		/>
		<PageHeader
			:title="t('groups.activities.edit.title')"
			:subtitle="t('groups.activities.edit.subtitle')"
		>
			<div class="w-full xl:w-[62vw] xl:max-w-[64rem]">
				<ActivityCreateStepTimeline
					v-model="currentStep"
					:steps="editSteps"
				/>
			</div>
		</PageHeader>

		<div
			class="mt-4"
			:class="isReviewStep
				? 'flex flex-col items-center gap-4'
				: 'mx-auto grid w-full max-w-5xl grid-cols-1 gap-6'"
		>
			<ActivityEditForm
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
					icon="i-lucide-save"
					:label="t('groups.activities.edit.submit')"
					:loading="form.processing"
					:disabled="form.processing"
					@click="submit"
				/>
			</div>
		</div>
	</div>
</template>
