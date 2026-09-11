import { onBeforeUnmount, ref, watch } from 'vue'

export function useResourceAutosave(options: {
    enabled: () => boolean
    changed: () => boolean
    value: () => string
    save: () => Promise<boolean>
    backup: () => void
    identity?: () => string
}) {
    const saving = ref(false)
    let timer: ReturnType<typeof setTimeout> | undefined
    let pending: Promise<boolean> | null = null
    let stopped = false
    let lastAttempt = ''
    let firstChange = 0
    let identity = options.identity?.()
    async function flush(): Promise<boolean> {
        clearTimeout(timer)
        if (pending) { if (!await pending) return false }
        if (!options.changed()) return true
        if (!options.enabled()) return false
        saving.value = true
        lastAttempt = options.value()
        pending = options.save()
        let success = false
        try { success = await pending; return success }
        finally {
            pending = null; saving.value = false; firstChange = 0
            options.backup()
            if ((success || options.value() !== lastAttempt) && options.changed() && !stopped) schedule()
        }
    }
    function schedule() {
        clearTimeout(timer)
        if (stopped || !options.enabled() || !options.changed() || options.value() === lastAttempt) return
        firstChange ||= Date.now()
        timer = setTimeout(() => { void flush() }, Math.max(0, Math.min(1200, 8000 - (Date.now() - firstChange))))
    }
    watch(() => [options.value(), options.enabled(), options.changed(), options.identity?.()], () => {
        if (identity !== options.identity?.()) { identity = options.identity?.(); lastAttempt = ''; firstChange = 0 }
        options.backup()
        schedule()
    }, { flush: 'sync' })
    onBeforeUnmount(() => { stopped = true; clearTimeout(timer); options.backup() })
    return { saving, flush }
}
