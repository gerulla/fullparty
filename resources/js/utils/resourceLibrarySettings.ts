import type { ResourceLibrary, ResourceLibraryCustomization, ResourceLibrarySettings, ResourceLibraryVisibility } from '../Types/GroupResources'

export function resourceLibraryCustomization(library: ResourceLibrary): ResourceLibraryCustomization {
    const saved = library.customization
    return {
        title: saved.title, introduction: saved.introduction,
        banner_image_id: saved.banner_image_id ?? null, logo_image_id: saved.logo_image_id ?? null, sharing_image_id: saved.sharing_image_id ?? null,
        banner_focal_x: saved.banner_focal_x ?? 50, banner_focal_y: saved.banner_focal_y ?? 50,
        accent_color: saved.accent_color ?? '#8457b0', appearance: saved.appearance ?? 'system',
        links: (saved.links ?? []).map(link => ({ ...link })),
    }
}

export function resourceLibrarySettingsPayload(visibility: ResourceLibraryVisibility, customization: ResourceLibraryCustomization): ResourceLibrarySettings {
    if (visibility === 'private') return { visibility }
    return { visibility, customization: {
        ...customization,
        banner_image_id: customization.banner_image_id || null,
        logo_image_id: customization.logo_image_id || null,
        sharing_image_id: customization.sharing_image_id || null,
        links: customization.links.map(link => ({ label: link.label.trim(), url: link.url.trim() })),
    } }
}
