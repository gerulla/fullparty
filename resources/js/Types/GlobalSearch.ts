export type GlobalSearchResultType = "run" | "group" | "activity"

export type GlobalSearchResult = {
	type: GlobalSearchResultType
	id: number
	title: string
	subtitle: string | null
	meta: string | null
	image_url: string | null
	icon: string
	url: string
}

export type GlobalSearchResponse = {
	runs: GlobalSearchResult[]
	past_runs: GlobalSearchResult[]
	groups: GlobalSearchResult[]
	activities: GlobalSearchResult[]
}
