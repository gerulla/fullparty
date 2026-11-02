/** One delegated listener also covers nested scrollers and portalled dialogs. */
export function initializeScrollbars(): () => void {
    const root = document.documentElement
    const timers = new Map<Element, number>()
    root.setAttribute('data-scrollbar-enhanced', '')

    const onScroll = (event: Event) => {
        const element = event.target === document ? root : event.target
        if (!(element instanceof Element)) return

        if (!element.hasAttribute('data-scrollbar-scrolling')) element.setAttribute('data-scrollbar-scrolling', '')
        window.clearTimeout(timers.get(element))
        timers.set(element, window.setTimeout(() => {
            element.removeAttribute('data-scrollbar-scrolling')
            timers.delete(element)
        }, 900))
    }
    const deactivate = () => root.setAttribute('data-scrollbar-inactive', '')
    const activate = () => root.removeAttribute('data-scrollbar-inactive')
    const onVisibility = () => document.hidden ? deactivate() : activate()

    document.addEventListener('scroll', onScroll, { capture: true, passive: true })
    document.addEventListener('visibilitychange', onVisibility)
    root.addEventListener('pointerleave', deactivate)
    root.addEventListener('pointerenter', activate)
    window.addEventListener('blur', deactivate)
    window.addEventListener('focus', activate)

    return () => {
        document.removeEventListener('scroll', onScroll, true)
        document.removeEventListener('visibilitychange', onVisibility)
        root.removeEventListener('pointerleave', deactivate)
        root.removeEventListener('pointerenter', activate)
        window.removeEventListener('blur', deactivate)
        window.removeEventListener('focus', activate)
        for (const [element, timer] of timers) {
            window.clearTimeout(timer)
            element.removeAttribute('data-scrollbar-scrolling')
        }
        timers.clear()
        root.removeAttribute('data-scrollbar-enhanced')
        root.removeAttribute('data-scrollbar-inactive')
    }
}
