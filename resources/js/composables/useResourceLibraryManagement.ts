import { useOverlay, useToast } from '@nuxt/ui/composables'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import ResourceLibraryManageModal from '@/components/Groups/Resources/ResourceLibraryManageModal.vue'
import { useConfirmationModal } from '@/composables/useConfirmationModal'
import type { ResourceLibrary, ResourceLibraryVisibility } from '@/Types/GroupResources'

export function useResourceLibraryManagement(groupSlug: () => string, library: () => ResourceLibrary) {
    const overlay = useOverlay()
    const toast = useToast()
    const confirmation = useConfirmationModal()
    const { t } = useI18n()
    let isOpen = false

    const open = async () => {
        if (isOpen) return
        isOpen = true
        let busy = false
        const modal = overlay.create(ResourceLibraryManageModal, { destroyOnClose: true })
        const setBusy = (value: boolean) => {
            busy = value
            modal.patch({ busy: value })
        }
        const instance = modal.open({
            visibility: library().visibility,
            onSave: async (visibility: ResourceLibraryVisibility) => {
                if (busy) return
                setBusy(true)
                modal.patch({ error: '' })
                try {
                    await axios.put(route('groups.dashboard.resources.library.update', { group: groupSlug() }), { visibility })
                    toast.add({ title: t('groups.resources.library.saved'), color: 'success', icon: 'i-lucide-check' })
                    modal.close(true)
                    router.reload({ only: ['library'] })
                } catch (error) {
                    modal.patch({ error: axios.isAxiosError(error) ? error.response?.data?.errors?.visibility?.[0] ?? t('groups.resources.library.save_failed') : t('groups.resources.library.save_failed') })
                    setBusy(false)
                }
            },
            onDelete: async () => {
                if (busy) return
                setBusy(true)
                const input = { type: 'text' as const, label: t('groups.resources.library.confirm_input'), requiredValue: 'i am sure' }
                const deleted = await confirmation.open({
                    title: t('groups.resources.library.confirm_title'),
                    description: t('groups.resources.library.confirm_description'),
                    warningText: t('groups.resources.library.delete_warning'),
                    confirmLabel: t('groups.resources.library.delete_all'),
                    confirmIcon: 'i-lucide-trash-2',
                    severity: 'error',
                    input,
                    onConfirm: async ({ inputValue, patch }) => {
                        if (inputValue !== 'i am sure') return false
                        patch({ confirmLoading: true, input })
                        try {
                            await axios.delete(route('groups.dashboard.resources.library.resources.destroy', { group: groupSlug() }), { data: { confirmation: inputValue } })
                            toast.add({ title: t('groups.resources.library.deleted'), color: 'success', icon: 'i-lucide-check' })
                            return true
                        } catch {
                            patch({ confirmLoading: false, input: { ...input, error: t('groups.resources.library.delete_failed') } })
                            return false
                        }
                    },
                })
                if (deleted) {
                    modal.close(true)
                    router.visit(route('groups.dashboard.resources.manage', { group: groupSlug() }), { preserveScroll: true })
                } else {
                    setBusy(false)
                }
            },
        })
        try {
            await instance.result
        } finally {
            isOpen = false
        }
    }

    return { open }
}
