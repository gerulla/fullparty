export type ResourceLibraryVisibility = 'public' | 'private'
export type ResourceLibraryCustomization = {
    title: string
    introduction: string
    banner_image_id: string | null
    banner_focal_x: number
    banner_focal_y: number
    logo_image_id: string | null
    accent_color: string
    appearance: 'light' | 'dark' | 'system'
    links: { label: string; url: string }[]
    sharing_image_id: string | null
}
export type ResourceLibrarySettings = { visibility: ResourceLibraryVisibility; customization?: ResourceLibraryCustomization }
export type ResourceLibrarySettingsErrors = Record<string, string>

export type ResourceReaderSummary = {
    source_type?: 'holster'; holster_id?: number
    id: number; slug: string; collection_id: number | null; is_home: boolean
    title: string; description: string; tags: string[]; activity_type_ids: number[]
    metadata_image_id: string | null; author: ResourceAuthorData | null
    access_level: 'everyone' | 'moderator' | 'admin'; published_at: string | null
}
export type ResourceReaderDocument = ResourceReaderSummary & {
    report_url: string
    holster?: ResourceHolsterLoadout
    legacy_body_html?: string | null
    commands: ResourceReaderCommand[]
    body: RichTextDocument
    images: import('./ResourceImages').ResourceReaderImage[]
    related_resources: ResourceReaderSummary[]
    linked_resources: ResourceReaderSummary[]
    history: ResourceReaderHistory
}
export type ResourceReaderCommand = { name: string; title: string }
export type ResourceReaderSection = { id: string; title: string; depth: number }
export type ResourceReaderHistoryEntry = { id: number | string; kind?: 'edit' | 'publication'; editor: { name?: string; avatar_url?: string | null }; summary: string | null; created_at: string | null }
export type ResourceReaderHistory = { data: ResourceReaderHistoryEntry[]; has_more: boolean }
export type ResourceReaderCollection = ResourceCollectionData & { icon: string | null; resource_count: number }
export type ResourceReaderFilters = { q?: string | null; tag?: string | null; activity_type_id?: number | null; page?: number }
export type ResourceReaderSeo = { title: string; description: string; url: string; image: string | null; type: string }
export type ResourceReaderPage = {
    group: { name: string; slug: string; description?: string | null; datacenter?: string | null; profile_picture_url?: string | null; banner_image_url?: string | null }
    library: ResourceLibrary
    collections: ResourceReaderCollection[]
    resources: { data: ResourceReaderSummary[]; current_page: number; last_page: number; per_page: number; total: number }
    resource?: ResourceReaderDocument
    filters: ResourceReaderFilters
    reader: { selected_collection_id: number | null; activities: { id: number; name: Record<string, string> }[]; recent_resources: ResourceReaderSummary[]; pinned_resources: ResourceReaderSummary[] }
}

export type ResourceLibrary = {
    visibility: ResourceLibraryVisibility
    public_url?: string | null
    customization: Pick<ResourceLibraryCustomization, 'title' | 'introduction'> & Partial<{ [K in keyof ResourceLibraryCustomization]: ResourceLibraryCustomization[K] | null }> & { start_resource_id?: number | null }
    storage?: { used_bytes: number, quota_bytes: number }
}

export type ResourceManagementGroup = {
    id: number
    name: string
    slug: string
    permissions: { can_update_group_settings: boolean }
}

export type ResourceCollectionData = { id: number; parent_id: number | null; name: string; slug: string; icon?: string | null; sort_order?: number }
export type ResourceAuthorData = { id?: number | null; name: string; avatar_url?: string | null }
export type ResourceReaderUrls = { public: string | null; group: string }
export type ResourceSnapshot = {
    collection_id?: number | null
    title: string; slug?: string; description?: string; body?: RichTextDocument; access_level: 'everyone' | 'moderator' | 'admin'
    tags: string[]; activity_type_ids: number[]; author: ResourceAuthorData | null; metadata_image_id?: string | null
    commands?: ResourceCommandData[]
}
export type ResourceCommandData = { name: string; enabled: boolean; updated_at?: string; embed?: {
        title?: string; description?: string; color?: number; url?: string; timestamp?: string
        author?: { name: string; url?: string; icon_url?: string }
        image?: { url?: string; asset_id?: string }; thumbnail?: { url?: string; asset_id?: string }
        fields?: { name: string; value: string; inline: boolean }[]
    } }
export type ResourceRevisionData = {
    id: number; snapshot: ResourceSnapshot; editor: ResourceAuthorData; summary: string
    state: string; created_at: string; published_at: string | null
}
export type ResourceSummaryData = {
    moderation_hidden?: boolean
    holster_id?: number | null
    id: number; uuid?: string; collection_id: number | null; is_home?: boolean; slug: string; status: string; version: number; sort_order: number; updated_at: string
    has_unpublished_changes: boolean
    can_publish: boolean
    is_pinned: boolean
    reader_urls: ResourceReaderUrls | null
    summary: ResourceSnapshot; commands: ResourceCommandData[]
}
export type ResourceDetailData = Omit<ResourceSummaryData, 'summary' | 'commands'> & {
    inherited_body_html?: string | null
    working_copy: ResourceSnapshot | null; published: ResourceSnapshot | null
    history: { id: number | string; kind?: 'edit' | 'publication'; editor: ResourceAuthorData; summary: string; created_at: string }[]
}
export type ResourceWorkspaceData = {
    holsters?: ResourceHolsterSettings
    pin_limit: number
    editor_user_id?: number
    embed_context?: { group_icon_url: string | null; public_base_url: string }
    resources: ResourceSummaryData[]
    authors: ResourceAuthorData[]
    activities: { id: number; name: Record<string, string> }[]
    access_levels: ResourceSnapshot['access_level'][]
}

export type ResourceHolsterSettings = { collection_id: number | null; active_count: number; resources: ResourceReaderSummary[] }
export type ResourceHolsterLoadout = import('./HolsterPlanner').HolsterLoadout & {
    prepop: import('./HolsterPlanner').HolsterLoadout | null
    refills: import('./HolsterPlanner').HolsterLoadout[]
}

export type ResourceMutationData = {
    id: number; version: number; editing_token?: string
    editing_expires_at: string | null; resource?: ResourceDetailData
}
import type { RichTextDocument } from './RichText'
