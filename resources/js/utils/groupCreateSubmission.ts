import type { GroupCreateArtwork, GroupCreateDraft, GroupCreateField, GroupCreateStep } from '@/Types/Groups'
import { normalizeGroupSlug } from '@/utils/groupIdentity'
import { sanitizeMultilineText, sanitizeSingleLineText } from '@/utils/textInputSanitizer'

export const groupCreateSteps: GroupCreateStep[] = ['type', 'datacenter', 'details', 'artwork', 'fit', 'focus', 'activity', 'joining']

const fieldSteps: Record<GroupCreateField, GroupCreateStep> = {
    group_type: 'type', datacenter: 'datacenter', name: 'details', slug: 'details', description: 'details',
    profile_picture: 'artwork', banner_image: 'artwork', discord_invite_url: 'joining',
    experience_expectation: 'fit', voice_expectation: 'fit', preferred_languages: 'fit',
    primary_focuses: 'focus', tags: 'focus', active_timezone: 'activity', active_days: 'activity',
    active_start_time: 'activity', active_end_time: 'activity', join_mode: 'joining', is_visible: 'joining',
}

export function groupCreateErrorStep(field: string): GroupCreateStep {
    return fieldSteps[field.split('.')[0] as GroupCreateField] ?? 'joining'
}

export function firstGroupCreateErrorStep(errors: Record<string, string>): GroupCreateStep {
    return groupCreateSteps.find(step => Object.keys(errors).some(field => groupCreateErrorStep(field) === step)) ?? 'joining'
}

export function groupCreatePayload(draft: GroupCreateDraft, artwork: GroupCreateArtwork) {
    return {
        ...draft.identity,
        name: sanitizeSingleLineText(draft.identity.name),
        slug: normalizeGroupSlug(draft.identity.slug),
        description: sanitizeMultilineText(draft.identity.description),
        group_type: draft.groupType,
        datacenter: draft.datacenter,
        ...draft.fit,
        ...draft.focus,
        ...draft.joining,
        active_timezone: draft.activity.active_timezone,
        active_days: [...draft.activity.active_days],
        active_start_time: draft.activity.active_start_time,
        active_end_time: draft.activity.active_end_time,
        ...artwork,
    }
}
