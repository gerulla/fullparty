import type { FormAnswers, FormPage, FormQuestion, FormQuestionPage } from '@/Types/Forms'

export function questionsByPage(pages: FormPage[] | undefined, questions: FormQuestion[]): FormQuestionPage[] {
    if (!pages?.length) return [{ id: 'legacy', title: {}, description: {}, questions, offset: 0 }]
    let offset = 0
    return pages.map((page, index) => {
        const items = questions.filter(question => question.page_id === page.id || (index === 0 && !pages.some(candidate => candidate.id === question.page_id)))
        const result = { ...page, questions: items, offset }
        offset += items.length
        return result
    })
}

export function orderPageQuestions(pages: FormPage[], questions: FormQuestion[]): FormQuestion[] {
    return questionsByPage(pages, questions).flatMap(page => page.questions)
}

export function missingRequiredQuestions(questions: FormQuestion[], answers: FormAnswers): FormQuestion[] {
    return questions.filter(question => {
        if (!question.required) return false
        const value = answers[question.id]
        return value == null || (typeof value === 'string' && value.trim() === '') || (Array.isArray(value) && value.length === 0)
    })
}

export function firstPageWithErrors(pages: FormQuestionPage[], errors: Record<string, string>): number {
    return pages.findIndex(page => page.questions.some(question => Object.keys(errors).some(key => key === `answers.${question.id}` || key.startsWith(`answers.${question.id}.`))))
}
