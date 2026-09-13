import type { NotificationDisplayMeta, NotificationRecord, NotificationTranslator } from "@/Types/Notifications"
import { createRelativeTimeFormatter } from "@/utils/dateTimeFormat"
import { translateCharacterClassName, translatePhantomJobName, translateRaidPositionName } from "@/utils/characterJobTranslations"

const TYPE_META: Record<string, NotificationDisplayMeta> = {
	'user.settings.notifications_updated': {
		icon: 'i-lucide-bell-ring',
		iconColor: 'text-brand-500',
	},
	'user.settings.username_updated': {
		icon: 'i-lucide-user-round-pen',
		iconColor: 'text-sky-500',
	},
	'user.settings.profile_picture_updated': {
		icon: 'i-lucide-image-up',
		iconColor: 'text-sky-500',
	},
	'user.settings.privacy_updated': {
		icon: 'i-lucide-shield-check',
		iconColor: 'text-emerald-500',
	},
	'user.social_account.linked': {
		icon: 'i-lucide-link',
		iconColor: 'text-cyan-500',
	},
	'user.social_account.unlinked': {
		icon: 'i-lucide-unlink',
		iconColor: 'text-rose-500',
	},
	'characters.added': {
		icon: 'i-lucide-user-round-plus',
		iconColor: 'text-emerald-500',
	},
	'characters.refreshed': {
		icon: 'i-lucide-refresh-ccw',
		iconColor: 'text-sky-500',
	},
	'characters.primary_changed': {
		icon: 'i-lucide-star',
		iconColor: 'text-amber-500',
	},
	'characters.unclaimed': {
		icon: 'i-lucide-unlink',
		iconColor: 'text-rose-500',
	},
	'assignments.designation_assigned': {
		icon: 'i-lucide-badge-check',
		iconColor: 'text-sky-500',
	},
	'assignments.designation_removed': {
		icon: 'i-lucide-badge-x',
		iconColor: 'text-amber-500',
	},
	'system.maintenance.upcoming': {
		icon: 'i-lucide-wrench',
		iconColor: 'text-amber-500',
	},
	'system.announcement': {
		icon: 'i-lucide-megaphone',
		iconColor: 'text-sky-500',
	},
	'integration.event_delivery_failed': {
		icon: 'i-lucide-plug-zap',
		iconColor: 'text-rose-500',
	},
}

const CATEGORY_META: Record<string, NotificationDisplayMeta> = {
	applications: {
		icon: 'i-lucide-file-text',
		iconColor: 'text-blue-500',
	},
	assignments: {
		icon: 'i-lucide-clipboard-check',
		iconColor: 'text-emerald-500',
	},
	runs_and_reminders: {
		icon: 'i-lucide-calendar-clock',
		iconColor: 'text-violet-500',
	},
	group_updates: {
		icon: 'i-lucide-shield',
		iconColor: 'text-cyan-500',
	},
	account_character_updates: {
		icon: 'i-lucide-user-round-cog',
		iconColor: 'text-amber-500',
	},
	system_notices: {
		icon: 'i-lucide-triangle-alert',
		iconColor: 'text-rose-500',
	},
}

const formatLabelKeyList = (keys: unknown, t: NotificationTranslator) => {
	if (!Array.isArray(keys)) {
		return ''
	}

	return keys
		.map((key) => typeof key === 'string' ? t(key) : null)
		.filter((value): value is string => Boolean(value))
		.join(', ')
}

export const resolveNotificationMeta = (notification: NotificationRecord) => {
	if (notification.type && TYPE_META[notification.type]) {
		return TYPE_META[notification.type]
	}

	if (notification.category && CATEGORY_META[notification.category]) {
		return CATEGORY_META[notification.category]
	}

	return {
		icon: 'i-lucide-bell',
		iconColor: 'text-neutral-500',
	}
}

const notificationParams = (notification: NotificationRecord, t: NotificationTranslator) => {
    const params = notification.message_params ? { ...notification.message_params } : {}
    if (typeof params.class === 'string') {
        params.class = translateCharacterClassName(t, { name: params.class, shorthand: String(params.class_shorthand ?? '') }, params.class)
    }
    if (typeof params.phantom_job === 'string') {
        params.phantom_job = translatePhantomJobName(t, { name: params.phantom_job }, params.phantom_job)
    }
    if (typeof params.position === 'string') {
        params.position = translateRaidPositionName(t, { key: String(params.position_key ?? ''), name: params.position }, params.position)
    }
    const designation = notification.payload?.designation_key
    if (typeof designation === 'string' && ['host', 'raid_leader', 'duelist', 'trapper', 'darter'].includes(designation)) {
        params.designation = t(`notifications.designations.${designation}`)
    }
    if (notification.payload?.status === 'cancelled' && params.reason === 'Run cancelled.') {
        params.reason = t('notifications.system_run_cancelled')
    }
    return params
}

export const resolveNotificationTitle = (notification: NotificationRecord, t: NotificationTranslator) => {
	if (!notification.title_key) {
		return t('notifications.ui.fallback_title')
	}

	const params = notificationParams(notification, t)

	return t(notification.title_key, params)
}

export const resolveNotificationDescription = (notification: NotificationRecord, t: NotificationTranslator) => {
	if (!notification.body_key) {
		return null
	}

	const params = notificationParams(notification, t)
	const settings = formatLabelKeyList(params.changed_setting_label_keys, t)

	if (settings) {
		params.categories = settings
		params.settings = settings
	}

	return t(notification.body_key, params)
}

export const formatNotificationTime = (value: string | null, locale: string, t: NotificationTranslator) => {
	if (!value) {
		return t('notifications.ui.just_now')
	}

	const target = new Date(value).getTime()
	const now = Date.now()
	const diffMs = target - now

	const units: Array<[Intl.RelativeTimeFormatUnit, number]> = [
		['year', 1000 * 60 * 60 * 24 * 365],
		['month', 1000 * 60 * 60 * 24 * 30],
		['day', 1000 * 60 * 60 * 24],
		['hour', 1000 * 60 * 60],
		['minute', 1000 * 60],
	]

	for (const [unit, threshold] of units) {
		if (Math.abs(diffMs) >= threshold) {
			return createRelativeTimeFormatter(locale, { numeric: 'auto' }).format(
				Math.round(diffMs / threshold),
				unit,
			)
		}
	}

	return t('notifications.ui.just_now')
}
