export type NotificationRecord = {
	id: string
	type: string | null
	category: string | null
	is_mandatory: boolean
	aggregate_count: number
	aggregate_key: string | null
	title_key: string | null
	body_key: string | null
	message_params: Record<string, unknown> | null
	payload: Record<string, unknown> | null
	action_url: string | null
	open_url: string
	created_at: string | null
	read_at: string | null
	is_unread: boolean
}

export type NotificationTranslator = (key: string, params?: Record<string, unknown>) => string

export type NotificationDisplayMeta = {
	icon: string
	iconColor: string
	surfaceClass?: string
}

export type NotificationPageData = {
	items: NotificationRecord[]
	pagination: {
		current_page: number
		next_page: number | null
		has_more_pages: boolean
		per_page: number
		total: number
	}
}

export type SystemNotificationHistoryItem = Pick<
	NotificationRecord,
	"id" | "type" | "is_mandatory" | "title_key" | "body_key" | "message_params" | "payload" | "action_url" | "created_at"
> & {
	actor: {
		id: number | null
		name: string
	}
	read_count: number
	delivery_count: number
}

export type SystemBannerRecord = {
	id: number
	title: string
	message: string
	action_label: string | null
	action_url: string | null
	updated_at: string | null
}
