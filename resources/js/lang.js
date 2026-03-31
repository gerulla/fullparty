const localeFiles = import.meta.glob('../../lang/**/*.json', { eager: true })

function buildMessages() {
    const messages = {}

    for (const path in localeFiles) {
        const match = path.match(/\.\.\/\.\.\/lang\/([^/]+)\/(.+)\.json$/)

        if (!match) continue

        const [, locale, fileName] = match
        const content = localeFiles[path].default ?? localeFiles[path]

        if (!messages[locale]) {
            messages[locale] = {}
        }

        messages[locale][fileName] = content
    }

    return messages
}

export const messages = buildMessages()
export const availableLocales = Object.keys(messages)

export function getDefaultLocale() {
    return 'en'
}