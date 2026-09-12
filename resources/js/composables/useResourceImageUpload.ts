import { inject, type InjectionKey } from 'vue'

export const resourceImageUploadKey: InjectionKey<(file: File, altText?: string, caption?: string) => Promise<string>> = Symbol('resource-image-upload')

export function useResourceImageUpload() {
    const upload = inject(resourceImageUploadKey)
    if (!upload) throw new Error('Resource image uploads require an editing workspace')
    return upload
}
