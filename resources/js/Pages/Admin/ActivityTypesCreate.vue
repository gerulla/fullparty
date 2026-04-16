<script setup lang="ts">
import ActivityTypeBuilderForm from "@/components/Admin/ActivityTypes/ActivityTypeBuilderForm.vue";
import PageHeader from "@/components/PageHeader.vue";
import { router, useForm } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";

defineProps<{
	schemaReference: {
		supportedFieldTypes: string[]
		supportedOptionSources: string[]
	}
}>();

const { t } = useI18n();

const createLocalizedRecord = () => ({ en: '' });

const form = useForm({
	slug: '',
	draft_name: createLocalizedRecord(),
	draft_description: createLocalizedRecord(),
	draft_layout_schema: {
		groups: [
			{
				key: 'party-1',
				label: {
					en: 'Party 1',
					de: '',
					fr: '',
					ja: '',
				},
				size: 8,
			},
		],
	},
	draft_slot_schema: [],
	draft_application_schema: [],
	draft_progress_schema: {
		milestones: [],
	},
	is_active: true,
});

const goBack = () => {
	router.get('/admin/activity-types');
};

const submit = () => {
	form.post('/admin/activity-types');
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
			:title="t('admin.activity_types.create_title')"
			:subtitle="t('admin.activity_types.create_subtitle')"
		/>

		<div class="mt-6">
			<ActivityTypeBuilderForm
				:form="form"
				:schema-reference="schemaReference"
				:submit-label="t('general.create')"
				back-href="/admin/activity-types"
				@submit="submit"
			/>
		</div>
	</div>
</template>
