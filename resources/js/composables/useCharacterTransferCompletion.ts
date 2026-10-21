import { computed, onBeforeUnmount, ref, watch, type Ref } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import type { CharacterTransferSummary, TransferChoice } from '@/Types/CharacterTransfer'

export function useCharacterTransferCompletion(summary: Ref<CharacterTransferSummary | null>, preview: Readonly<Ref<boolean>>, done: () => void) {
    const { t, te } = useI18n()
    const history = ref<TransferChoice | null>(null)
    const upcoming = ref<TransferChoice | null>(null)
    const busy = ref(false)
    const error = ref<string | null>(null)
    let active = true
    const ready = computed(() => !!history.value && !!upcoming.value && !busy.value)
    watch([() => summary.value?.id, preview], () => {
        history.value = null
        upcoming.value = null
        error.value = null
    })

    async function complete() {
        if (!ready.value || !summary.value) return
        if (preview.value) { done(); return }
        busy.value = true
        error.value = null
        try {
            await axios.post(route('characters.transfer.complete', summary.value.id), {
                history: history.value, upcoming: upcoming.value,
            })
            if (active) {
                done()
                router.reload({ only: ['account_flags'] })
            }
        } catch (exception) {
            if (!active) return
            const key = axios.isAxiosError(exception) ? exception.response?.data?.errors?.transfer?.[0] : null
            error.value = t(typeof key === 'string' && te(`characters.transfer.errors.${key}`)
                ? `characters.transfer.errors.${key}` : 'characters.transfer.errors.generic')
        } finally {
            if (active) busy.value = false
        }
    }
    onBeforeUnmount(() => { active = false })
    return { history, upcoming, busy, error, ready, complete }
}
