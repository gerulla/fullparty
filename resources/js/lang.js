import { createTranslationLoader } from './i18n/createTranslationLoader.js'

const files = import.meta.glob([
    '../../lang/**/*.json',
    '!../../lang/*/calculator.json',
    '!../../lang/*/planner.json',
])

export const translations = createTranslationLoader(files)
