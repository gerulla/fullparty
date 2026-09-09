import './bootstrap'
import { ZiggyVue } from 'ziggy-js'
import { createLocalizedApp } from './bootstrap/createLocalizedApp.js'
import { translations } from './lang.js'

createLocalizedApp({
    pages: import.meta.glob('./Pages/**/*.vue'),
    translations,
    loadDefaultLayout: () => import('./Layouts/DefaultLayout.vue'),
    plugins: [ZiggyVue],
    title: title => `${title} FullParty`,
    progress: { color: '#70439b', includeCSS: true, showSpinner: true },
})
