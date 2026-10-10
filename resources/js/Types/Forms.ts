import type { RichTextDocument } from './RichText'

export type FormLocale = 'en' | 'de' | 'fr' | 'ja'
export type FormText = Partial<Record<FormLocale, string>>
export type FormQuestionType = 'short_text' | 'long_text' | 'single_choice' | 'multiple_choice' | 'dropdown' | 'rating' | 'number' | 'date'
export type FormQuestion = { id: string; type: FormQuestionType; label: FormText; description: FormText; required: boolean; options: { id: string; label: FormText }[]; min?: number | null; max?: number | null; page_id?: string | null }
export type FormPage = { id: string; title: FormText; description: FormText }
export type FormQuestionPage = FormPage & { questions: FormQuestion[]; offset: number }
export type FormPageNavigation = { prepareSubmit: () => boolean; revealErrors: (errors: Record<string, string>) => void; reset: () => void }
export type FormDefinition = { title: FormText; intro: Partial<Record<FormLocale, RichTextDocument>>; thank_you: FormText; access: 'account' | 'anyone'; response_policy: 'single_editable' | 'single' | 'multiple'; questions: FormQuestion[]; pages?: FormPage[] }
export type FormAnswer = string | number | string[] | null
export type FormAnswers = Record<string, FormAnswer>
export type SurveyFormRecord = { slug: string; draft: FormDefinition; revision: number; is_published: boolean; is_open: boolean; published_version_id: number | null; has_changes: boolean }
export type FormSummaryRow = { question: FormQuestion; answered: number; average: number | null; counts: { id: string; label: FormText; count: number }[] }
export type FormResponseRow = { id: number; respondent: string; created_at: string; updated_at: string }
export type FormResponseDetail = FormResponseRow & { answers: { question: string; answer: string }[] }
export type FormVersion = { id: number; number: number; published_at: string }
export type FormPagination<T> = { data: T[]; current_page: number; total: number; per_page: number }
export type FormListRow = { slug: string; title: string; is_published: boolean; is_open: boolean; responses_count: number }
export type PublicForm = { slug: string; definition: FormDefinition; version_id: number; is_open: boolean }
export type ExistingFormResponse = { answers: FormAnswers; revision: number; version_changed: boolean; updated_at: string }
