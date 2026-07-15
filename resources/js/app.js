import './bootstrap';
import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import DefaultLayout from './Layouts/DefaultLayout.vue';
import ui from '@nuxt/ui/vue-plugin'
import { config as configureMarkdownEditor, XSSPlugin } from 'md-editor-v3'
import 'md-editor-v3/lib/style.css'
import { ZiggyVue } from 'ziggy-js';
import { createI18n } from 'vue-i18n'
import { messages, availableLocales, getDefaultLocale } from './lang'

configureMarkdownEditor({
    markdownItPlugins(plugins) {
        return [
            ...plugins,
            {
                type: 'xss',
                plugin: XSSPlugin,
                options: {},
            },
        ]
    },
})

const i18n = createI18n({
    legacy: false,
    locale: getDefaultLocale(),
    fallbackLocale: 'en',
    availableLocales,
    messages,
})

const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })

createInertiaApp({
    title: title => `${title} FullParty`,
    resolve: name => {
        const page = pages[`./Pages/${name}.vue`];

        if (!page) {
            throw new Error(`Inertia page not found: ${name}`)
        }

        page.default.layout = page.default.layout || DefaultLayout;
        return page;
    },
    setup({ el, App, props, plugin }) {
        i18n.global.locale.value = props.initialPage?.props?.locale?.current ?? getDefaultLocale()

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ui)
            .use(ZiggyVue)
            .use(i18n)
            .mount(el)
    },
    progress: {
        color: '#70439b',
        includeCSS: true,
        showSpinner: true,
    }
})
