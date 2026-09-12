import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { ResourceLibrary } from '@/Types/GroupResources'

export function usePublicResourceAppearance(getLibrary: () => ResourceLibrary) {
    const choice = ref<'light' | 'dark' | null>(null)
    const storageKey = 'resource-reader-appearance'
    const isDark = computed(() => (choice.value ?? getLibrary().customization.appearance) !== 'light')
    let restore = () => {}
    onMounted(() => {
        try {
            const saved = localStorage.getItem(storageKey)
            if (saved === 'light' || saved === 'dark') choice.value = saved
        } catch { /* The toggle still works when storage is unavailable. */ }
        const elements = [document.documentElement, document.body]
        const previous = elements.map(element => ({ dark: element.classList.contains('dark'), light: element.classList.contains('light'), scheme: element.style.colorScheme, background: element.style.backgroundColor, color: element.style.color }))
        const stop = watch(isDark, dark => {
            elements.forEach(element => {
                element.classList.toggle('dark', dark)
                element.classList.toggle('light', !dark)
                element.style.colorScheme = dark ? 'dark' : 'light'
                element.style.backgroundColor = 'var(--ui-bg)'
                element.style.color = 'var(--ui-text)'
            })
        }, { immediate: true })
        restore = () => {
            stop()
            elements.forEach((element, index) => {
                element.classList.toggle('dark', previous[index].dark)
                element.classList.toggle('light', previous[index].light)
                element.style.colorScheme = previous[index].scheme
                element.style.backgroundColor = previous[index].background
                element.style.color = previous[index].color
            })
        }
    })
    onBeforeUnmount(() => restore())
    function toggle() {
        choice.value = isDark.value ? 'light' : 'dark'
        try { localStorage.setItem(storageKey, choice.value) } catch { /* Storage is optional. */ }
    }
    return { isDark, toggle }
}
