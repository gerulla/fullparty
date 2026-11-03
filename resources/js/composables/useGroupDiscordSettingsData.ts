import axios from 'axios'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { GroupDiscordSettingsData, MembershipCoverage } from '@/Types/GroupDiscord'

export function useGroupDiscordSettingsData(endpoint: () => string, enabled: () => boolean = () => true) {
    const data = ref<GroupDiscordSettingsData | null>(null)
    const loading = ref(true)
    const failed = ref(false)
    let controller: AbortController | null = null

    async function loadCoverage(signal: AbortSignal) {
        try {
            const response = await axios.get<{ membershipCoverage: MembershipCoverage }>(endpoint(), {
                params: { section: 'coverage' }, signal, headers: { Accept: 'application/json' },
            })
            if (!signal.aborted && data.value) data.value.membershipCoverage = response.data.membershipCoverage
        } catch {
            if (!signal.aborted && data.value) data.value.membershipCoverage = null
        }
    }

    async function reload() {
        controller?.abort()
        if (!enabled()) {
            loading.value = false
            return
        }
        controller = new AbortController()
        const { signal } = controller
        loading.value = true
        failed.value = false
        try {
            const response = await axios.get<GroupDiscordSettingsData>(endpoint(), {
                signal, headers: { Accept: 'application/json' },
            })
            if (signal.aborted) return
            data.value = response.data
            if (data.value.integration) void loadCoverage(signal)
        } catch (error) {
            if (!signal.aborted && !axios.isCancel(error)) failed.value = true
        } finally {
            if (!signal.aborted) loading.value = false
        }
    }

    onMounted(() => { void reload() })
    watch(enabled, () => { void reload() })
    onBeforeUnmount(() => controller?.abort())

    return { data, loading, failed, reload }
}
