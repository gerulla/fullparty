import './bootstrap';
import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import DefaultLayout from './Layouts/DefaultLayout.vue';
import ui from '@nuxt/ui/vue-plugin'
import { ZiggyVue } from 'ziggy-js';
import { createI18n } from 'vue-i18n'
import { messages, availableLocales, getDefaultLocale } from './lang'

const i18n = createI18n({
    legacy: false,
    locale: getDefaultLocale(),
    fallbackLocale: 'en',
    availableLocales,
    messages,
})

createInertiaApp({
    title: title => `${title} FullParty`,
    resolve: name => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
        let page = pages[`./Pages/${name}.vue`];
        page.default.layout = page.default.layout || DefaultLayout;
        return page;
    },
    setup({ el, App, props, plugin }) {
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