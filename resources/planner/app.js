import { createLocalizedApp } from '../js/bootstrap/createLocalizedApp.js'
import { translations } from './lang.js'

createLocalizedApp({
    application: 'planner',
    pages: import.meta.glob('./Pages/**/*.vue'),
    translations,
    title: title => `${title} - FullParty`,
})
