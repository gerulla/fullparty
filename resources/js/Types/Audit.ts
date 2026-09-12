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
}

export type AuditLogFeedPage = {
	auditLogs: AuditLogRowRecord[]
	nextCursor: string | null
	selectedFilters?: Partial<AuditLogFilters>
}

export type AuditLogFilterOption = { value: string, label: string }

export type AuditLogFilterOptions = {
	actions: AuditLogFilterOption[]
	severities: AuditLogFilterOption[]
	users: AuditLogFilterOption[]
	groups?: AuditLogFilterOption[]
	activities?: Array<{ value: string, title: string | null, starts_at: string | null }>
}
