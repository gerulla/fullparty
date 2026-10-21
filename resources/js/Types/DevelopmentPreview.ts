import type { Ref, ShallowRef } from 'vue'

export type DevelopmentPreviewControls = {
    active?: boolean
    loading?: boolean
    disabled?: boolean
}

export type DevelopmentPreviewMenu = {
    open: Ref<boolean>
    controls: ShallowRef<Readonly<DevelopmentPreviewControls> | null>
    close: () => void
}
