export const supportedLocales = ['en', 'de', 'fr', 'ja']
export const defaultLocale = 'en'
export const normalizeLocale = locale => supportedLocales.includes(locale) ? locale : defaultLocale

export function createTranslationLoader(files) {
    const loaders = new Map()
    for (const [path, loader] of Object.entries(files)) {
        const match = path.match(/\/lang\/([^/]+)\/(.+)\.json$/)
        if (match) loaders.set(`${match[1]}/${match[2]}`, loader)
    }
    const requests = new Map()
    const installed = new WeakMap()

    function loadFile(locale, namespace) {
        const key = `${locale}/${namespace}`
        if (requests.has(key)) return requests.get(key)
        const importer = loaders.get(key)
        if (!importer) {
            if (locale !== defaultLocale) return Promise.resolve(null)
            return Promise.reject(new Error(`Missing fallback translations: ${key}`))
        }
        const request = Promise.resolve().then(importer).then(module => module.default ?? module)
            .catch(error => {
                requests.delete(key)
                throw error
            })
        requests.set(key, request)
        return request
    }

    async function load(composer, locale, namespaces) {
        const locales = [...new Set([defaultLocale, normalizeLocale(locale)])]
        const installedKeys = installed.get(composer) ?? new Set()
        installed.set(composer, installedKeys)
        await Promise.all(locales.flatMap(code => [...new Set(namespaces)].map(async namespace => {
            const key = `${code}/${namespace}`
            if (installedKeys.has(key)) return
            const content = await loadFile(code, namespace)
            if (!installedKeys.has(key) && content !== null) {
                const message = namespace.split('/').reduceRight((value, segment) => ({ [segment]: value }), content)
                composer.mergeLocaleMessage(code, message)
            }
            installedKeys.add(key)
        })))
    }

    return { load }
}
