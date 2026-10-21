import { ref } from 'vue'

// A local UI fixture only; no flags or character records are written.
const enabled = ref(false)

export function useAccountFlagsPreview() {
    return { enabled, toggle: () => { enabled.value = !enabled.value } }
}
