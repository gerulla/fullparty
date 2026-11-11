import { ref, toValue, type MaybeRefOrGetter } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import { useConfirmationModal } from '@/composables/useConfirmationModal'
import type { GroupMembershipActionsGroup } from '@/Types/Groups'

export function useGroupMembershipActions(group: MaybeRefOrGetter<GroupMembershipActionsGroup>) {
    const { t } = useI18n()
    const confirmationModal = useConfirmationModal()
    const notificationPreferencesOpen = ref(false)
    const leaveConfirmationOpen = ref(false)

    function openNotificationPreferences() {
        if (toValue(group).permissions.can_toggle_notifications) notificationPreferencesOpen.value = true
    }

    async function leaveGroup() {
        const currentGroup = toValue(group)
        if (!currentGroup.permissions.can_leave || leaveConfirmationOpen.value) return

        leaveConfirmationOpen.value = true
        try {
            await confirmationModal.open({
                title: t('groups.dashboard.leave_group_modal.title', { name: currentGroup.name }),
                description: t('groups.dashboard.leave_group_modal.description', { name: currentGroup.name }),
                severity: 'error',
                warningText: t('groups.dashboard.leave_group_modal.warning'),
                confirmLabel: t('groups.dashboard.leave_group_modal.confirm'),
                confirmIcon: 'i-lucide-log-out',
                onConfirm: async ({ patch }) => {
                    if (toValue(group).slug !== currentGroup.slug || !toValue(group).permissions.can_leave) return false

                    patch({ confirmLoading: true })
                    return await new Promise<boolean>(resolve => {
                        router.post(route('groups.leave', currentGroup.slug), {
                            redirect_to: currentGroup.is_visible ? 'profile' : 'groups',
                        }, {
                            onSuccess: () => resolve(true),
                            onError: () => resolve(false),
                            onFinish: () => {
                                patch({ confirmLoading: false })
                                resolve(false)
                            },
                        })
                    })
                },
            })
        } finally {
            leaveConfirmationOpen.value = false
        }
    }

    return { notificationPreferencesOpen, openNotificationPreferences, leaveConfirmationOpen, leaveGroup }
}
