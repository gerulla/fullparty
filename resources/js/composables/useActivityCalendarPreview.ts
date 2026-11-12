import { onScopeDispose, ref, shallowRef } from 'vue'
import type { ActivityIndexItem } from '@/Types/ActivityCore'

export function useActivityCalendarPreview() {
    const activity = shallowRef<ActivityIndexItem | null>(null)
    const reference = shallowRef<HTMLElement | null>(null)
    const open = ref(false)
    const focusContent = ref(false)
    let openTimer: ReturnType<typeof setTimeout> | undefined
    let closeTimer: ReturnType<typeof setTimeout> | undefined

    function cancelTimers() {
        clearTimeout(openTimer)
        clearTimeout(closeTimer)
    }

    function close() {
        cancelTimers()
        open.value = false
        focusContent.value = false
    }

    function show(item: ActivityIndexItem, anchor: HTMLElement, focus = false) {
        cancelTimers()
        const reveal = () => {
            if (!anchor.isConnected) return close()
            activity.value = item
            reference.value = anchor
            focusContent.value = focus
            open.value = true
        }
        if (focus) reveal()
        else if (!open.value || reference.value !== anchor) {
            close()
            openTimer = setTimeout(reveal, 500)
        }
    }

    function keepOpen() {
        clearTimeout(closeTimer)
    }

    function scheduleClose() {
        cancelTimers()
        // Allow crossing the gap between the run and its floating preview.
        closeTimer = setTimeout(close, 320)
    }

    onScopeDispose(cancelTimers)

    return { activity, reference, open, focusContent, show, keepOpen, scheduleClose, close }
}
