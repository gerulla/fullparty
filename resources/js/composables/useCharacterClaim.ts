import { computed, onBeforeUnmount, onMounted, ref, type Ref } from 'vue'
import axios from 'axios'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { useClipboard } from '@vueuse/core'
import type { CharacterClaimCodeResponse, CharacterClaimProps, CharacterClaimStage, CharacterClaimPreviewScreen } from '@/Types/CharacterClaim'
import { characterClaimPreview } from '@/utils/characterClaimPreview'
import { characterTransferPreview } from '@/utils/characterTransferPreview'
import type { CharacterTransferSummary } from '@/Types/CharacterTransfer'

export function useCharacterClaim(props: CharacterClaimProps, canPreview: Readonly<Ref<boolean>> = ref(false)) {
    const { t, te, locale } = useI18n()
    const { copy, copied } = useClipboard({ legacy: true })
    const character = ref(props.character)
    const context = ref(props.context ?? 'owned')
    const transferred = ref(!!props.transfer)
    const transfer = ref<CharacterTransferSummary | null>(props.transfer ?? null)
    const stage = ref<CharacterClaimStage>(context.value === 'already_claimed' ? 'claimed_elsewhere'
        : transfer.value?.pending ? 'transfer' : character.value.status === 'claimed' ? 'complete' : 'instructions')
    const code = ref<string | null>(null)
    const expiresAt = ref<string | null>(null)
    const busy = ref(false)
    const error = ref<string | null>(null)
    const copyError = ref(false)
    const abort = new AbortController()
    let active = true
    const previewScreen = ref<CharacterClaimPreviewScreen>('live')
    const previewActive = computed(() => canPreview.value && previewScreen.value !== 'live')
    const previewExpiry = ref('')
    const preview = computed(() => previewActive.value ? characterClaimPreview(previewScreen.value, previewExpiry.value) : null)
    let previewTimer: ReturnType<typeof setTimeout> | undefined

    const profileUrl = computed(() => `https://na.finalfantasyxiv.com/lodestone/character/${(preview.value?.character ?? character.value).lodestone_id}/`)
    const charactersUrl = computed(() => route('account.characters', previewActive.value ? {} : { character: character.value.id }))
    const expiry = computed(() => {
        const value = preview.value ? preview.value.expiresAt : expiresAt.value
        return value ? new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : ''
    })

    function showPreview(screen: CharacterClaimPreviewScreen) {
        if (!canPreview.value || busy.value) return
        clearTimeout(previewTimer)
        previewTimer = undefined
        previewExpiry.value = new Date(Date.now() + 86400000).toISOString()
        previewScreen.value = screen
    }

    function previewClaim() {
        if (!previewActive.value || preview.value?.stage !== 'linked_elsewhere') return
        showPreview(preview.value.character.status === 'verified' ? 'instructions_verified' : 'instructions')
    }

    function showError(exception: unknown) {
        let key = 'generic'
        if (axios.isAxiosError(exception)) {
            const status = exception.response?.status
            if (status === 409 && exception.response?.data?.outcome === 'already_claimed') {
                character.value = exception.response.data.character
                context.value = 'already_claimed'
                stage.value = 'claimed_elsewhere'
                code.value = null
                expiresAt.value = null
                error.value = null
                return
            }
            if (status === 429) key = 'rate_limit'
            else if (status === 401 || status === 419) key = 'session'
            else if (status === 403 || status === 404) key = 'unavailable'
            else {
                const reason = exception.response?.data?.errors?.claim?.[0]
                if (typeof reason === 'string' && te(`characters.claim_flow.errors.${reason}`)) key = reason
            }
        }
        if (key === 'expired_code' || key === 'code_changed' || key === 'ownership_changed') {
            code.value = null
            expiresAt.value = null
        }
        const quotaError = axios.isAxiosError(exception) ? exception.response?.data?.errors?.quota?.[0] : null
        error.value = typeof quotaError === 'string' ? quotaError : t(`characters.claim_flow.errors.${key}`)
    }

    async function loadCode() {
        if (previewActive.value) { showPreview('instructions'); return }
        if (busy.value || ['complete', 'claimed_elsewhere', 'transfer'].includes(stage.value)) return
        busy.value = true
        error.value = null
        copyError.value = false
        try {
            const { data } = await axios.post<CharacterClaimCodeResponse>(route('characters.claim.code', character.value.id), {}, { signal: abort.signal })
            if (!active) return
            character.value = data.character
            context.value = data.context
            code.value = data.code
            expiresAt.value = data.expires_at
            transfer.value = data.transfer ?? null
            if (data.context === 'owned' && data.character.status === 'claimed') stage.value = transfer.value?.pending ? 'transfer' : 'complete'
        } catch (exception) {
            if (active && !axios.isCancel(exception)) showError(exception)
        } finally {
            if (active) busy.value = false
        }
    }

    async function copyCode() {
        if (previewActive.value) {
            if (preview.value?.code) showPreview('copied')
            return
        }
        if (!code.value) return
        copyError.value = false
        try { await copy(code.value) } catch { copyError.value = true }
    }

    async function verify() {
        if (previewActive.value) {
            if (!preview.value?.code || preview.value.busy) return
            showPreview('checking')
            previewTimer = setTimeout(() => showPreview('complete'), 1800)
            return
        }
        if (busy.value || !code.value || stage.value !== 'instructions') return
        busy.value = true
        error.value = null
        stage.value = 'checking'
        try {
            const { data } = await axios.post(route('characters.claim.verify', character.value.id), { code: code.value }, { signal: abort.signal })
            if (!active) return
            character.value = data.character
            context.value = 'owned'
            transferred.value = data.transferred === true
            transfer.value = data.transfer ?? null
            stage.value = transfer.value?.pending ? 'transfer' : 'complete'
            code.value = null
            expiresAt.value = null
        } catch (exception) {
            if (active && !axios.isCancel(exception)) {
                stage.value = 'instructions'
                showError(exception)
            }
        } finally {
            if (active) busy.value = false
        }
    }

    onMounted(() => { void loadCode() })
    onBeforeUnmount(() => { active = false; abort.abort(); clearTimeout(previewTimer) })

    // Preview state overlays the live flow, so exiting restores the real character and code.
    return {
        transfer: computed(() => previewScreen.value === 'transfer' && previewActive.value ? characterTransferPreview() : transfer.value),
        finishTransfer: () => {
            if (previewActive.value) { showPreview('complete'); return }
            transfer.value = null
            transferred.value = true
            stage.value = 'complete'
        },
        character: computed(() => preview.value?.character ?? character.value),
        context: computed(() => preview.value ? (previewScreen.value === 'instructions_verified' ? 'transfer' : 'owned') : context.value),
        transferred: computed(() => !previewActive.value && transferred.value),
        stage: computed(() => preview.value?.stage ?? stage.value),
        code: computed(() => preview.value ? preview.value.code : code.value),
        busy: computed(() => preview.value?.busy ?? busy.value),
        error: computed(() => preview.value
            ? (preview.value.errorKey ? t(`characters.claim_flow.errors.${preview.value.errorKey}`) : null) : error.value),
        copied: computed(() => preview.value?.copied ?? copied.value),
        copyError: computed(() => preview.value?.copyError ?? copyError.value),
        expiry, profileUrl, charactersUrl, loadCode, copyCode, verify,
        previewScreen, previewActive, showPreview, previewClaim,
    }
}
