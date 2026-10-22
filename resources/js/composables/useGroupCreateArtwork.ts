import { computed, onScopeDispose, reactive } from 'vue'
import type { GroupCreateArtwork, GroupCreateArtworkController, GroupCreateArtworkField } from '@/Types/Groups'
import { groupImageMaxBytes, validateGroupProfilePictureFile } from '@/utils/groupProfilePictureValidation'

export function useGroupCreateArtwork(): GroupCreateArtworkController {
    const files = reactive<GroupCreateArtwork>({ profile_picture: null, banner_image: null })
    const previewUrls = reactive<Record<GroupCreateArtworkField, string | null>>({ profile_picture: null, banner_image: null })
    const errors = reactive<Record<GroupCreateArtworkField, string | null>>({ profile_picture: null, banner_image: null })
    const checking = reactive({ profile_picture: false, banner_image: false })
    const versions = { profile_picture: 0, banner_image: 0 }
    const pendingUrls = new Set<string>()
    let disposed = false

    async function selectFile(field: GroupCreateArtworkField, file: File | null | undefined) {
        const version = ++versions[field]
        if (previewUrls[field]) URL.revokeObjectURL(previewUrls[field])
        previewUrls[field] = null
        files[field] = file ?? null
        errors[field] = null
        checking[field] = false
        if (!file) return

        if (!validateGroupProfilePictureFile(file).isValid) {
            errors[field] = 'groups.index.create_modal.validation.image_invalid_format'
            return
        }
        if (file.size > groupImageMaxBytes) {
            errors[field] = 'groups.index.create_modal.validation.image_too_large'
            return
        }

        const url = URL.createObjectURL(file)
        pendingUrls.add(url)
        checking[field] = true
        let retained = false
        try {
            const image = new Image()
            image.src = url
            await image.decode()
            if (disposed || versions[field] !== version) return
            previewUrls[field] = url
            retained = true
        } catch {
            if (!disposed && versions[field] === version) {
                errors[field] = 'groups.index.create_flow.artwork.unreadable_image'
            }
        } finally {
            pendingUrls.delete(url)
            if (!retained) URL.revokeObjectURL(url)
            if (!disposed && versions[field] === version) checking[field] = false
        }
    }

    onScopeDispose(() => {
        disposed = true
        for (const url of [...Object.values(previewUrls), ...pendingUrls]) {
            if (url) URL.revokeObjectURL(url)
        }
        pendingUrls.clear()
    })

    return reactive({
        files,
        previewUrls,
        errors,
        checking,
        canContinue: computed(() => !Object.values(errors).some(Boolean) && !Object.values(checking).some(Boolean)),
        selectFile,
    })
}
