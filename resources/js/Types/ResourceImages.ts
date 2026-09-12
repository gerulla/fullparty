import type { Ref } from 'vue'

export type ResourceImage = {
    uuid: string; name: string; url: string; mime_type: string
    width: number; height: number; size_bytes: number
    alt_text: string; caption: string | null; created_at: string
    uploader: string | null; in_use: boolean
}
export type ResourceImagePage = { data: ResourceImage[]; current_page: number; per_page: number; total: number; last_page: number }
export type ResourceImageContext = { groupSlug: () => string; resourceId?: () => string | null; libraryOnly?: boolean; changed: () => void }
export type ResourceImageUpload = (file: File, altText?: string, caption?: string) => Promise<string>
export type ResourceImageSelection = Pick<ResourceImage, 'url' | 'name' | 'alt_text' | 'caption'>
export type ResourceImageMetadata = { name: string; alt_text: string; caption: string }
export type ResourceImagesController = {
    state: { items: ResourceImage[]; query: string; type: string; page: number; total: number; perPage: number; loading: boolean; busy: boolean; error: string }
    selected: Ref<ResourceImage | null>
    load: (page?: number) => Promise<void>
    search: () => void
    save: (image: ResourceImage, values: ResourceImageMetadata) => Promise<boolean>
    remove: () => Promise<boolean>
    upload: ResourceImageUpload
}
