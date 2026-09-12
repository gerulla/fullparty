import type { ActivitySlot, RosterDiscordIds } from '../Types/ActivityRoster'

type AssignedSlot = Pick<ActivitySlot, 'assigned_character_id' | 'assigned_character'>

export function rosterDiscordUserIds(slots: AssignedSlot[]): number[] {
    return [...new Set(slots.filter(slot => slot.assigned_character_id && slot.assigned_character?.id === slot.assigned_character_id)
        .map(slot => slot.assigned_character?.user_id).filter((id): id is number => typeof id === 'number'))].sort((a, b) => a - b)
}

export function assignedSlotDiscordId(slot: AssignedSlot, ids: RosterDiscordIds): string | null {
    if (!slot.assigned_character_id || slot.assigned_character?.id !== slot.assigned_character_id || !slot.assigned_character.user_id) return null
    const id = ids[String(slot.assigned_character.user_id)]
    return typeof id === 'string' && /^[0-9]{1,32}$/.test(id) ? id : null
}
