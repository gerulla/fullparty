export type LocalizedText = Record<string, string | null | undefined> | null | undefined;

export interface ActivityIndexItem {
	id: number
	activity_type: {
		id: number | null
		slug: string | null
		draft_name: LocalizedText
	}
	activity_type_version_id: number
	title: string | null
	status: string
	starts_at: string | null
	organized_by: {
		id: number
		name: string
		avatar_url: string | null
	} | null
	slot_count: number
	application_count: number
	progress_milestone_count: number
	created_at: string | null
	updated_at: string | null
}
