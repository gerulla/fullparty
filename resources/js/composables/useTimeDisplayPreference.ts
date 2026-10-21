import axios from 'axios'
import { ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { useTimeDisplayMode } from '@/composables/useTimeDisplayMode'
import type { TimeDisplayMode } from '@/composables/useTimeDisplayMode'

const isSaving = ref(false)

export function useTimeDisplayPreference() {
    const page = usePage()
    const { t } = useI18n()
    const toast = useToast()
    const { timeDisplayMode, setTimeDisplayMode } = useTimeDisplayMode()

    function syncMode(mode: TimeDisplayMode) {
        setTimeDisplayMode(mode)
        if (page.props.auth?.user) page.props.auth.user.time_display_mode = mode
    }

    async function updateMode(mode: TimeDisplayMode) {
        if (mode === timeDisplayMode.value || isSaving.value) return

        const previousMode = timeDisplayMode.value
        syncMode(mode)
        isSaving.value = true

        try {
            const response = await axios.patch<{ time_display_mode: TimeDisplayMode }>(
                route('settings.time-display'), { time_display_mode: mode },
            )
            syncMode(response.data.time_display_mode)
        } catch {
            syncMode(previousMode)
            toast.add({
                color: 'error',
                title: t('navigation.topbar.time_display.error_title'),
                description: t('navigation.topbar.time_display.error_description'),
            })
        } finally {
            isSaving.value = false
        }
    }

    return { timeDisplayMode, isSaving, updateMode }
}
