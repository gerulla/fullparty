<script setup lang="ts">
import ActivityProgressMilestonesEditor from "@/components/Admin/ActivityTypes/ActivityProgressMilestonesEditor.vue";
import ActivityProgPointsEditor from "@/components/Admin/ActivityTypes/ActivityProgPointsEditor.vue";
import ActivityLayoutGroupsEditor from "@/components/Admin/ActivityTypes/ActivityLayoutGroupsEditor.vue";
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
