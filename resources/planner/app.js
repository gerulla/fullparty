import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import ui from '@nuxt/ui/vue-plugin'
import { createI18n } from 'vue-i18n'
import { messages, availableLocales, getDefaultLocale } from '../js/lang'

const i18n = createI18n({
    legacy: false,
    locale: getDefaultLocale(),
    fallbackLocale: 'en',
    availableLocales,
    messages,
})

const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })

createInertiaApp({
    title: title => `${title} - FullParty`,
    resolve: name => {
        const page = pages[`./Pages/${name}.vue`]

        if (!page) {
            throw new Error(`Planner page not found: ${name}`)
        }

        return page
    },
    setup({ el, App, props, plugin }) {
        i18n.global.locale.value = props.initialPage?.props?.locale?.current ?? getDefaultLocale()

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ui)
            .use(i18n)
            .mount(el)
    },
})
