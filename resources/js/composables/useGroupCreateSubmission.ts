import { computed, onScopeDispose, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { isAxiosError } from 'axios'
import type { GroupCreateArtworkController, GroupCreateDraft, GroupCreateStep } from '@/Types/Groups'
import { firstGroupCreateErrorStep, groupCreateErrorStep, groupCreatePayload } from '@/utils/groupCreateSubmission'

export function useGroupCreateSubmission(
    draft: GroupCreateDraft,
    artwork: GroupCreateArtworkController,
    showStep: (step: GroupCreateStep) => Promise<void>,
) {
    const processing = ref(false)
    const uploadPercent = ref<number | null>(null)
    const errors = ref<Record<string, string>>({})
    const failure = ref<string | null>(null)
    const failureStep = ref<GroupCreateStep>('joining')
    let correcting = false
    let removeListeners: (() => void)[] = []

    const stepErrors = computed(() => Object.fromEntries(Object.entries(errors.value)
        .filter(([field]) => groupCreateErrorStep(field) === draft.step)))
    const currentFailure = computed(() => draft.step === failureStep.value ? failure.value : null)

    function cleanup() {
        removeListeners.splice(0).forEach(remove => remove())
    }

    function fail(status?: number) {
        failureStep.value = status === 413 ? 'artwork' : 'joining'
        const reason = status === 413 ? 'upload_too_large'
            : status === 401 || status === 419 ? 'session_expired'
            : status === 403 ? 'forbidden'
            : status === 429 ? 'too_many_requests'
            : status === undefined ? 'network_error' : 'server_error'
        failure.value = `groups.index.create_flow.submission.${reason}`
        processing.value = false
        correcting = true
        void showStep(failureStep.value)
    }

    function advance(step: GroupCreateStep) {
        errors.value = Object.fromEntries(Object.entries(errors.value)
            .filter(([field]) => groupCreateErrorStep(field) !== draft.step))
        if (failureStep.value === draft.step) failure.value = null
        return showStep(correcting ? firstGroupCreateErrorStep(errors.value) : step)
    }

    function submit() {
        if (processing.value) return
        if (!artwork.canContinue) {
            void showStep('artwork')
            return
        }

        cleanup()
        errors.value = {}
        failure.value = null
        processing.value = true
        uploadPercent.value = artwork.files.profile_picture || artwork.files.banner_image ? 0 : null
        // A correlation marker, not a credential; also works on HTTP LAN previews.
        const requestId = `${Date.now()}-${Math.random().toString(36).slice(2)}`
        const matchesRequest = (headers: Record<string, unknown> | undefined) => headers?.['X-Group-Create-Request'] === requestId

        // Inertia exposes transport failures as global events. Only consume this POST's failures.
        removeListeners = [
            router.on('invalid', event => {
                if (!matchesRequest(event.detail.response.config?.headers)) return
                event.preventDefault()
                fail(event.detail.response.status)
            }),
            router.on('exception', event => {
                const error = event.detail.exception
                if (!isAxiosError(error) || !matchesRequest(error.config?.headers)) return
                event.preventDefault()
                fail(error.response?.status)
            }),
        ]

        try {
            router.post(route('groups.store'), groupCreatePayload(draft, artwork.files), {
                forceFormData: true,
                preserveState: true,
                preserveScroll: true,
                showProgress: false,
                headers: { 'X-Group-Create-Request': requestId },
                onProgress: progress => {
                    if (uploadPercent.value !== null) uploadPercent.value = progress?.percentage ?? 0
                },
                onError: validationErrors => {
                    errors.value = validationErrors
                    correcting = true
                    processing.value = false
                    void showStep(firstGroupCreateErrorStep(validationErrors))
                },
                onBeforeUpdate: page => {
                    if (page.url.split('?')[0].endsWith(`/${draft.identity.slug}/dashboard`)) {
                        router.remember(undefined, 'groups.create')
                    }
                },
                onCancel: () => { processing.value = false },
                onFinish: () => {
                    processing.value = false
                    cleanup()
                },
            })
        } catch {
            cleanup()
            fail(500)
        }
    }

    onScopeDispose(cleanup)
    return reactive({ processing, uploadPercent, errors, stepErrors, currentFailure, submit, advance })
}
