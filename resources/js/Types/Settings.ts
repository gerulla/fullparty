export type SettingsSocialAccount = {
	id: number
	provider: string
	provider_name: string | null
	provider_email: string | null
}

export type SettingsDiscordUserIntegration = {
	id: number
	discord_user_id: string
	username: string | null
	global_name: string | null
	avatar_url: string | null
	user_app_installed_at: string | null
}

export type SettingsLinkedSession = {
	id: string
	name: string | null
	client_name: string | null
	scopes: string[]
	created_at: string | null
	expires_at: string | null
	refresh_expires_at: string | null
}

export type SettingsUser = {
	name: string
	email: string
	avatar_url: string | null
	public_profile: boolean
	public_characters: boolean
	application_notifications: boolean
	run_and_reminder_notifications: boolean
	group_update_notifications: boolean
	assignment_notifications: boolean
	account_character_notifications: boolean
	system_notice_notifications: boolean
	email_notifications: boolean
	discord_notifications: boolean
	notification_preferences: Record<string, Partial<Record<"in_app" | "email" | "discord", boolean>>>
	time_display_mode: "local" | "server"
	discord_link_token_expires_at: string | null
	notification_preferences_reviewed_at: string | null
	discord_user_integration: SettingsDiscordUserIntegration | null
	social_accounts: SettingsSocialAccount[]
}
