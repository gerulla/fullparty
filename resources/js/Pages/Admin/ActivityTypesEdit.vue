<script setup lang="ts">
import ActivityTypeBuilderForm from "@/components/Admin/ActivityTypes/ActivityTypeBuilderForm.vue";
import PageHeader from "@/components/PageHeader.vue";
import { router, useForm } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	activityType: any
	schemaReference: {
		supportedFieldTypes: string[]
		supportedOptionSources: string[]
	}
	existingTags: string[]
}>();

const { t } = useI18n();

const form = useForm({
	slug: props.activityType.slug,
	draft_name: props.activityType.draft_name,
	draft_description: props.activityType.draft_description ?? { en: '' },
	tags: props.activityType.tags ?? [],
	draft_layout_schema: props.activityType.draft_layout_schema,
	draft_slot_schema: props.activityType.draft_slot_schema,
	draft_application_schema: props.activityType.draft_application_schema,
	draft_progress_schema: props.activityType.draft_progress_schema ?? { milestones: [] },
	draft_prog_points: props.activityType.draft_prog_points ?? [],
	is_active: props.activityType.is_active,
});

const goBack = () => {
	router.get('/admin/activity-types');
};

const submit = () => {
	form.put(`/admin/activity-types/${props.activityType.id}`);
};
</script>

<template>
	<div class="w-full">
		<UButton
			:label="t('admin.activity_types.back')"
			icon="i-lucide-arrow-left"
			variant="ghost"
			color="neutral"
			@click.stop="goBack"
		/>
		<PageHeader
			:title="t('admin.activity_types.edit_title')"
			:subtitle="t('admin.activity_types.edit_subtitle')"
		/>

		<div class="mt-6">
			<ActivityTypeBuilderForm
				:form="form"
				:schema-reference="schemaReference"
				:existing-tags="existingTags"
				:submit-label="t('general.update')"
				back-href="/admin/activity-types"
				@submit="submit"
			/>
		</div>
	</div>
</template>
