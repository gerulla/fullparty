export const groupNameMaxLength = 255
export const groupSlugMaxLength = 8
export const groupDescriptionMaxLength = 1000

const reservedGroupSlugs = new Set([
    'admin', 'api', 'auth', 'groups', 'group', 'invite', 'invites',
    'login', 'register', 'settings',
])

export const normalizeGroupSlug = (value: string): string => value
    .toLowerCase()
    .replace(/[^a-z]/g, '')
    .slice(0, groupSlugMaxLength)

export function groupSlugValidationError(value: string): 'required' | 'slug_letters_only' | 'slug_reserved' | null {
    if (!value) return 'required'
    if (!/^[a-z]+$/.test(value) || value.length > groupSlugMaxLength) return 'slug_letters_only'
    if (reservedGroupSlugs.has(value)) return 'slug_reserved'
    return null
}
