import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { LegalSection } from '@/Types/Legal'

export function useLegalDocument(document: 'privacy' | 'cookies') {
    const page = usePage<{ legal: { controller_name: string | null; contact_email: string | null } }>()
    const { t, tm } = useI18n()
    const parameters = computed(() => {
        const controller = page.props.legal.controller_name ?? ''
        const contact = page.props.legal.contact_email ?? t('legal.contact_fallback')
        const suffix = controller ? '' : '_fallback'
        return {
            controller,
            contact,
            operator_sentence: t(`legal.privacy.operator_sentence${suffix}`, { controller }),
            operator_details: t(`legal.privacy.operator_details${suffix}`, { controller }),
            contact_line: t(`legal.cookies.contact_line${suffix}`, { controller, contact }),
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
        })) : []
    })

    return { intro, sections }
}
