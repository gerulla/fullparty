import { nextTick, onBeforeUnmount, onMounted } from 'vue'
import type { Ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { createPageRippleRenderer } from '@/utils/pageRippleCanvas'
import type { PageRippleOrigin } from '@/Types/NavigationTransition'

let covered: Promise<void> | undefined

/** The resolver waits only for the outgoing cover, never for the network or reveal. */
export async function waitForPageTransition() {
    await covered
}

export function usePageTransition(canvas: Ref<HTMLCanvasElement | null>) {
    let dispose: (() => void) | undefined

    onMounted(() => {
        if (!canvas.value) return
        const renderer = createPageRippleRenderer(canvas.value)
        if (!renderer) {
            document.documentElement.classList.remove('page-ripple-arriving')
            delete document.documentElement.dataset.pageRipple
            return
        }

        const motion = window.matchMedia('(prefers-reduced-motion: reduce)')
        let generation = 0
        let navigationSequence = 0
        let active = false
        let revealing = false
        let frame = 0
        let watchdog: ReturnType<typeof setTimeout> | undefined
        let releaseCover: (() => void) | undefined
        let visitUrl: URL | undefined
        let currentPath = window.location.pathname
        let origin: PageRippleOrigin | undefined

        function clearArrival() {
            document.documentElement.classList.remove('page-ripple-arriving')
            delete document.documentElement.dataset.pageRipple
        }

        function reset() {
            generation++
            cancelAnimationFrame(frame)
            clearTimeout(watchdog)
            releaseCover?.()
            releaseCover = undefined
            covered = undefined
            visitUrl = undefined
            active = false
            revealing = false
            renderer!.clear()
            clearArrival()
        }

        function animate(duration: number, reveal: boolean, complete: () => void) {
            const token = generation
            const started = performance.now()
            const tick = (now: number) => {
                if (token !== generation) return
                const progress = Math.min(1, (now - started) / duration)
                try {
                    renderer!.draw(progress, reveal)
                } catch {
                    reset()
                    return
                }
                if (progress < 1) frame = requestAnimationFrame(tick)
                else complete()
            }
            frame = requestAnimationFrame(tick)
        }

        function begin(url?: URL) {
            navigationSequence++
            reset()
            if (motion.matches || document.visibilityState === 'hidden') return
            active = true
            visitUrl = url
            try {
                origin = renderer!.start()
            } catch {
                reset()
                return
            }
            covered = new Promise(resolve => { releaseCover = resolve })
            animate(460, false, () => { releaseCover?.(); releaseCover = undefined })
            // Failed requests, missing events, or a suspended tab must never leave a curtain behind.
            watchdog = setTimeout(reset, 8000)
        }

        async function reveal() {
            if (!active || revealing) return
            revealing = true
            const token = generation
            await covered
            await nextTick()
            if (token !== generation) return
            animate(540, true, reset)
        }

        const stopBefore = router.on('before', event => {
            const visit = event.detail.visit
            if (visit.method !== 'get' || visit.async || visit.prefetch || visit.preserveUrl || visit.only.length
                || visit.except.length || visit.reset.length || visit.url.origin !== window.location.origin
                || visit.url.pathname === currentPath) return
            // Let other navigation guards (including unsaved edits) cancel first.
            queueMicrotask(() => {
                if (!event.defaultPrevented) begin(visit.url)
            })
        })
        const stopNavigate = router.on('navigate', () => {
            currentPath = window.location.pathname
            void reveal()
        })
        const stopFinish = router.on('finish', event => {
            if (!active || event.detail.visit.url !== visitUrl) return
            currentPath = window.location.pathname
            if (event.detail.visit.cancelled || event.detail.visit.interrupted) reset()
            else void reveal()
        })
        const stopSuccess = router.on('success', () => {
            // Cached visits with replace:true can complete without a navigate event.
            if (window.location.pathname !== currentPath) {
                currentPath = window.location.pathname
                void reveal()
            }
        })
        const stopInvalid = router.on('invalid', reset)
        const stopException = router.on('exception', reset)
        const onHistory = () => {
            if (window.location.pathname !== currentPath) begin()
        }
        const onMotion = () => { if (motion.matches) reset() }
        const onVisibility = () => { if (document.visibilityState === 'hidden') reset() }
        const onNativeClick = async (event: MouseEvent) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey
                || event.altKey || motion.matches || !(event.target instanceof Element)) return
            const link = event.target.closest<HTMLAnchorElement>('a[href]')
            if (!link || link.hasAttribute('download') || link.hasAttribute('data-no-transition')
                || link.getAttribute('aria-disabled') === 'true' || (link.target && link.target !== '_self')) return
            const url = new URL(link.href, window.location.href)
            if (url.origin !== window.location.origin || url.pathname === currentPath
                || /\.(?:ics|csv|zip|pdf|png|jpe?g|webp|svg)$/i.test(url.pathname)
                || /\/(?:auth|logout|export|download|calendar)(?:\/|$)/i.test(url.pathname)) return
            // Native links retain native navigation; only their visual handoff is delayed.
            event.preventDefault()
            begin(url)
            const navigation = navigationSequence
            await covered
            if (navigation !== navigationSequence) return
            try {
                sessionStorage.setItem('fullparty-page-ripple', JSON.stringify({
                    ...origin, color: renderer!.color(), href: url.href, at: Date.now(),
                }))
            } catch { /* Navigation must still work when browser storage is unavailable. */ }
            window.location.assign(url.href)
            clearTimeout(watchdog)
            watchdog = setTimeout(reset, 1500)
        }
        window.addEventListener('popstate', onHistory)
        window.addEventListener('resize', reset)
        window.addEventListener('pagehide', reset)
        document.addEventListener('visibilitychange', onVisibility)
        document.addEventListener('click', onNativeClick)
        motion.addEventListener('change', onMotion)

        // app.blade.php paints the arrival curtain before the JS bundle is ready.
        const arrival = document.documentElement.dataset.pageRipple
        if (arrival) {
            if (!motion.matches) {
                try {
                    renderer.start(JSON.parse(arrival))
                    renderer.draw(1, false)
                    active = true
                    void reveal()
                } catch { reset() }
            }
            clearArrival()
        }

        dispose = () => {
            navigationSequence++
            reset()
            stopBefore()
            stopNavigate()
            stopFinish()
            stopSuccess()
            stopInvalid()
            stopException()
            window.removeEventListener('popstate', onHistory)
            window.removeEventListener('resize', reset)
            window.removeEventListener('pagehide', reset)
            document.removeEventListener('visibilitychange', onVisibility)
            document.removeEventListener('click', onNativeClick)
            motion.removeEventListener('change', onMotion)
        }
    })

    onBeforeUnmount(() => dispose?.())
}
