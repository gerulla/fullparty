export type RunSearchField = 'activity' | 'when' | 'group'

export type RunSearchSegment = {
    field: RunSearchField
    label: string
    summary: string
    image?: string | null
}
