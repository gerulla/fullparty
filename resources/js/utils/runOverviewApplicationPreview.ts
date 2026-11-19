import type { AttendeeActivity, RunOverviewViewerParticipation } from '@/Types/ActivityAttendee'
import type { RunApplicationPreviewState, RunApplicationStatus } from '@/Types/RunParticipation'
import { canAcceptActivityApplications, isArchivedActivityStatus } from '@/utils/activityLifecycle'

function applicationStatus(state: Exclude<RunApplicationPreviewState, 'live' | 'not_applied'>): RunApplicationStatus {
    switch (state) {
        case 'assigned':
        case 'filled_in':
        case 'checked_in':
        case 'late':
        case 'missing':
            return 'approved'
        default:
            return state
    }
}

export function previewRunApplication(
    state: Exclude<RunApplicationPreviewState, 'live'>,
    activity: AttendeeActivity,
    applicationUrl: string,
): RunOverviewViewerParticipation | null {
    if (state === 'not_applied') return null

    const kind = state === 'on_bench' ? 'bench' : state === 'filled_in' ? 'fill_in'
        : ['assigned', 'checked_in', 'late'].includes(state) ? 'roster' : null
    const slot = kind && (activity.slots.find(slot => slot.slot_kind === kind && slot.assigned_character)
        ?? activity.slots.find(slot => slot.slot_kind === kind))
    const character = slot?.assigned_character ?? activity.slots.find(slot => slot.assigned_character)?.assigned_character
    const name = character?.name ?? activity.organized_by_character?.name ?? 'Ari Vale'
    const status = applicationStatus(state)
    const canOpen = !isArchivedActivityStatus(activity.status) && state !== 'withdrawn' && state !== 'cancelled'
    const canEdit = canOpen && ['pending', 'approved', 'on_bench', 'assigned', 'filled_in'].includes(state)
        && canAcceptActivityApplications(activity.status, activity.starts_at)

    return {
        application: {
            status, marked_missing: state === 'missing', character_name: name, avatar_url: character ? character.avatar_url : activity.organized_by_character?.avatar_url ?? null,
            world: character?.world ?? null, datacenter: character?.datacenter ?? activity.datacenter,
            can_edit: canEdit, url: canEdit ? applicationUrl : null, view_url: canOpen ? applicationUrl : null,
        },
        placement: kind ? {
            slot_kind: kind, slot_id: slot?.id ?? 0, group_label: slot?.filled_group_label ?? slot?.group_label,
            slot_label: slot?.slot_label, position_in_group: slot?.position_in_group ?? null, character_name: name,
        } : null,
    }
}
