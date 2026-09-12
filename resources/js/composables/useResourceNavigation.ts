import { router } from '@inertiajs/vue3'
import { onBeforeUnmount, onMounted } from 'vue'

export function useResourceNavigation(changed: () => boolean, flush: () => Promise<boolean>) {
    let stop: (() => void) | undefined
    let replaying = false
    let navigating = false
    onMounted(() => {
        stop = router.on('before', event => {
            const visit = event.detail.visit
            if (replaying || !changed() || (visit.only.length && visit.only.every(key => key === 'library'))) return
            event.preventDefault()
            if (navigating) return
            navigating = true
            void flush().then(saved => {
                if (saved) {
                    replaying = true
                    router.visit(visit.url, { ...visit })
                    replaying = false
                }
            }).finally(() => { navigating = false })
        })
    })
    onBeforeUnmount(() => stop?.())
}
