import { createApp, h } from 'vue'
import { createInertiaApp, router } from '@inertiajs/vue3'
import { getInitialPageFromDOM } from '@inertiajs/core'
import { createI18n } from 'vue-i18n'
import ui from '@nuxt/ui/vue-plugin'
import { createPageResolver } from '../i18n/createPageResolver.js'
import { normalizeLocale, supportedLocales } from '../i18n/createTranslationLoader.js'

const loadErrors = import.meta.glob('../../../lang/*/loading.json', { import: 'default' })

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
        // Keep a usable English fallback even when the translation server is unreachable.
        notice.textContent = 'Unable to load this page. Check your connection and try again.'
        const retry = document.createElement('button')
        retry.type = 'button'
        retry.textContent = 'Try again'
        retry.addEventListener('click', () => window.location.reload())
        root.replaceChildren(notice, retry)
        root.setAttribute('role', 'alert')
        console.error('Unable to initialize FullParty', error)
        loadErrors[`../../../lang/${initialLocale}/loading.json`]?.().then(messages => {
            notice.textContent = messages.failed
            retry.textContent = messages.retry
        }).catch(() => {})
    }
}
