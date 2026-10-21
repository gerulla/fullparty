import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { LegalPageProps, LegalSection } from '@/Types/Legal'

export function useLegalDocument(document: 'privacy' | 'cookies') {
    const page = usePage<LegalPageProps>()
    const { t, tm } = useI18n()
    const parameters = computed(() => {
        const controller = page.props.legal.controller_name ?? ''
        const contact = page.props.legal.contact_email ?? t('legal.contact_fallback')
        const suffix = controller ? '' : '_fallback'
        const cookies = page.props.cookie_settings
        return {
            controller,
            contact,
            operator_sentence: t(`legal.privacy.operator_sentence${suffix}`, { controller }),
            operator_details: t(`legal.privacy.operator_details${suffix}`, { controller }),
            contact_line: t(`legal.cookies.contact_line${suffix}`, { controller, contact }),
            session_cookie: cookies?.session_name ?? '',
            session_minutes: cookies?.session_minutes ?? '',
            session_duration: document === 'cookies'
                ? t(cookies?.session_expires_on_close ? 'legal.cookies.session_until_close' : 'legal.cookies.session_minutes', {
                    minutes: cookies?.session_minutes ?? '',
                })
                : '',
        }
    })
    const lines = (key: string): string[] => {
        const messages = tm(key)
        return Array.isArray(messages) ? messages.map((_, index) => t(`${key}.${index}`, parameters.value)) : []
    }
    const intro = computed(() => lines(`legal.${document}.intro`))
    const sections = computed<LegalSection[]>(() => {
        const messages = tm(`legal.${document}.sections`)
        return Array.isArray(messages) ? messages.map((section, index) => ({
            title: t(`legal.${document}.sections.${index}.title`),
            paragraphs: Array.isArray(section.paragraphs) ? lines(`legal.${document}.sections.${index}.paragraphs`) : [],
            bullets: Array.isArray(section.bullets) ? lines(`legal.${document}.sections.${index}.bullets`) : [],
            links: Array.isArray(section.links) ? section.links.map((_, linkIndex) => ({
                label: t(`legal.${document}.sections.${index}.links.${linkIndex}.label`),
                href: t(`legal.${document}.sections.${index}.links.${linkIndex}.href`),
            })) : [],
        })) : []
    })

    return { intro, sections }
}
