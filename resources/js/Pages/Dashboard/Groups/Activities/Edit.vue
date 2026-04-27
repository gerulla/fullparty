<script setup lang="ts">
import { router, useForm } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import PageHeader from "@/components/PageHeader.vue";
import { useI18n } from "vue-i18n";
import ActivityEditForm from "@/components/Groups/Activities/ActivityEditForm.vue";
import ActivityCreateSummaryCard from "@/components/Groups/Activities/ActivityCreateSummaryCard.vue";

const props = defineProps<{
	group: {
		id: number
		name: string
		slug: string
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
		target_prog_point_key: string | null
		is_public: boolean
		needs_application: boolean
	}
	activityTypes: Array<{
		id: number
		slug: string
		draft_name: Record<string, string | null | undefined> | null | undefined
		current_published_version_id: number | null
		slot_count: number
		prog_points: Array<{
			key: string
			label: Record<string, string | null | undefined> | null | undefined
		}>
	}>
	organizerCharacters: Array<{
		id: number
		user_id: number
		name: string | null
		user_name: string | null
		avatar_url: string | null
		world: string | null
	}>
}>();

const { t } = useI18n();

const form = useForm({
	activity_type_id: props.activity.activity_type_id,
	organized_by_user_id: props.activity.organized_by_user_id,
	organized_by_character_id: props.activity.organized_by_character_id,
	status: props.activity.status,
	title: props.activity.title ?? '',
	notes: props.activity.notes ?? '',
	starts_at: props.activity.starts_at,
	duration_hours: props.activity.duration_hours ?? 2,
	target_prog_point_key: props.activity.target_prog_point_key,
	is_public: props.activity.is_public,
	needs_application: props.activity.needs_application,
});

const goBack = () => {
	router.get(route('groups.dashboard.activities.show', {
		group: props.group.slug,
		activity: props.activity.id,
	}));
};

const submit = () => {
	form
		.transform((data) => ({
			organized_by_user_id: data.organized_by_user_id,
			organized_by_character_id: data.organized_by_character_id,
			title: data.title,
			notes: data.notes,
			starts_at: data.starts_at,
			duration_hours: data.duration_hours,
			target_prog_point_key: data.target_prog_point_key,
		}))
		.put(route('groups.dashboard.activities.update', {
		group: props.group.slug,
		activity: props.activity.id,
	}), {
		preserveScroll: true,
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
		/>

		<div class="mt-4 grid grid-cols-1 gap-6 xl:grid-cols-[1.15fr_0.85fr]">
			<ActivityEditForm
				:form="form"
				:activity-types="activityTypes"
				:organizer-characters="organizerCharacters"
				:submit-label="t('groups.activities.edit.submit')"
				@submit="submit"
			/>
			<ActivityCreateSummaryCard
				:form="form"
				:activity-types="activityTypes"
				:organizer-characters="organizerCharacters"
			/>
		</div>
	</div>
</template>
