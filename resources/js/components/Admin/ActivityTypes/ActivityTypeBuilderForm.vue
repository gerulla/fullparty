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
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";
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

const editor = ref<HTMLFormElement | null>(null);
const activeTab = ref('general');
const sections = [
	{ value: 'general', label: 'general.title' },
	{ value: 'draft_layout_schema', label: 'tabs.roster' },
	{ value: 'draft_slot_schema', label: 'slot_fields.title' },
	{ value: 'draft_roster_summary_presets', label: 'tabs.presets' },
	{ value: 'draft_application_schema', label: 'tabs.applications' },
	{ value: 'draft_progress_schema', label: 'tabs.milestones' },
	{ value: 'draft_prog_points', label: 'prog_points.title' },
];
const sectionForField = (field: string) => sections.find(section => section.value === field.split('.')[0])?.value ?? 'general';
const errors = computed(() => Object.entries(props.form.errors ?? {}));
const topErrors = computed(() => errors.value.slice(0, 8));
const tabs = computed(() => sections.map(section => {
	const count = errors.value.filter(([field]) => sectionForField(field) === section.value).length;
	return {
		value: section.value,
		label: t(`admin.activity_types.${section.label}`),
		badge: count ? { label: String(count), color: 'error' as const, variant: 'soft' as const } : undefined,
	};
}));

async function openErrorSection(field: string) {
	activeTab.value = sectionForField(field);
	await nextTick();
	editor.value?.querySelector<HTMLElement>('[role="tab"][aria-selected="true"]')?.focus();
}

watch(errors, list => {
	if (list.length) void openErrorSection(list[0][0]);
}, { immediate: true });

type NativeControl = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;
const invalidSelector = 'input:invalid, select:invalid, textarea:invalid';
let pendingFocus: MutationObserver | undefined;
onBeforeUnmount(() => pendingFocus?.disconnect());

function submitDraft() {
	pendingFocus?.disconnect();
	const form = editor.value;
	const invalid = form?.querySelector<NativeControl>(invalidSelector);
	if (!form || !invalid) {
		emit('submit');
		return;
	}

	const tab = invalid.closest<HTMLElement>('[data-editor-tab]')?.dataset.editorTab ?? 'general';
	activeTab.value = tab;
	invalid.checkValidity(); // The section captures this event to expand its fields.

	function focusWhenVisible() {
		if (activeTab.value !== tab) {
			pendingFocus?.disconnect();
			return;
		}
		const panel = form?.querySelector<HTMLElement>(`[data-editor-tab="${tab}"]`);
		const field = panel?.querySelector<NativeControl>(invalidSelector);
		if (!field || field.closest('[hidden]') || !field.getClientRects().length) return;
		pendingFocus?.disconnect();
		field.focus();
		field.reportValidity();
	}

	// Reka updates the panel's hidden state after the selected tab changes.
	// Resolve the live control once the tab and its disclosure are both visible.
	pendingFocus = new MutationObserver(focusWhenVisible);
	pendingFocus.observe(form, { subtree: true, childList: true, attributes: true, attributeFilter: ['hidden', 'style', 'data-state'] });
	void nextTick(focusWhenVisible);
}
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
	<form ref="editor" class="min-w-0 space-y-4" novalidate @submit.prevent="submitDraft">
		<UAlert
			v-if="topErrors.length > 0"
			color="error"
			variant="soft"
			icon="i-lucide-circle-alert"
			:title="t('admin.activity_types.form.error_title')"
		>
			<template #description>
				<ul class="list-disc pl-4">
					<li v-for="[field, message] in topErrors" :key="field">
						<button type="button" class="text-left underline decoration-current/40 underline-offset-2" @click="openErrorSection(field)">{{ message }}</button>
					</li>
				</ul>
			</template>
		</UAlert>

		<div class="grid min-w-0 items-start gap-4 xl:grid-cols-[minmax(0,1fr)_18rem] 2xl:grid-cols-[minmax(0,1fr)_20rem]">
			<UTabs
				v-model="activeTab"
				:items="tabs"
				:unmount-on-hide="false"
				class="contents"
				:ui="{
					list: 'col-span-full w-full min-w-0 justify-start gap-1 overflow-x-auto rounded-xl bg-elevation-1 p-1 shadow-sm dark:bg-elevation-3',
					trigger: 'flex-none rounded-lg px-3',
					content: 'min-w-0',
				}"
			>
				<template #content="{ item }">
					<div :data-editor-tab="item.value">
						<ActivityTypeSectionCard
							v-if="item.value === 'general'"
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
									rounded
									:model-value="form.draft_name"
									:locales="locales"
									:label="t('admin.activity_types.general.name')"
									:description="t('admin.activity_types.general.name_help')"
									:placeholder-prefix="t('admin.activity_types.general.name_placeholder')"
									@update:model-value="updateDraftName"
								/>

								<LocalizedTextFields
									rounded
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
											<label class="flex min-h-10 min-w-0 cursor-pointer items-center gap-2 rounded-lg border border-dashed border-default px-3 py-2 text-default transition-colors hover:bg-elevation-2 focus-within:ring-2 focus-within:ring-primary dark:hover:bg-elevation-4">
												<UIcon name="i-lucide-upload" size="16" />
												<span class="min-w-0 truncate text-sm font-medium">
													{{ form.draft_small_image?.name || t('admin.activity_types.general.small_image.placeholder') }}
												</span>
												<input
													class="sr-only"
													type="file"
													accept="image/*"
													@change="(event) => updateImage(event, 'draft_small_image')"
												>
											</label>

											<div v-if="smallImagePreviewUrl" class="admin-inset min-w-0 p-3">
												<p class="mb-2 text-xs uppercase tracking-wide text-muted">
													{{ t('admin.activity_types.general.small_image.preview_label') }}
												</p>
												<div class="aspect-[10/17] w-full max-w-40 overflow-hidden rounded-lg bg-elevation-2 dark:bg-elevation-3">
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
											<label class="flex min-h-10 min-w-0 cursor-pointer items-center gap-2 rounded-lg border border-dashed border-default px-3 py-2 text-default transition-colors hover:bg-elevation-2 focus-within:ring-2 focus-within:ring-primary dark:hover:bg-elevation-4">
												<UIcon name="i-lucide-upload" size="16" />
												<span class="min-w-0 truncate text-sm font-medium">
													{{ form.draft_banner_image?.name || t('admin.activity_types.general.banner_image.placeholder') }}
												</span>
												<input
													class="sr-only"
													type="file"
													accept="image/*"
													@change="(event) => updateImage(event, 'draft_banner_image')"
												>
											</label>

											<div v-if="bannerImagePreviewUrl" class="admin-inset min-w-0 p-3">
												<p class="mb-2 text-xs uppercase tracking-wide text-muted">
													{{ t('admin.activity_types.general.banner_image.preview_label') }}
												</p>
												<div class="aspect-[3/1] w-full overflow-hidden rounded-lg bg-elevation-2 dark:bg-elevation-3">
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
							v-else-if="item.value === 'draft_layout_schema'"
							v-model="form.draft_layout_schema.groups"
							:locales="locales"
							:layout-presets="schemaReference.layoutPresets"
							:composition-presets="schemaReference.compositionPresets"
						/>

						<ActivitySchemaFieldsEditor
							v-else-if="item.value === 'draft_slot_schema'"
							v-model="form.draft_slot_schema"
							:locales="locales"
							:title="t('admin.activity_types.slot_fields.title')"
							:description="t('admin.activity_types.slot_fields.subtitle')"
							field-kind="slot"
							:supported-field-types="schemaReference.supportedFieldTypes"
							:supported-option-sources="schemaReference.supportedOptionSources"
						/>

						<ActivitySchemaFieldsEditor
							v-else-if="item.value === 'draft_application_schema'"
							v-model="form.draft_application_schema"
							:locales="locales"
							:title="t('admin.activity_types.application.title')"
							:description="t('admin.activity_types.application.subtitle')"
							field-kind="application"
							:supported-field-types="schemaReference.supportedFieldTypes"
							:supported-option-sources="schemaReference.supportedOptionSources"
						/>

						<ActivityRosterSummaryPresetsEditor
							v-else-if="item.value === 'draft_roster_summary_presets'"
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
							v-else-if="item.value === 'draft_progress_schema'"
							v-model="form.draft_progress_schema"
							:locales="locales"
						/>

						<ActivityProgPointsEditor
							v-else-if="item.value === 'draft_prog_points'"
							v-model="form.draft_prog_points"
							:locales="locales"
							:errors="form.errors"
						/>
					</div>
				</template>
			</UTabs>
			<aside class="min-w-0"><ActivityTypeSummaryCard :form="form" /></aside>
		</div>

		<div class="admin-panel sticky bottom-2 z-10 ml-auto flex w-fit max-w-full flex-wrap justify-end gap-2 p-2 shadow-md">
			<UButton
				type="submit"
				color="primary"
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
	</form>
</template>
