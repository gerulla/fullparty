<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/PageHeader.vue'
import RichTextEditor from '@/components/Shared/RichText/RichTextEditor.vue'
import RichTextReader from '@/components/Shared/RichText/RichTextReader.vue'
import FormQuestionBuilder from '@/components/Forms/FormQuestionBuilder.vue'
import FormQuestions from '@/components/Forms/FormQuestions.vue'
import { useSurveyFormEditor } from '@/composables/useSurveyFormEditor'
import { localizedValue } from '@/utils/localizedValue'
import type { FormDefinition, SurveyFormRecord, FormLocale } from '@/Types/Forms'
const props = defineProps<{ formRecord: SurveyFormRecord | null; emptyDefinition: FormDefinition }>()
const { t } = useI18n()
const page = usePage()
const languages: FormLocale[] = ['en', 'de', 'fr', 'ja']
const { form, language, preview, previewAnswers, busy, errors, save, transition } = useSurveyFormEditor(props)
</script>
<template>
    <Head :title="t(formRecord ? 'forms.edit' : 'forms.create')" />
    <div class="mx-auto max-w-5xl space-y-5">
        <Link :href="route('admin.forms.index')" class="inline-flex items-center gap-1 text-sm text-muted"><UIcon name="i-lucide-arrow-left" />{{ t('forms.title') }}</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <PageHeader :title="t(formRecord ? 'forms.edit' : 'forms.create')" :subtitle="t('forms.admin_description')" />
            <div class="flex gap-2"><UButton v-if="formRecord?.published_version_id" :to="route('admin.forms.responses', { surveyForm: formRecord.slug })" color="neutral" variant="outline" icon="i-lucide-chart-column" :label="t('forms.responses')" /><UButton v-if="formRecord?.is_published" :to="route('forms.show', { surveyForm: formRecord.slug })" target="_blank" color="neutral" variant="outline" icon="i-lucide-external-link" :label="t('forms.view_form')" /></div>
        </div>
        <UAlert v-if="String(page.props.flash?.success ?? '').startsWith('survey_')" color="success" variant="soft" :title="t('forms.saved')" />
        <UAlert v-for="error in errors" :key="error" color="error" variant="soft" :title="error" />
        <form class="space-y-5" @submit.prevent="save">
            <section class="space-y-4 border border-default p-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <UFormField :label="t('forms.slug')" :help="t(formRecord?.published_version_id ? 'forms.slug_locked_help' : 'forms.slug_help')" required><UInput v-model="form.slug" :disabled="Boolean(formRecord?.published_version_id)" class="w-full" :maxlength="80" /></UFormField>
                    <UFormField :label="t('forms.access')"><USelect v-model="form.definition.access" :items="[{ value: 'account', label: t('forms.account_access') }, { value: 'anyone', label: t('forms.anyone_access') }]" class="w-full" /></UFormField>
                    <UFormField :label="t('forms.response_policy')" class="md:col-span-2"><USelect v-model="form.definition.response_policy" :items="['single_editable', 'single', 'multiple'].map(value => ({ value, label: t(`forms.policies.${value}`) }))" class="w-full" /></UFormField>
                </div>
                <p v-if="form.definition.access === 'anyone'" class="text-xs text-muted">{{ t('forms.guest_limit_help') }}</p>
            </section>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex gap-1"><UButton v-for="key in languages" :key="key" :label="key.toUpperCase()" :color="key === language ? 'primary' : 'neutral'" :variant="key === language ? 'solid' : 'outline'" @click="language = key" /></div>
                <USwitch v-model="preview" :label="t('forms.preview')" />
            </div>
            <p class="text-sm text-muted">{{ t('forms.translation_help') }}</p>
            <template v-if="!preview">
                <section class="space-y-4 border border-default p-4">
                    <UFormField :label="t('forms.form_title')" :required="language === 'en'"><UInput v-model="form.definition.title[language]" class="w-full" :maxlength="160" /></UFormField>
                    <UFormField :label="t('forms.intro')"><RichTextEditor :key="language" v-model="form.definition.intro[language]!" :max-length="10000" class="min-h-48 border border-default" /></UFormField>
                    <UFormField :label="t('forms.thank_you_label')"><UTextarea v-model="form.definition.thank_you[language]" class="w-full" :maxlength="1000" :placeholder="t('forms.thank_you')" /></UFormField>
                </section>
                <FormQuestionBuilder v-model="form.definition.questions" :language="language" />
            </template>
            <section v-else class="space-y-5">
                <UAlert color="info" variant="soft" :title="t('forms.preview_notice')" />
                <h2 class="text-2xl font-semibold">{{ localizedValue(form.definition.title, language) }}</h2>
                <RichTextReader :document="form.definition.intro[language]!" />
                <FormQuestions v-model="previewAnswers" :questions="form.definition.questions" :language="language" />
            </section>
            <p class="text-sm text-muted">{{ t('forms.publish_help') }}</p>
            <div class="flex flex-wrap justify-end gap-2">
                <UButton type="submit" icon="i-lucide-save" :label="t('forms.save')" :loading="form.processing" :disabled="busy" />
                <template v-if="formRecord">
                    <UButton v-if="!formRecord.is_published || formRecord.has_changes" color="success" :label="t('forms.actions.publish')" :disabled="form.isDirty || form.processing || busy" @click="transition('publish')" />
                    <UButton v-if="formRecord.is_published" color="warning" variant="outline" :label="t(formRecord.is_open ? 'forms.actions.close' : 'forms.actions.open')" :disabled="form.isDirty || form.processing || busy" @click="transition(formRecord.is_open ? 'close' : 'open')" />
                    <UButton v-if="formRecord.is_published" color="neutral" variant="outline" :label="t('forms.actions.unpublish')" :disabled="form.isDirty || form.processing || busy" @click="transition('unpublish')" />
                </template>
            </div>
        </form>
    </div>
</template>
