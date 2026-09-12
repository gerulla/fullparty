import axios from 'axios'
import { onBeforeUnmount, ref, watch } from 'vue'
import { route } from 'ziggy-js'
import type { ApplicantRecord, ApplicantRecordContext } from '@/Types/ApplicantRecord'

export function useApplicantRecord(props: ApplicantRecordContext) {
    const record = ref<ApplicantRecord | null>(null)
    const loading = ref(false)
    const failed = ref(false)
    let requestId = 0
    let controller: AbortController | null = null
    let contextKey = ''

    function reset() {
        requestId++
        controller?.abort()
        controller = null
        record.value = null
        loading.value = false
        failed.value = false
    }

    async function reload() {
        if (!props.open || !props.shouldFetch || loading.value) return
        const currentRequest = ++requestId
        controller = new AbortController()
        loading.value = true
        failed.value = false
        try {
            const response = await axios.get<ApplicantRecord>(route('groups.dashboard.activities.application-record', {
                group: props.groupSlug, activity: props.activityId, application: props.applicationId,
            }), { signal: controller.signal })
            if (currentRequest === requestId) record.value = response.data
        } catch (error) {
            if (currentRequest === requestId && !axios.isCancel(error)) failed.value = true
        } finally {
            if (currentRequest === requestId) loading.value = false
        }
    }

    watch(() => [props.open, props.shouldFetch, props.groupSlug, props.activityId, props.applicationId], () => {
        const key = `${props.groupSlug}:${props.activityId}:${props.applicationId}`
        if (key !== contextKey || !props.open) {
            reset()
            contextKey = key
        }
        if (props.open && props.shouldFetch && !record.value && !failed.value) void reload()
    }, { immediate: true })

    onBeforeUnmount(reset)
    return { record, loading, failed, reload }
}
