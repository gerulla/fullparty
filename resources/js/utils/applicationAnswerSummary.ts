import type { ApplicationQuestion } from '../Types/ActivityApplications';

export function formatApplicationAnswerSummary(
    question: ApplicationQuestion,
    value: unknown,
    optionLabel: (question: ApplicationQuestion, key: string) => string,
    t: (key: string) => string,
): { value: string, isLongText: boolean } | null {
    if (value === null || value === undefined || value === '') return null;

    if (question.type === 'boolean') {
        return { value: value ? t('general.yes') : t('general.no'), isLongText: false };
    }

    if (question.type === 'holster_pair_list') {
        if (!Array.isArray(value)) return null;

        const labels = value.flatMap(pair => {
            if (!pair || typeof pair !== 'object' || Array.isArray(pair)) return [];

            const parts = (['prepop', 'refill'] as const).flatMap(kind => {
                const id = pair[`${kind}_id`];
                if ((typeof id !== 'string' && typeof id !== 'number') || id === '') return [];

                const label = optionLabel(question, String(id));
                return label ? [`${t(`groups.activities.application.holsters.${kind}`)}: ${label}`] : [];
            });

            return parts.length ? [parts.join(' + ')] : [];
        });

        return labels.length ? { value: labels.join('\n'), isLongText: true } : null;
    }

    if (question.type === 'multi_select' && Array.isArray(value)) {
        const labels = value
            .filter(entry => typeof entry === 'string' || typeof entry === 'number')
            .map(entry => optionLabel(question, String(entry)))
            .filter(entry => entry !== '');

        return labels.length ? { value: labels.join(', '), isLongText: labels.length > 2 } : null;
    }

    if (question.type === 'single_select' && typeof value === 'string') {
        const label = optionLabel(question, value);
        return label ? { value: label, isLongText: false } : null;
    }

    if (typeof value === 'string') {
        return { value, isLongText: question.type === 'textarea' || value.length > 80 };
    }

    if (typeof value === 'number' || typeof value === 'boolean') {
        return { value: String(value), isLongText: false };
    }

    return null;
}
