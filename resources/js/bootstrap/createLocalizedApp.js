import { createApp, h } from 'vue'
import { createInertiaApp, router } from '@inertiajs/vue3'
import { getInitialPageFromDOM } from '@inertiajs/core'
import { createI18n } from 'vue-i18n'
import ui from '@nuxt/ui/vue-plugin'
import { createPageResolver } from '../i18n/createPageResolver.js'
import { normalizeLocale, supportedLocales } from '../i18n/createTranslationLoader.js'
import enLoading from '../../../lang/en/loading.json'
import deLoading from '../../../lang/de/loading.json'
import frLoading from '../../../lang/fr/loading.json'
import jaLoading from '../../../lang/ja/loading.json'

// These two tiny messages must remain available when network requests for page dictionaries fail.
const loadingMessages = { en: enLoading, de: deLoading, fr: frLoading, ja: jaLoading }

export async function createLocalizedApp({ pages, translations, application = 'main', loadDefaultLayout, plugins = [], title, progress }) {
    const initialPage = getInitialPageFromDOM('app', false)
    const initialLocale = normalizeLocale(initialPage.props.locale?.current)
    const i18n = createI18n({ legacy: false, locale: initialLocale, fallbackLocale: 'en', messages: {} })
    let pendingPage = initialPage
    const stop = router.on('beforeUpdate', event => { pendingPage = event.detail.page })
    const resolve = createPageResolver({
        pages, application, translations, composer: i18n.global, loadDefaultLayout,
        getLocale(name) {
            const locale = pendingPage?.component === name ? pendingPage.props.locale?.current : null
            pendingPage = null
            const urlLocale = window.location.pathname.split('/')[1]
            return normalizeLocale(locale ?? (supportedLocales.includes(urlLocale) ? urlLocale : i18n.global.locale.value))
        },
    })

    try {
        return await createInertiaApp({
            page: initialPage, resolve, title, progress,
            setup({ el, App, props, plugin }) {
                const app = createApp({ render: () => h(App, props) }).use(plugin).use(ui).use(i18n)
                for (const extra of plugins) app.use(extra)
                app.mount(el)
            },
        })
    } catch (error) {
        stop()
        const root = document.getElementById('app')
        const notice = document.createElement('p')
        const messages = loadingMessages[initialLocale]
        notice.textContent = messages.failed
        const retry = document.createElement('button')
        retry.type = 'button'
        retry.textContent = messages.retry
        retry.addEventListener('click', () => window.location.reload())
        root.replaceChildren(notice, retry)
        root.setAttribute('role', 'alert')
        console.error('Unable to initialize FullParty', error)
    }
}
