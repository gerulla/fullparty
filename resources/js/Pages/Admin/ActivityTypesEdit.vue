<script setup lang="ts">
import type {
	ActivityTypeCompositionPreset,
	ActivityTypeLayoutPreset,
	ActivityTypeRosterSummarySourceOption,
} from "@/Types/AdminActivityTypes";
import ActivityTypeBuilderForm from "@/components/Admin/ActivityTypes/ActivityTypeBuilderForm.vue";
import PageHeader from "@/components/PageHeader.vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { watch } from "vue";

const props = defineProps<{
	activityType: any
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
const page = usePage();
const toast = useToast();

const form = useForm({
	slug: props.activityType.slug,
	draft_name: props.activityType.draft_name,
	draft_description: props.activityType.draft_description ?? { en: '' },
	draft_small_image: null as File | null,
	draft_banner_image: null as File | null,
	draft_small_image_url: props.activityType.draft_small_image_url,
	draft_banner_image_url: props.activityType.draft_banner_image_url,
	draft_difficulty: props.activityType.draft_difficulty ?? 'normal',
	draft_default_min_item_level: props.activityType.draft_default_min_item_level ?? null,
	tags: props.activityType.tags ?? [],
	draft_layout_schema: props.activityType.draft_layout_schema,
	draft_slot_schema: props.activityType.draft_slot_schema,
	draft_application_schema: props.activityType.draft_application_schema,
	draft_roster_summary_presets: props.activityType.draft_roster_summary_presets ?? [],
	draft_progress_schema: props.activityType.draft_progress_schema ?? { milestones: [] },
	draft_bench_size: props.activityType.draft_bench_size ?? 0,
	draft_prog_points: props.activityType.draft_prog_points ?? [],
	draft_fflogs_zone_id: props.activityType.draft_fflogs_zone_id ?? null,
	is_active: props.activityType.is_active,
});

const goBack = () => {
	router.get(route('admin.activity-types.index'));
};

const submit = () => {
	form
		.transform((data) => ({
			...data,
			_method: 'put',
		}))
		.post(route('admin.activity-types.update', props.activityType.id), {
			forceFormData: true,
		});
};

const publish = () => {
	router.post(route('admin.activity-types.publish', props.activityType.id));
};

watch(
	() => page.props.flash?.success,
	(success) => {
		if (!success?.includes('activity_type_cloned')) {
			return;
		}

		toast.add({
			title: t('general.success'),
			description: t('admin.activity_types.toasts.cloned'),
			color: 'success',
			icon: 'i-lucide-copy',
		});
	},
	{ immediate: true }
);
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
		>
			<UButton
				color="primary"
				icon="i-lucide-upload"
				:label="t('admin.activity_types.publish')"
				@click="publish"
			/>
		</PageHeader>

		<div class="mt-6">
			<ActivityTypeBuilderForm
				:form="form"
				:schema-reference="schemaReference"
				:existing-tags="existingTags"
				:submit-label="t('general.update')"
				:back-href="route('admin.activity-types.index')"
				@submit="submit"
			/>
		</div>
	</div>
</template>
