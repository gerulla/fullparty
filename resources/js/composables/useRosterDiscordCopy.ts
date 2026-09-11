import axios from 'axios'
import { inject, provide, ref, watch, type InjectionKey } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@nuxt/ui/composables'
import type { ContextMenuItem } from '@nuxt/ui'
import { route } from 'ziggy-js'
import type { ActivitySlot, RosterDiscordIds } from '@/Types/ActivityRoster'
import { assignedSlotDiscordId, rosterDiscordUserIds } from '@/utils/rosterDiscordIds'

const discordCopyKey: InjectionKey<(slot: ActivitySlot) => ContextMenuItem[]> = Symbol('roster-discord-copy')

export function provideRosterDiscordCopy(groupSlug: () => string, activityId: () => number, slots: () => ActivitySlot[]) {
    const ids = ref<RosterDiscordIds>({})
    const { t } = useI18n()
    const toast = useToast()

    watch([groupSlug, activityId, () => rosterDiscordUserIds(slots()).join(',')], async ([group, activity, users], _, onCleanup) => {
        ids.value = {}
        if (!users) return
        const controller = new AbortController()
        onCleanup(() => controller.abort())
        try {
            const response = await axios.get<{ discord_user_ids: RosterDiscordIds }>(route('groups.dashboard.activities.roster-discord-ids', { group, activity }), { signal: controller.signal })
            if (!controller.signal.aborted) ids.value = response.data.discord_user_ids
        } catch {
            // Unavailable or unauthorized contact data must never leave a stale copy action.
        }
    }, { immediate: true })

    provide(discordCopyKey, (slot) => {
        const id = assignedSlotDiscordId(slot, ids.value)
        if (!id) return []
        return [{
            label: t('groups.activities.management.roster.copy_discord_id_action'),
            icon: 'i-lucide-copy',
            onSelect: async () => {
                try {
                    await navigator.clipboard.writeText(id)
                    toast.add({ title: t('groups.activities.management.roster.discord_id_copied'), color: 'success', icon: 'i-lucide-copy-check' })
                } catch {
                    toast.add({ title: t('groups.activities.management.roster.discord_id_copy_failed'), color: 'error' })
                }
            },
        }]
    })
}

export function useRosterDiscordCopyMenu() {
    return inject(discordCopyKey, () => [])
}
