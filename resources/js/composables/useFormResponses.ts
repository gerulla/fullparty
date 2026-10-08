import { onBeforeUnmount, ref } from 'vue'
import axios from 'axios'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import type { FormResponseDetail } from '@/Types/Forms'

export function useFormResponses(slug: () => string) {
    const { t } = useI18n()
    const open = ref(false)
    const loading = ref(false)
    const error = ref('')
    const detail = ref<FormResponseDetail | null>(null)
    let controller: AbortController | null = null
    async function show(id: number) {
        controller?.abort()
        const request = new AbortController()
        controller = request
        open.value = true
        loading.value = true
        detail.value = null
        error.value = ''
        try {
            const result = await axios.get<FormResponseDetail>(route('admin.forms.response', { surveyForm: slug(), surveyResponse: id }), { signal: request.signal })
            if (!request.signal.aborted) detail.value = result.data
        } catch (failure) {
            if (!axios.isCancel(failure)) error.value = t('forms.load_failed')
        } finally {
            if (!request.signal.aborted) loading.value = false
        }
    }
    function close() { controller?.abort(); open.value = false; detail.value = null; loading.value = false }
    onBeforeUnmount(() => controller?.abort())
    return { open, loading, error, detail, show, close }
}
