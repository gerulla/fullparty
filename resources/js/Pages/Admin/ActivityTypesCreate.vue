<script setup lang="ts">
import type {
	ActivityTypeCompositionPreset,
	ActivityTypeLayoutPreset,
	ActivityTypeRosterSummarySourceOption,
} from "@/Types/AdminActivityTypes";
import ActivityTypeBuilderForm from "@/components/Admin/ActivityTypes/ActivityTypeBuilderForm.vue";
import AdminPageHeader from "@/components/Admin/AdminPageHeader.vue";
import { router, useForm } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";

defineProps<{
	schemaReference: {
		supportedFieldTypes: string[]
		supportedOptionSources: string[]
		rosterSummarySources: string[]
		rosterSummaryComparisonModes: string[]
		rosterSummaryScopeTypes: string[]
		activityDifficulties: string[]
		layoutPresets: ActivityTypeLayoutPreset[]
		compositionPresets: ActivityTypeCompositionPreset[]
		rosterSummarySourceOptions: Record<string, ActivityTypeRosterSummarySourceOption[]>
	}
	existingTags: string[]
}>();

const { t } = useI18n();

const createLocalizedRecord = () => ({ en: '' });

const form = useForm({
	slug: '',
	draft_name: createLocalizedRecord(),
	draft_description: createLocalizedRecord(),
	draft_small_image: null as File | null,
	draft_banner_image: null as File | null,
	draft_small_image_url: null as string | null,
	draft_banner_image_url: null as string | null,
	draft_difficulty: 'normal',
	draft_default_min_item_level: null as number | null,
	tags: [],
	draft_layout_schema: {
		groups: [
			{
				key: 'party-a',
				label: {
					en: 'Party A',
					de: '',
					fr: '',
					ja: '',
				},
				size: 8,
				composition_hint_key: null,
				composition_hints: [],
			},
		],
	},
	draft_slot_schema: [],
	draft_application_schema: [],
	draft_roster_summary_presets: [],
	draft_progress_schema: {
		milestones: [],
	},
	draft_bench_size: 0,
	draft_prog_points: [],
	draft_fflogs_zone_id: null,
	is_active: true,
});

const goBack = () => {
	router.get(route('admin.activity-types.index'));
};

const submit = () => {
	form.post(route('admin.activity-types.store'), {
		forceFormData: true,
	});
};
</script>

<template>
	<div class="min-w-0">
		<UButton
			class="mb-3"
			:label="t('admin.activity_types.back')"
			icon="i-lucide-arrow-left"
			variant="ghost"
			color="neutral"
			@click.stop="goBack"
		/>
		<AdminPageHeader
			:title="t('admin.activity_types.create_title')"
			:subtitle="t('admin.activity_types.create_subtitle')"
		/>

		<div class="mt-6">
			<ActivityTypeBuilderForm
				:form="form"
				:schema-reference="schemaReference"
				:existing-tags="existingTags"
				:submit-label="t('general.create')"
				:back-href="route('admin.activity-types.index')"
				@submit="submit"
			/>
		</div>
	</div>
</template>
