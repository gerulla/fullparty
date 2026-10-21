import { computed, onBeforeUnmount, onMounted, ref, type Ref } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import type { AddedCharacter, CharacterAddMethod, CharacterAddStep, CharacterCandidate, CharacterCreateProps, CharacterOnboardingPreviewScreen, CharacterOnboardingResponse } from '@/Types/CharacterOnboarding'
import { characterOnboardingPreview } from '@/utils/characterOnboardingPreview'
import { readCharacterImportStream } from '@/utils/characterImportStream'
import { useCharacterImportProgress } from '@/composables/useCharacterImportProgress'
import type { CharacterTransferSummary } from '@/Types/CharacterTransfer'
import { characterTransferPreview } from '@/utils/characterTransferPreview'

export function useCharacterOnboarding(props: CharacterCreateProps, canPreview: Readonly<Ref<boolean>> = ref(false)) {
    const { t, te } = useI18n()
    const step = ref<CharacterAddStep>('method')
    const method = ref<CharacterAddMethod>('lodestone')
    const candidates = ref<CharacterCandidate[]>([])
    const selected = ref<CharacterCandidate | null>(null)
    const added = ref<AddedCharacter | null>(null)
    const name = ref('')
    const world = ref('')
    const busy = ref(false)
    const searched = ref(false)
    const error = ref<string | null>(null)
    const reconnect = ref(false)
    const refreshFailed = ref(false)
    const partial = ref(false)
    const transferred = ref(false)
    const transfer = ref<CharacterTransferSummary | null>(null)
    const previewScreen = ref<CharacterOnboardingPreviewScreen>('live')
    const previewActive = computed(() => previewScreen.value !== 'live')
    const importProgress = useCharacterImportProgress()
    const searchAbort = new AbortController()
    let active = true

    const linkUrl = computed(() => route('xivauth.redirect', { onboarding: 1 }))

    function showPreview(screen: CharacterOnboardingPreviewScreen, previewMethod: CharacterAddMethod = method.value) {
        // Do not replace state while a real import/search is in flight.
        if (!canPreview.value || (busy.value && !previewActive.value)) return
        importProgress.reset()
        const state = characterOnboardingPreview(screen, previewMethod)
        previewScreen.value = screen
        method.value = previewMethod
        step.value = state.step
        candidates.value = state.candidates
        selected.value = state.selected
        added.value = state.added
        busy.value = state.busy
        searched.value = state.searched
        refreshFailed.value = state.refreshFailed
        partial.value = state.partial
        transferred.value = state.transferred
        transfer.value = screen === 'transfer' ? characterTransferPreview() : null
        reconnect.value = state.reconnect
        error.value = state.errorKey ? t(`characters.onboarding.errors.${state.errorKey}`) : null
        name.value = ''
        world.value = ''
        if (screen === 'loading') void importProgress.preview(() => showPreview('complete', previewMethod))
    }

    function errorMessage(exception: unknown) {
        if (axios.isAxiosError(exception)) {
            if (exception.response?.status === 429) return t('characters.onboarding.errors.rate_limit')
            if (exception.response?.status === 419 || exception.response?.status === 401) return t('characters.onboarding.errors.session')
            const errors = exception.response?.data?.errors ?? {}
            const code = errors.onboarding?.[0]
            reconnect.value = code === 'xivauth_reconnect'
            if (typeof code === 'string' && te(`characters.onboarding.errors.${code}`)) return t(`characters.onboarding.errors.${code}`)
            if (Array.isArray(errors.quota)) return errors.quota[0]
        }
        return t('characters.onboarding.errors.generic')
    }

    async function loadCandidates() {
        if (busy.value) return
        if (previewActive.value) { showPreview('list'); return }
        busy.value = true
        error.value = null
        reconnect.value = false
        candidates.value = []
        selected.value = null
        searched.value = false
        try {
            const response = method.value === 'xivauth'
                ? await axios.get(route('characters.onboarding.xivauth'), { signal: searchAbort.signal })
                : await axios.get(route('characters.onboarding.search'), {
                    params: { name: name.value.trim(), world: world.value || undefined }, signal: searchAbort.signal,
                })
            if (!active) return
            candidates.value = response.data.characters
            searched.value = true
        } catch (exception) {
            if (active && !axios.isCancel(exception)) error.value = errorMessage(exception)
        } finally {
            busy.value = false
        }
    }

    function chooseMethod(value: CharacterAddMethod) {
        if (busy.value) return
        if (previewActive.value) { showPreview('list', value); return }
        if (value === 'xivauth' && !props.xivAuthLinked) {
            window.location.assign(linkUrl.value)
            return
        }
        method.value = value
        reconnect.value = false
        step.value = 'select'
        error.value = null
        candidates.value = []
        selected.value = null
        searched.value = false
        if (value === 'xivauth') void loadCandidates()
    }

    function select(candidate: CharacterCandidate, toggle = false) {
        if (busy.value) return
        selected.value = toggle && selected.value?.lodestone_id === candidate.lodestone_id ? null : candidate
        error.value = null
        if (previewActive.value) previewScreen.value = selected.value ? 'selected' : 'list'
    }

    function back() {
        if (busy.value) return
        if (previewActive.value) { showPreview('method'); return }
        error.value = null
        reconnect.value = false
        step.value = 'method'
        selected.value = null
    }

    async function refreshData() {
        if (!added.value || previewActive.value) return
        refreshFailed.value = false
        partial.value = false
        try {
            const response = await axios.post(route('characters.onboarding.refresh', added.value.id), {}, {
                adapter: 'fetch',
                responseType: 'stream',
                headers: { Accept: 'application/json', 'X-Character-Progress': 'stream' },
                signal: searchAbort.signal,
            })
            const result = await readCharacterImportStream(response.data, importProgress.update)
            if (!active) return
            added.value = result.character
            partial.value = result.partial
            await importProgress.finish()
        } catch {
            refreshFailed.value = true
        }
        if (active) step.value = transfer.value?.pending ? 'transfer' : 'complete'
    }

    async function confirm() {
        if (busy.value || !selected.value) return
        if (previewActive.value) { showPreview('loading'); return }
        importProgress.reset()
        busy.value = true
        error.value = null
        transferred.value = false
        step.value = 'loading'
        try {
            const response = await axios.post<CharacterOnboardingResponse>(route('characters.onboarding.store'), {
                method: method.value, lodestone_id: selected.value.lodestone_id,
            })
            if (!active) return
            if (response.data.outcome !== 'added') {
                step.value = 'select'
                router.visit(route('characters.claim', response.data.character.id))
                return
            }
            added.value = response.data.character
            transferred.value = response.data.transferred
            transfer.value = response.data.transfer ?? null
            await refreshData()
        } catch (exception) {
            if (active) {
                error.value = errorMessage(exception)
                step.value = 'select'
            }
        } finally {
            busy.value = false
        }
    }

    async function retryRefresh() {
        if (busy.value || !added.value) return
        if (previewActive.value) { showPreview('loading'); return }
        importProgress.reset()
        busy.value = true
        step.value = 'loading'
        try { await refreshData() } finally { busy.value = false }
    }

    function finishTransfer() {
        if (previewActive.value) { showPreview('transferred'); return }
        transfer.value = null
        transferred.value = true
        step.value = 'complete'
    }

    onMounted(() => {
        const oauthError = props.errors?.onboarding ?? props.errors?.error
        if (oauthError) {
            error.value = te(`characters.onboarding.errors.${oauthError}`)
                ? t(`characters.onboarding.errors.${oauthError}`) : t('characters.onboarding.errors.xivauth_cancelled')
        } else if (props.resumeXivAuth && props.xivAuthLinked) {
            chooseMethod('xivauth')
        }
    })
    onBeforeUnmount(() => { active = false; searchAbort.abort() })

    return { step, method, candidates, selected, added, name, world, busy, searched, error, reconnect,
        refreshFailed, partial, transferred, linkUrl, chooseMethod, loadCandidates, select, back, confirm, retryRefresh,
        previewScreen, previewActive, showPreview, transfer, finishTransfer, importProgress: importProgress.progress }
}
