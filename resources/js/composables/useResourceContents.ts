import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { ResourceReaderSection } from '@/Types/GroupResources'

export function useResourceContents(root: () => HTMLElement | null, sections: () => ResourceReaderSection[]) {
    const activeId = ref('')
    let frame = 0
    let pendingHash: string | null = null
    let linkedPosition: { id: string; y: number } | null = null
    let mutations: MutationObserver | undefined
    let resize: ResizeObserver | undefined
    let stop = () => {}
    function readHash() {
        try { pendingHash = decodeURIComponent(window.location.hash.slice(1)) || null }
        catch { pendingHash = null }
    }
    function update() {
        frame = 0
        const container = root()
        if (!container) return
        const targets = sections().flatMap(section => {
            const element = document.getElementById(section.id)
            return element && container.contains(element) ? [{ id: section.id, element }] : []
        })
        const linked = targets.find(target => target.id === pendingHash)
        // The rich-text editor mounts its headings after the surrounding sections.
        if (linked && targets.length === sections().length) {
            pendingHash = null
            linked.element.scrollIntoView({ block: 'start', behavior: 'auto' })
            linked.element.focus({ preventScroll: true })
            linkedPosition = { id: linked.id, y: window.scrollY }
        }
        let current = targets[0]?.id ?? ''
        for (const target of targets) {
            if (target.element.getBoundingClientRect().top <= 120) current = target.id
        }
        if (window.scrollY > 0 && window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) current = targets.at(-1)?.id ?? current
        // Short final sections cannot always reach the top of the viewport.
        if (linkedPosition && Math.abs(window.scrollY - linkedPosition.y) < 2) current = linkedPosition.id
        else linkedPosition = null
        activeId.value = current
    }
    function schedule() { if (!frame) frame = requestAnimationFrame(update) }
    function hashChanged() { readHash(); schedule() }
    onMounted(() => {
        stop = watch(() => [root(), sections()] as const, ([container]) => {
            mutations?.disconnect(); resize?.disconnect()
            readHash(); linkedPosition = null; activeId.value = ''; schedule()
            if (!container) return
            mutations = new MutationObserver(schedule)
            mutations.observe(container, { childList: true, subtree: true })
            resize = new ResizeObserver(schedule)
            resize.observe(container)
        }, { immediate: true, flush: 'post' })
        window.addEventListener('scroll', schedule, { passive: true })
        window.addEventListener('resize', schedule)
        window.addEventListener('hashchange', hashChanged)
    })
    onBeforeUnmount(() => {
        stop(); mutations?.disconnect(); resize?.disconnect(); cancelAnimationFrame(frame)
        window.removeEventListener('scroll', schedule)
        window.removeEventListener('resize', schedule)
        window.removeEventListener('hashchange', hashChanged)
    })
    return { activeId }
}
