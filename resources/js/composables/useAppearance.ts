import { computed, readonly, ref, watch } from 'vue'
import type { Appearance } from '../Types/Appearance'

export const APPEARANCE_STORAGE_KEY = 'fullparty-appearance'
const appearance = ref<Appearance>('dark')

function readAppearance(): Appearance {
    try {
        return localStorage.getItem(APPEARANCE_STORAGE_KEY) === 'light' ? 'light' : 'dark'
    } catch {
        return document.documentElement.classList.contains('light') ? 'light' : 'dark'
    }
}

/** Installed once by the main app; individual controls share useAppearance(). */
export function initializeAppearance(): () => void {
    appearance.value = readAppearance()
    const stop = watch(appearance, value => {
        const root = document.documentElement
        root.classList.toggle('dark', value === 'dark')
        root.classList.toggle('light', value === 'light')
        root.style.colorScheme = value
    }, { immediate: true, flush: 'sync' })

    const onStorage = (event: StorageEvent) => {
        if (event.key === APPEARANCE_STORAGE_KEY || event.key === null) {
            appearance.value = readAppearance()
        }
    }
    window.addEventListener('storage', onStorage)

    return () => {
        stop()
        window.removeEventListener('storage', onStorage)
    }
}

export function useAppearance() {
    function setAppearance(value: Appearance) {
        appearance.value = value
        try {
            localStorage.setItem(APPEARANCE_STORAGE_KEY, value)
        } catch { /* The current session can still switch when storage is unavailable. */ }
    }

    function toggleAppearance() {
        setAppearance(appearance.value === 'dark' ? 'light' : 'dark')
    }

    return {
        appearance: readonly(appearance),
        isDark: computed(() => appearance.value === 'dark'),
        setAppearance,
        toggleAppearance,
    }
}
