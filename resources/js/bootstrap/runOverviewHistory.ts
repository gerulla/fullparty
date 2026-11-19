import { router } from '@inertiajs/vue3'

type RunOrigin = { href: string; steps: number }
type BrowserEntries = {
    currentEntry: { index: number } | null
    entries(): { url: string | null }[]
}

function navigationEntries() {
    return (window as Window & { navigation?: BrowserEntries }).navigation
}

function runKey(pathname: string) {
    return pathname.match(/^(?:\/[^/]+)?(\/groups\/[^/]+\/activities\/\d+)(?:\/|$)/)?.[1]
}

function originFrom(href: string | null, key: string, steps: number): RunOrigin | null {
    if (!href) return null
    const url = new URL(href, window.location.href)
    if (url.protocol !== location.protocol || url.origin !== location.origin || runKey(url.pathname) === key) return null
    return { href: url.href, steps }
}

export function runOrigin(runPath: string): RunOrigin | null {
    const key = runKey(runPath)
    if (!key) return null
    const remembered = router.restore<RunOrigin | null>('run-origin:' + key)
    if (remembered === null || remembered?.steps === 0) return remembered

    // Traverse real entries so the originating page also recovers its remembered state and scroll.
    const navigation = navigationEntries()
    if (navigation?.currentEntry) {
        const entries = navigation.entries()
        const current = navigation.currentEntry.index
        for (let index = current - 1; index >= 0; index--) {
            const url = entries[index].url
            if (!url || new URL(url).origin !== window.location.origin) break
            const origin = originFrom(url, key, current - index)
            if (origin) {
                if (remembered && remembered.href !== origin.href) return { ...remembered, steps: 0 }
                return origin
            }
        }
        if (remembered) return { ...remembered, steps: 0 }
    }

    if (remembered) return remembered
    // A new tab can have a referring page but no entry to go back to.
    return originFrom(document.referrer, key, navigation || history.length <= 1 ? 0 : 1)
}

// Keep the source even when a visit replaces it or the browser cannot inspect previous entries.
export function initializeRunOriginTracking() {
    let replacing = false
    const stopStart = router.on('start', ({ detail }) => {
        if (!detail.visit.async) replacing = detail.visit.replace
    })
    const stopUpdate = router.on('beforeUpdate', ({ detail: { page } }) => {
        if (!page.component.startsWith('Groups/Activities/')) return
        const destination = new URL(page.url, location.href)
        const key = runKey(destination.pathname)
        if (!key) return

        const current = new URL(location.href)
        const addsEntry = !replacing && current.pathname + current.search !== destination.pathname + destination.search
        let origin = originFrom(current.href, key, addsEntry ? 1 : 0)
        if (!origin) {
            const previous = runOrigin(key)
            if (previous) origin = { ...previous, steps: previous.steps > 0 ? previous.steps + Number(addsEntry) : 0 }
        }
        page.rememberedState = { ...page.rememberedState, ['run-origin:' + key]: origin }
    })
    return () => { stopStart(); stopUpdate() }
}
