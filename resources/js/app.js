import './bootstrap'
import { ZiggyVue } from 'ziggy-js'
import { createLocalizedApp } from './bootstrap/createLocalizedApp.js'
import { translations } from './lang.js'
import ReportFeedbackHost from './components/Shared/Reports/ReportFeedbackHost.vue'
import { initializeAppearance } from './composables/useAppearance'
import PageTransition from './components/Navigation/PageTransition.vue'
import { waitForPageTransition } from './composables/usePageTransition'

createLocalizedApp({
    pages: import.meta.glob('./Pages/**/*.vue'),
    translations,
    loadDefaultLayout: () => import('./Layouts/DefaultLayout.vue'),
    plugins: [ZiggyVue, {
        install(app) {
            app.onUnmount(initializeAppearance())
        },
    }],
    rootComponents: [ReportFeedbackHost, PageTransition],
    beforePageSwap: waitForPageTransition,
    title: title => `${title} FullParty`,
    progress: { color: '#70439b', includeCSS: true, showSpinner: true },
})
