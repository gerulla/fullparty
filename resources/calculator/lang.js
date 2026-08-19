const localeGroups = {
    auth: import.meta.glob('../../lang/*/auth.json', { eager: true }),
    calculator: import.meta.glob('../../lang/*/calculator.json', { eager: true }),
    navigation: import.meta.glob('../../lang/*/navigation.json', { eager: true }),
}

function buildMessages() {
    const messages = {}

    for (const namespace in localeGroups) {
        const files = localeGroups[namespace]

        for (const path in files) {
            const match = path.match(/\.\.\/\.\.\/lang\/([^/]+)\/[^/]+\.json$/)

            if (!match) continue

            const [, locale] = match
            const content = files[path].default ?? files[path]

            messages[locale] = {
                ...(messages[locale] ?? {}),
                [namespace]: content,
            }
        }
    }

    return messages
}

export const messages = buildMessages()
export const availableLocales = Object.keys(messages)

export function getDefaultLocale() {
    return 'en'
}
