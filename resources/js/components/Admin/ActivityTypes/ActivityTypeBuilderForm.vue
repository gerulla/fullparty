<script setup lang="ts">
import type {
	ActivityTypeCompositionPreset,
	ActivityTypeLayoutPreset,
	ActivityTypeRosterSummarySourceOption,
} from "@/Types/AdminActivityTypes";
import ActivityProgressMilestonesEditor from "@/components/Admin/ActivityTypes/ActivityProgressMilestonesEditor.vue";
import ActivityProgPointsEditor from "@/components/Admin/ActivityTypes/ActivityProgPointsEditor.vue";
import ActivityLayoutGroupsEditor from "@/components/Admin/ActivityTypes/ActivityLayoutGroupsEditor.vue";
import ActivityRosterSummaryPresetsEditor from "@/components/Admin/ActivityTypes/ActivityRosterSummaryPresetsEditor.vue";
import ActivitySchemaFieldsEditor from "@/components/Admin/ActivityTypes/ActivitySchemaFieldsEditor.vue";
import ActivityTypeSectionCard from "@/components/Admin/ActivityTypes/ActivityTypeSectionCard.vue";
import ActivityTypeSummaryCard from "@/components/Admin/ActivityTypes/ActivityTypeSummaryCard.vue";
import LocalizedTextFields from "@/components/Admin/ActivityTypes/LocalizedTextFields.vue";
import { slugify } from "@/utils/slugify";
import { router, usePage } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	form: any
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
	submitLabel: string
	backHref: string
}>();

const emit = defineEmits<{
	submit: []
}>();

const { t } = useI18n();
const page = usePage();
const localeConfig = computed(() => page.props.locale as {
	available?: string[]
	fallback?: string
});
const locales = computed(() => {
	const fallback = localeConfig.value?.fallback;
	const available = localeConfig.value?.available ?? [];

	if (available.length > 0) {
		const withoutFallback = available.filter((locale) => locale !== fallback);

		return fallback ? [fallback, ...withoutFallback] : available;
	}

	return fallback ? [fallback] : ['en'];
});

const topErrors = computed(() => Object.entries(props.form.errors ?? {}).slice(0, 8));
const primaryLocale = computed(() => localeConfig.value?.fallback ?? locales.value[0] ?? 'en');
const availableTags = ref([...props.existingTags]);
const tagSearchTerm = ref('');
const smallImagePreviewUrl = ref<string | null>(props.form.draft_small_image_url ?? null);
const bannerImagePreviewUrl = ref<string | null>(props.form.draft_banner_image_url ?? null);
const difficultyItems = computed(() => props.schemaReference.activityDifficulties.map((value) => ({
	label: t(`admin.activity_types.difficulties.${value}`),
	value,
})));

watch(() => props.existingTags, (tags) => {
	availableTags.value = [...tags];
}, { immediate: true });

const updateDraftName = (value: Record<string, string>) => {
	const previousPrimaryName = props.form.draft_name?.[primaryLocale.value] ?? '';
	const nextPrimaryName = value?.[primaryLocale.value] ?? '';
	const previousGeneratedSlug = slugify(previousPrimaryName);
	const nextGeneratedSlug = slugify(nextPrimaryName);

	props.form.draft_name = value;

	if (!props.form.slug || props.form.slug === previousGeneratedSlug) {
		props.form.slug = nextGeneratedSlug;
	}
};

const goBack = () => {
	router.get(props.backHref);
};

const updateImage = (event: Event, field: 'draft_small_image' | 'draft_banner_image') => {
	const target = event.target as HTMLInputElement;
	const file = target.files?.[0] ?? null;

	props.form[field] = file;

	if (field === 'draft_small_image') {
		smallImagePreviewUrl.value = file ? URL.createObjectURL(file) : (props.form.draft_small_image_url ?? null);

		return;
	}

	bannerImagePreviewUrl.value = file ? URL.createObjectURL(file) : (props.form.draft_banner_image_url ?? null);
};

const addCreatedTag = (rawTag: string) => {
	const tag = rawTag.trim();

	if (!tag) {
		tagSearchTerm.value = '';

		return;
	}

	if (!Array.isArray(props.form.tags)) {
		props.form.tags = [];
	}

	if (!props.form.tags.includes(tag)) {
		props.form.tags = [...props.form.tags, tag];
	}

	if (!availableTags.value.includes(tag)) {
		availableTags.value = [...availableTags.value, tag].sort((left, right) => left.localeCompare(right));
	}

	tagSearchTerm.value = '';
};
</script>

<template>
	<form class="flex flex-col gap-6" @submit.prevent="emit('submit')">
		<div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
			<div class="flex flex-col gap-6">
				<UAlert
					v-if="topErrors.length > 0"
					color="error"
					variant="soft"
					icon="i-lucide-circle-alert"
					:title="t('admin.activity_types.form.error_title')"
				>
					<template #description>
						<ul class="list-disc pl-4">
							<li v-for="[field, message] in topErrors" :key="field">{{ message }}</li>
						</ul>
					</template>
				</UAlert>

				<ActivityTypeSectionCard
					:title="t('admin.activity_types.general.title')"
					:description="t('admin.activity_types.general.subtitle')"
				>
					<div class="flex flex-col gap-5">
						<UFormField :label="t('admin.activity_types.general.slug')" :description="t('admin.activity_types.general.slug_help')" required>
							<UInput
								v-model="form.slug"
								class="w-full"
								:placeholder="t('admin.activity_types.general.slug_placeholder')"
							/>
						</UFormField>

						<LocalizedTextFields
							:model-value="form.draft_name"
							:locales="locales"
							:label="t('admin.activity_types.general.name')"
							:description="t('admin.activity_types.general.name_help')"
							:placeholder-prefix="t('admin.activity_types.general.name_placeholder')"
							@update:model-value="updateDraftName"
						/>

						<LocalizedTextFields
							v-model="form.draft_description"
							:locales="locales"
							:label="t('admin.activity_types.general.description')"
							:description="t('admin.activity_types.general.description_help')"
							:placeholder-prefix="t('admin.activity_types.general.description_placeholder')"
							multiline
						/>

						<div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,220px)_minmax(0,1fr)]">
							<UFormField
								:label="t('admin.activity_types.general.small_image.label')"
								:help="t('admin.activity_types.general.small_image.help')"
								:error="form.errors.draft_small_image"
							>
								<div class="flex flex-col gap-3">
									<label class="file-upload-field">
										<UIcon name="i-lucide-upload" size="16" />
										<span class="text-sm font-medium">
											{{ form.draft_small_image?.name || t('admin.activity_types.general.small_image.placeholder') }}
										</span>
										<input
											class="sr-only"
											type="file"
											accept="image/*"
											@change="(event) => updateImage(event, 'draft_small_image')"
										>
									</label>

									<div v-if="smallImagePreviewUrl" class="rounded-sm border border-muted p-3">
										<p class="mb-2 text-xs uppercase tracking-wide text-muted">
											{{ t('admin.activity_types.general.small_image.preview_label') }}
										</p>
										<div class="aspect-[10/17] w-full max-w-40 overflow-hidden rounded-sm border border-default bg-muted/30">
											<img
												:src="smallImagePreviewUrl"
												:alt="t('admin.activity_types.general.small_image.preview_alt')"
												class="h-full w-full object-cover object-center"
											>
										</div>
									</div>
								</div>
							</UFormField>

							<UFormField
								:label="t('admin.activity_types.general.banner_image.label')"
								:help="t('admin.activity_types.general.banner_image.help')"
								:error="form.errors.draft_banner_image"
							>
								<div class="flex flex-col gap-3">
									<label class="file-upload-field">
										<UIcon name="i-lucide-upload" size="16" />
										<span class="text-sm font-medium">
											{{ form.draft_banner_image?.name || t('admin.activity_types.general.banner_image.placeholder') }}
										</span>
										<input
											class="sr-only"
											type="file"
											accept="image/*"
											@change="(event) => updateImage(event, 'draft_banner_image')"
										>
									</label>

									<div v-if="bannerImagePreviewUrl" class="rounded-sm border border-muted p-3">
										<p class="mb-2 text-xs uppercase tracking-wide text-muted">
											{{ t('admin.activity_types.general.banner_image.preview_label') }}
										</p>
										<div class="aspect-[3/1] w-full overflow-hidden rounded-sm border border-default bg-muted/30">
											<img
												:src="bannerImagePreviewUrl"
												:alt="t('admin.activity_types.general.banner_image.preview_alt')"
												class="h-full w-full object-cover object-center"
											>
										</div>
									</div>
								</div>
							</UFormField>
						</div>

						<div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
							<UFormField
								:label="t('admin.activity_types.general.difficulty')"
								:description="t('admin.activity_types.general.difficulty_help')"
							>
								<USelect
									v-model="form.draft_difficulty"
									class="w-full"
									:items="difficultyItems"
									value-key="value"
								/>
							</UFormField>

							<UFormField
								:label="t('admin.activity_types.general.default_min_item_level')"
								:description="t('admin.activity_types.general.default_min_item_level_help')"
							>
								<UInput
									v-model.number="form.draft_default_min_item_level"
									class="w-full"
									type="number"
									min="1"
									max="9999"
									:placeholder="t('admin.activity_types.general.default_min_item_level_placeholder')"
								/>
							</UFormField>
						</div>

						<UFormField
							:label="t('admin.activity_types.general.tags')"
							:description="t('admin.activity_types.general.tags_help')"
						>
							<UInputMenu
								v-model="form.tags"
								v-model:search-term="tagSearchTerm"
								class="w-full"
								:items="availableTags"
								multiple
								create-item="always"
								:placeholder="t('admin.activity_types.general.tags_placeholder')"
								@create="addCreatedTag"
							/>
						</UFormField>

						<UFormField
							:label="t('admin.activity_types.general.fflogs_zone_id')"
							:description="t('admin.activity_types.general.fflogs_zone_id_help')"
						>
							<UInput
								v-model.number="form.draft_fflogs_zone_id"
								class="w-full"
								type="number"
								min="1"
								:placeholder="t('admin.activity_types.general.fflogs_zone_id_placeholder')"
							/>
						</UFormField>

						<UFormField
							:label="t('admin.activity_types.general.bench_size')"
							:description="t('admin.activity_types.general.bench_size_help')"
						>
							<UInput
								v-model.number="form.draft_bench_size"
								class="w-full"
								type="number"
								min="0"
								max="24"
								placeholder="0"
							/>
						</UFormField>

						<UFormField
							:label="t('admin.activity_types.general.active')"
							:description="t('admin.activity_types.general.active_help')"
							orientation="horizontal"
							class="max-w-sm"
						>
							<USwitch v-model="form.is_active" />
						</UFormField>
					</div>
				</ActivityTypeSectionCard>

				<ActivityLayoutGroupsEditor
					v-model="form.draft_layout_schema.groups"
					:locales="locales"
					:layout-presets="schemaReference.layoutPresets"
					:composition-presets="schemaReference.compositionPresets"
				/>

				<ActivitySchemaFieldsEditor
					v-model="form.draft_slot_schema"
					:locales="locales"
					:title="t('admin.activity_types.slot_fields.title')"
					:description="t('admin.activity_types.slot_fields.subtitle')"
					field-kind="slot"
					:supported-field-types="schemaReference.supportedFieldTypes"
					:supported-option-sources="schemaReference.supportedOptionSources"
				/>

				<ActivitySchemaFieldsEditor
					v-model="form.draft_application_schema"
					:locales="locales"
					:title="t('admin.activity_types.application.title')"
					:description="t('admin.activity_types.application.subtitle')"
					field-kind="application"
					:supported-field-types="schemaReference.supportedFieldTypes"
					:supported-option-sources="schemaReference.supportedOptionSources"
				/>

				<ActivityRosterSummaryPresetsEditor
					v-model="form.draft_roster_summary_presets"
					:locales="locales"
					:layout-groups="form.draft_layout_schema?.groups ?? []"
					:summary-reference="{
						supportedSources: schemaReference.rosterSummarySources,
						supportedComparisons: schemaReference.rosterSummaryComparisonModes,
						supportedScopeTypes: schemaReference.rosterSummaryScopeTypes,
						sourceOptions: schemaReference.rosterSummarySourceOptions,
					}"
				/>

				<ActivityProgressMilestonesEditor
					v-model="form.draft_progress_schema"
					:locales="locales"
				/>

				<ActivityProgPointsEditor
					v-model="form.draft_prog_points"
					:locales="locales"
				/>
			</div>

			<div class="flex flex-col gap-4">
				<ActivityTypeSummaryCard :form="form" />

				<UCard class="dark:bg-elevated/25">
					<div class="flex flex-col gap-3">
						<UButton
							type="submit"
							color="neutral"
							icon="i-lucide-save"
							:label="submitLabel"
							:loading="form.processing"
						/>

						<UButton
							color="neutral"
							variant="outline"
							:label="t('general.cancel')"
							@click="goBack"
						/>
					</div>
				</UCard>
			</div>
		</div>
	</form>
</template>
