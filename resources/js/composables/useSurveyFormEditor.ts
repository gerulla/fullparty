import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { useConfirmationModal } from '@/composables/useConfirmationModal'
import { emptyRichTextDocument } from '@/utils/richText'
import type { FormDefinition, FormLocale, FormAnswers, SurveyFormRecord } from '@/Types/Forms'

export function useSurveyFormEditor(props: { formRecord: SurveyFormRecord | null; emptyDefinition: FormDefinition }) {
    const { t } = useI18n()
    const confirmation = useConfirmationModal()
    const language = ref<FormLocale>('en')
    const preview = ref(false)
    const previewAnswers = ref<FormAnswers>({})
    const busy = ref(false)
    const actionErrors = ref<Record<string, string>>({})
    const initial = () => {
        const definition = JSON.parse(JSON.stringify(props.formRecord?.draft ?? props.emptyDefinition)) as FormDefinition
        for (const key of ['en', 'de', 'fr', 'ja'] as const) definition.intro[key] ??= emptyRichTextDocument()
        return { slug: props.formRecord?.slug ?? '', revision: props.formRecord?.revision ?? 1, definition }
    }
    const form = useForm(initial())
    watch(() => props.formRecord?.slug, () => { form.defaults(initial()); form.reset() })
    const errors = computed(() => [...new Set([...Object.values(form.errors), ...Object.values(actionErrors.value)])])
    function save() {
        if (form.processing || busy.value) return
        actionErrors.value = {}
        const options = { preserveScroll: true, onSuccess: () => { form.defaults(initial()); form.reset() } }
        if (props.formRecord) form.put(route('admin.forms.update', { surveyForm: props.formRecord.slug }), options)
        else form.post(route('admin.forms.store'), options)
    }
    async function transition(action: 'publish' | 'unpublish' | 'open' | 'close') {
        if (!props.formRecord || form.isDirty || busy.value) return
        if (!await confirmation.open({ title: t(`forms.actions.${action}`), description: form.definition.title.en ?? '', warningText: t(`forms.confirm.${action}`), confirmLabel: t(`forms.actions.${action}`), severity: action === 'publish' || action === 'open' ? 'info' : 'warning' })) return
        busy.value = true
        actionErrors.value = {}
        router.post(route('admin.forms.transition', { surveyForm: props.formRecord.slug, action }), { revision: props.formRecord.revision }, {
            preserveScroll: true, onSuccess: () => { form.defaults(initial()); form.reset() }, onError: errors => { actionErrors.value = errors }, onFinish: () => { busy.value = false },
        })
    }
    return { form, language, preview, previewAnswers, busy, errors, save, transition }
}
