export type AuditLogRowRecord = {
	id: number
	action: string
	severity: string
	scope?: {
		type: string | null
		id: number | null
		label: string | null
	}
	actor: {
		id: number | null
		name: string
		avatar_url: string | null
		is_system: boolean
	}
	subject: {
		type: string | null
		id: number | null
		name: string
		avatar_url: string | null
		is_system: boolean
	}
	title?: string
	summary?: string
	changes: Array<{
		label: string
		old: string
		new: string
	}>
	details: string[]
	search_text?: string
	created_at: string
	category?: AuditLogCategory
	icon?: string
	summary_parts?: AuditSummaryPart[]
	activity?: { id: number | null; title: string; url: string | null } | null
	resource?: { kind: 'resource' | 'collection' | 'image' | 'library'; id: number; title: string; url: string | null } | null
	change_count?: number
}

export type AuditLogCategory = 'runs' | 'members' | 'resources' | 'settings'

export type AuditSummaryPart = {
    kind: 'text' | 'actor' | 'subject' | 'activity' | 'resource' | 'value'
    text: string
    display_value?: AuditDisplayValue
}

export type AuditDisplayValue =
    | { type: 'text'; value: string }
    | { type: 'datetime'; value: string }
    | { type: 'duration'; value: number }
    | { type: 'boolean'; value: boolean }
    | { type: 'empty' }
    | { type: 'list'; value: AuditDisplayValue[] }
    | { type: 'object'; value: Array<{ label: string; value: AuditDisplayValue }> }

export type AuditLogEventRecord = AuditLogRowRecord & {
    formatted_changes: Array<{
        key: string
        label: string
        old_value: AuditDisplayValue
        new_value: AuditDisplayValue
    }>
    facts: Array<{ key: string; label: string; value: AuditDisplayValue }>
}

export type AuditLogFilters = {
	search: string
	action: string
	severity: string
	user: string
	group: string
	activity: string
	beforeDate: string
	afterDate: string
	category?: string
	timezone?: string
}

export type AuditLogFeedPage = {
	auditLogs: AuditLogRowRecord[]
	nextCursor: string | null
	selectedFilters?: Partial<AuditLogFilters>
	filters?: AuditLogFilterOptions
}

export type AuditLogFilterOption = { value: string, label: string }

export type AuditLogFilterOptions = {
	actions: AuditLogFilterOption[]
	severities: AuditLogFilterOption[]
	users: AuditLogFilterOption[]
	groups?: AuditLogFilterOption[]
	activities?: Array<{ value: string, title: string | null, starts_at: string | null }>
}
