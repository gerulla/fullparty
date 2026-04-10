const localeFiles = import.meta.glob('../../lang/**/*.json', { eager: true })

function buildMessages() {
    const messages = {}

    for (const path in localeFiles) {
        const match = path.match(/\.\.\/\.\.\/lang\/([^/]+)\/(.+)\.json$/)

        if (!match) continue

        const [, locale, filePath] = match
        const content = localeFiles[path].default ?? localeFiles[path]

        if (!messages[locale]) {
            messages[locale] = {}
        }

        // Split the file path by '/' to handle nested directories
        const pathParts = filePath.split('/')
        let current = messages[locale]

        // Navigate through nested structure, creating objects as needed
        for (let i = 0; i < pathParts.length - 1; i++) {
            if (!current[pathParts[i]]) {
                current[pathParts[i]] = {}
            }
            current = current[pathParts[i]]
        }

        // Set the content at the final key
        const finalKey = pathParts[pathParts.length - 1]
        current[finalKey] = content
    }

    return messages
}

export const messages = buildMessages()
export const availableLocales = Object.keys(messages)

export function getDefaultLocale() {
    return 'en'
}