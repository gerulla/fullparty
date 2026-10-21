import { inject, provide, ref, shallowRef, type InjectionKey } from 'vue'
import type { DevelopmentPreviewMenu } from '@/Types/DevelopmentPreview'

const menuKey: InjectionKey<DevelopmentPreviewMenu> = Symbol('development-preview-menu')

export function provideDevelopmentPreviewMenu() {
    const open = ref(false)
    const menu: DevelopmentPreviewMenu = {
        open,
        controls: shallowRef(null),
        close: () => { open.value = false },
    }
    provide(menuKey, menu)
    return menu
}

export function useDevelopmentPreviewMenu() {
    const menu = inject(menuKey)
    if (!menu) throw new Error('Development preview controls require V2Layout.')
    return menu
}
