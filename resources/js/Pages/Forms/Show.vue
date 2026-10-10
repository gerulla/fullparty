<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import LandingLayout from '@/Layouts/LandingLayout.vue'
import RichTextReader from '@/components/Shared/RichText/RichTextReader.vue'
import FormPageQuestions from '@/components/Forms/FormPageQuestions.vue'
import { localizedValue } from '@/utils/localizedValue'
import { emptyRichTextDocument } from '@/utils/richText'
import type { PublicForm, ExistingFormResponse, FormLocale, FormPageNavigation } from '@/Types/Forms'

defineOptions({ layout: LandingLayout })
const props = defineProps<{ form: PublicForm; canSubmit: boolean; existing: ExistingFormResponse | null; submissionKey: string }>()
const { t, locale } = useI18n()
const page = usePage()
const title = computed(() => localizedValue(props.form.definition.title, locale.value))
const intro = computed(() => props.form.definition.intro[locale.value as FormLocale] ?? props.form.definition.intro.en ?? emptyRichTextDocument())
const locked = computed(() => !props.form.is_open || !props.canSubmit || (Boolean(props.existing) && props.form.definition.response_policy === 'single'))
const initial = () => ({ answers: { ...props.existing?.answers }, version_id: props.form.version_id, response_revision: props.existing?.revision ?? 0, submission_key: props.submissionKey })
const response = useForm(initial())
const navigation = ref<FormPageNavigation | null>(null)
const saved = computed(() => page.props.flash?.success === 'survey_response_saved')
const errors = computed(() => [...new Set(Object.values(response.errors))])
function submit() {
    if (locked.value || response.processing) return
    if (!navigation.value?.prepareSubmit()) return
    response.post(route('forms.submit', { surveyForm: props.form.slug }), {
        preserveScroll: false,
        onSuccess: () => { response.defaults(initial()); response.reset(); navigation.value?.reset() },
        onError: errors => navigation.value?.revealErrors(errors),
    })
}
</script>

<template>
    <Head :title="title"><meta name="robots" content="noindex, nofollow" /></Head>
    <div class="space-y-5">
        <div class="border-t-4 border-primary bg-default p-6 ring-1 ring-default">
            <p class="mb-3 text-xs font-semibold tracking-widest text-primary">FULLPARTY</p>
            <h1 class="mb-4 text-2xl font-semibold sm:text-3xl">{{ title }}</h1>
            <RichTextReader :document="intro" />
            <p class="mt-4 text-sm text-muted">{{ t(page.props.auth?.user ? 'forms.identity_notice' : 'forms.guest_notice') }}</p>
        </div>
        <UAlert v-if="saved" color="success" variant="soft" icon="i-lucide-check" :title="t('forms.response_saved')" :description="localizedValue(form.definition.thank_you, locale) || t('forms.thank_you')" />
        <UAlert v-if="!form.is_open" color="neutral" variant="soft" icon="i-lucide-lock" :title="t('forms.closed')" />
        <UAlert v-else-if="!canSubmit" color="info" variant="soft" :title="t('forms.sign_in_required')">
            <template #actions><UButton :to="route(page.props.auth?.user ? 'verification.notice' : 'login')" :label="t(page.props.auth?.user ? 'forms.verify_email' : 'forms.sign_in')" /></template>
        </UAlert>
        <UAlert v-else-if="existing" :color="existing.version_changed ? 'warning' : 'info'" variant="soft" :title="t(form.definition.response_policy === 'single' ? 'forms.already_submitted' : 'forms.editable_notice')" :description="existing.version_changed ? t('forms.version_changed') : undefined" />
        <form class="space-y-5" @submit.prevent="submit">
            <UAlert v-for="error in errors" :key="error" color="error" variant="soft" :title="error" />
            <FormPageQuestions ref="navigation" v-model="response.answers" :definition="form.definition" :disabled="locked" :busy="response.processing" :errors="response.errors">
                <template #actions><UButton v-if="!locked" type="submit" icon="i-lucide-send" :loading="response.processing" :label="t(existing ? 'forms.update_response' : 'forms.submit')" /></template>
            </FormPageQuestions>
        </form>
    </div>
</template>
