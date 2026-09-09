import { pageNamespaces } from './pageNamespaces.js'

export function createPageResolver({ pages, application, composer, translations, getLocale, loadDefaultLayout }) {
    return async name => {
        const importer = pages[`./Pages/${name}.vue`]
        if (!importer) throw new Error(`Inertia page not found: ${name}`)
        const [module] = await Promise.all([
            importer(),
            translations.load(composer, getLocale(name), pageNamespaces(name, application)),
        ])
        const component = module.default ?? module
        if (!component.layout && loadDefaultLayout) {
            const layout = await loadDefaultLayout()
            component.layout = layout.default ?? layout
        }
        return module
    }
}
