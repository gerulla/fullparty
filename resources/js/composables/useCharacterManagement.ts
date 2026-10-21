import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { useConfirmationModal } from '@/composables/useConfirmationModal'
import type { AccountCharacter, CharacterClassProgress, CharacterManagementActions, CharacterPhantomJobProgress } from '@/Types/Characters'

export function useCharacterManagement(character: () => AccountCharacter | null, onRemoved: () => void): CharacterManagementActions {
    const { t } = useI18n()
    const confirmation = useConfirmationModal()
    const pendingAction = ref<string | null>(null)
    const error = ref<string | null>(null)
    const confirmingRemoval = ref(false)
    const busy = computed(() => pendingAction.value !== null || confirmingRemoval.value)

    watch(() => character()?.id, () => { error.value = null })

    function submit(action: string, routeName: string, data: Record<string, number | boolean> = {}) {
        const current = character()
        if (!current || busy.value) return
        error.value = null
        pendingAction.value = action
        router.post(route(routeName, current.id), data, {
            preserveScroll: true,
            preserveState: true,
            onError: () => { error.value = t('characters.detail.action_failed') },
            onFinish: () => { pendingAction.value = null },
        })
    }

    async function remove() {
        const current = character()
        if (!current || busy.value) return
        confirmingRemoval.value = true
        try {
            const confirmed = await confirmation.open({
                title: t('characters.detail.remove'),
                description: [current.name, current.world].filter(Boolean).join(' · '),
                warningText: t('characters.card.unclaim_description'),
                confirmLabel: t('characters.detail.remove'),
                confirmIcon: 'i-lucide-unlink',
                severity: 'error',
            })
            if (!confirmed || character()?.id !== current.id) return
            error.value = null
            pendingAction.value = 'remove'
            router.delete(route('characters.destroy', current.id), {
                preserveScroll: true,
                preserveState: true,
                onSuccess: onRemoved,
                onError: () => { error.value = t('characters.detail.action_failed') },
                onFinish: () => { pendingAction.value = null },
            })
        } finally {
            confirmingRemoval.value = false
        }
    }

    return reactive({
        busy, pendingAction, error,
        refresh: () => submit('refresh', 'characters.refresh'),
        makePrimary: () => {
            if (!character()?.verified_at || character()?.is_primary) return
            submit('primary', 'characters.make-primary')
        },
        remove,
        toggleClass: (job: CharacterClassProgress) => submit(`class:${job.id}`, 'characters.preferred-class', {
            character_class_id: job.id, is_preferred: !job.is_preferred,
        }),
        togglePhantomJob: (job: CharacterPhantomJobProgress) => submit(`phantom:${job.id}`, 'characters.preferred-phantom-job', {
            phantom_job_id: job.id, is_preferred: !job.is_preferred,
        }),
    })
}
