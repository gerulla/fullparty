import { createTranslationLoader } from '../js/i18n/createTranslationLoader.js'

export const translations = createTranslationLoader(import.meta.glob([
    '../../lang/*/auth.json',
    '../../lang/*/calculator.json',
    '../../lang/*/navigation.json',
]))
