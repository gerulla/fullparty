export type ResourceLibraryVisibility = 'public' | 'private'

export type ResourceLibrary = {
    visibility: ResourceLibraryVisibility
    customization: Record<string, unknown>
    storage?: { used_bytes: number, quota_bytes: number }
}

export type ResourceManagementGroup = {
    id: number
    name: string
    slug: string
    permissions: { can_update_group_settings: boolean }
}
