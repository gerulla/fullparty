<script setup lang="ts">
import ActivityLayoutGroupsEditor from "@/components/Admin/ActivityTypes/ActivityLayoutGroupsEditor.vue";
import ActivitySchemaFieldsEditor from "@/components/Admin/ActivityTypes/ActivitySchemaFieldsEditor.vue";
import ActivityTypeSummaryCard from "@/components/Admin/ActivityTypes/ActivityTypeSummaryCard.vue";
import LocalizedTextFields from "@/components/Admin/ActivityTypes/LocalizedTextFields.vue";
import { slugify } from "@/utils/slugify";
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	form: any
	schemaReference: {
		supportedFieldTypes: string[]
		supportedOptionSources: string[]
	}
	submitLabel: string
	backHref: string
}>();

const emit = defineEmits<{
	submit: []
}>();

const { t } = useI18n();

const locales = ['en', 'de', 'fr', 'ja'];

const topErrors = computed(() => Object.entries(props.form.errors ?? {}).slice(0, 8));

const updateDraftName = (value: Record<string, string>) => {
	const previousEnglishName = props.form.draft_name?.en ?? '';
	const nextEnglishName = value?.en ?? '';
	const previousGeneratedSlug = slugify(previousEnglishName);
	const nextGeneratedSlug = slugify(nextEnglishName);

	props.form.draft_name = value;

	if (!props.form.slug || props.form.slug === previousGeneratedSlug) {
		props.form.slug = nextGeneratedSlug;
	}
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

				<UCard class="dark:bg-elevated/25">
					<template #header>
						<div>
							<h2 class="text-lg font-semibold">{{ t('admin.activity_types.general.title') }}</h2>
							<p class="text-sm text-muted">{{ t('admin.activity_types.general.subtitle') }}</p>
						</div>
					</template>

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
							:label="t('admin.activity_types.general.active')"
							:description="t('admin.activity_types.general.active_help')"
							orientation="horizontal"
							class="max-w-sm"
						>
							<USwitch v-model="form.is_active" />
						</UFormField>
					</div>
				</UCard>

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
						/>
					</div>
				</UCard>
			</div>
		</div>
	</form>
</template>
