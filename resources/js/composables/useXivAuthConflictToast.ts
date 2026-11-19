import { watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { useToast } from '@nuxt/ui/composables'

export function useXivAuthConflictToast() {
    const page = usePage()
    const { t } = useI18n()
    const toast = useToast()
    watch(() => page.props.flash?.data?.xivauth_character_sync?.conflicts, conflicts => {
        if (!Array.isArray(conflicts) || !conflicts.length) return
        toast.add({
            title: t('characters.xivauth.sync.conflict_title'),
            description: t(conflicts.length === 1
                ? 'characters.xivauth.sync.conflict_description_one'
                : 'characters.xivauth.sync.conflict_description_many', {
                characters: conflicts.map(conflict => conflict?.name).filter(Boolean).join(', '),
            }),
            color: 'warning',
            icon: 'i-lucide-triangle-alert',
        })
    }, { immediate: true })
}
