export type LegalSection = {
	title: string
	paragraphs?: string[]
	bullets?: string[]
	links?: { label: string; href: string }[]
}

export type LegalPageProps = {
    legal: { controller_name: string | null; contact_email: string | null }
    cookie_settings?: {
        session_name: string
        session_minutes: number
        session_expires_on_close: boolean
    }
}
