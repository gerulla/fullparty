import axios from 'axios'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { route } from 'ziggy-js'
import type { AllianceProgressContext, AllianceProgressRecord, AllianceProgressResponse } from '@/Types/AllianceProgress'

export function useAllianceProgress(context: AllianceProgressContext) {
    const records = ref<Record<number, AllianceProgressRecord>>({})
    const loading = ref(false)
    const failed = ref(false)
    const fflogsUnavailable = computed(() => Object.values(records.value).some(record => record.fflogs_unavailable))
    const characterIds = computed(() => [...new Set(context.slots.flatMap(slot => slot.assigned_character_id === null ? [] : [slot.assigned_character_id]))].sort((a, b) => a - b))
    let requestId = 0
    let controller: AbortController | null = null
    let currentContext = ''

    function cancel() {
        requestId++
        controller?.abort()
        controller = null
        loading.value = false
    }

    async function reload(retryUnavailable = true) {
        if (!context.enabled || loading.value) return
        const pending = characterIds.value.filter(id => !records.value[id] || (retryUnavailable && records.value[id].fflogs_unavailable))
        if (!pending.length) return
        const batches: number[][] = []
        for (let index = 0; index < pending.length; index += 4) batches.push(pending.slice(index, index + 4))
        const currentRequest = ++requestId
        controller = new AbortController()
        const signal = controller.signal
        const url = route('groups.activities.alliance-progress', { group: context.groupSlug, activity: context.activityId })
        loading.value = true
        failed.value = false

        async function worker() {
            while (batches.length && currentRequest === requestId) {
                const ids = batches.shift()!
                try {
                    const response = await axios.get<AllianceProgressResponse>(url, { params: { character_ids: ids }, signal })
                    if (currentRequest === requestId) records.value = { ...records.value, ...response.data.characters }
                } catch (error) {
                    if (currentRequest === requestId && !axios.isCancel(error)) failed.value = true
                }
            }
        }

        await Promise.all([worker(), worker()])
        if (currentRequest === requestId) loading.value = false
    }

    watch(() => [context.enabled, context.groupSlug, context.activityId, context.targetProgPointKey, characterIds.value.join(',')], () => {
        const key = `${context.groupSlug}:${context.activityId}:${context.targetProgPointKey ?? ''}:${characterIds.value.join(',')}`
        cancel()
        if (key !== currentContext) {
            records.value = {}
            failed.value = false
            currentContext = key
        }
        if (context.enabled) void reload(false)
    }, { immediate: true })

    onBeforeUnmount(cancel)
    return { records, loading, failed, fflogsUnavailable, reload }
}
