import { computed, onBeforeMount, onBeforeUnmount, readonly, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import type { UiTheme } from '../Types/Appearance'

const activeTheme = ref<UiTheme>('classic')

function applyTheme(theme: UiTheme) {
    activeTheme.value = theme
    document.documentElement.dataset.uiTheme = theme
}

export function useUiTheme(layoutTheme?: UiTheme) {
    const page = usePage()
    const canPreviewTheme = computed(() => page.props.theme_preview_enabled === true)

    // The document scope includes Nuxt UI menus/modals teleported to the body.
    if (layoutTheme) {
        onBeforeMount(() => applyTheme(layoutTheme))
        onBeforeUnmount(() => applyTheme('classic'))
    }

    function previewTheme(theme: UiTheme) {
        if (canPreviewTheme.value) applyTheme(theme)
    }

    return { theme: readonly(activeTheme), canPreviewTheme, previewTheme }
}
