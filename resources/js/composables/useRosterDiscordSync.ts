import axios from 'axios'
import { computed, inject, provide, ref, type InjectionKey } from 'vue'
import { useNow } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { useToast } from '@nuxt/ui/composables'
import type { ContextMenuItem } from '@nuxt/ui'
import { route } from 'ziggy-js'
import type { ActivitySlot } from '@/Types/ActivityRoster'
import type { ConfirmationModalInput } from '@/Types/Shared'
import { useConfirmationModal } from '@/composables/useConfirmationModal'

const discordSyncKey: InjectionKey<(slot: ActivitySlot) => ContextMenuItem[]> = Symbol('roster-discord-sync')

export function provideRosterDiscordSync(groupSlug: () => string, activityId: () => number, availableFrom: () => string | null | undefined, busy: () => boolean) {
    const { t } = useI18n()
    const toast = useToast()
    const modal = useConfirmationModal()
    const modalOpen = ref(false)
    const now = useNow({ interval: 1000 })
    const available = computed(() => Boolean(availableFrom() && Date.parse(availableFrom()!) <= now.value.getTime()))
    const l = (key: string) => t(`groups.activities.management.discord_sync.${key}`)

    async function open(slot: ActivitySlot) {
        if (!available.value || busy() || modalOpen.value || !slot.assigned_character) return
        const expectedStateToken = slot.state_token
        const url = route('groups.dashboard.activities.discord-participants.store', { group: groupSlug(), activity: activityId(), slot: slot.id })
        const input: ConfirmationModalInput = { label: l('id_label'), type: 'text', maxlength: 20, placeholder: '234567890123456789' }
        modalOpen.value = true
        try {
            await modal.open({
                title: l('title'),
                description: t('groups.activities.management.discord_sync.description', { character: `${slot.assigned_character.name} [${slot.assigned_character.world ?? ''}]` }),
                severity: 'info',
                warningText: l('temporary_notice'),
                confirmLabel: l('submit'),
                confirmIcon: 'i-lucide-send',
                input,
                onConfirm: async ({ inputValue, patch }) => {
                    const id = inputValue.trim()
                    if (!/^[1-9][0-9]{16,19}$/.test(id)) {
                        patch({ input: { ...input, error: l('invalid_id') } })
                        return false
                    }
                    if (!available.value) {
                        patch({ input: { ...input, error: l('unavailable') } })
                        return false
                    }
                    patch({ confirmLoading: true, input: { ...input, error: undefined } })
                    try {
                        await axios.post(url, { discord_user_id: id, expected_slot_state_token: expectedStateToken })
                        toast.add({ title: l('success'), color: 'success', icon: 'i-lucide-check' })
                        return true
                    } catch (error) {
                        const data = axios.isAxiosError(error) ? error.response?.data : null
                        const message = data?.errors?.discord_user_id?.[0] ?? data?.message
                        patch({ input: { ...input, error: typeof message === 'string' ? message : l('failed') } })
                        return false
                    } finally {
                        patch({ confirmLoading: false })
                    }
                },
            })
        } finally { modalOpen.value = false }
    }

    provide(discordSyncKey, slot => available.value && slot.assigned_character_id ? [{
        label: l('title'),
        icon: 'ic:baseline-discord',
        disabled: busy() || modalOpen.value,
        onSelect: () => { void open(slot) },
    }] : [])
}

export function useRosterDiscordSyncMenu() {
    return inject(discordSyncKey, () => [])
}
