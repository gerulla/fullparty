import type { RichTextDocument } from './RichText'

export type ChangelogLocale = 'en' | 'de' | 'fr' | 'ja'
export type ChangelogTranslation = { title: string; body: RichTextDocument }
export type ChangelogSummary = { id: number; title: string; version_from: string | null; version_to: string; published_at: string | null }
export type ChangelogArticle = ChangelogSummary & ChangelogTranslation
export type ChangelogVersionChoices = { current_version: string | null; current_commit: string | null; previous_entry_id: number | null; previous_version: string | null; can_use_range: boolean }
export type ChangelogDraft = {
    id: number; translations: Partial<Record<ChangelogLocale, ChangelogTranslation>>; version_mode: 'current' | 'since_last'
    version_from: string | null; version_to: string; commit: string | null; baseline_id: number | null
    is_published: boolean; published_at: string | null; revision: number
}
export type ChangelogForm = {
    translations: Record<ChangelogLocale, ChangelogTranslation>; version_mode: '' | 'current' | 'since_last'
    version_context: ChangelogVersionChoices; revision: number
}
export type ChangelogAdminList = { data: (ChangelogSummary & { is_published: boolean })[]; current_page: number; per_page: number; total: number }
