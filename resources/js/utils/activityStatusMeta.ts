import type { ActivityStatus, ActivityStatusMeta } from "@/Types/ActivityCore"
import { getRunStatusIcon } from '@/utils/runStatusIcons'

const STATUS_META: Record<ActivityStatus, ActivityStatusMeta> = {
	draft: {
		color: 'neutral',
		icon: getRunStatusIcon('draft'),
		borderClass: 'border-t-zinc-950 dark:border-t-white',
		dotClass: 'bg-zinc-950 dark:bg-white',
	},
	scheduled: {
		color: 'warning',
		icon: getRunStatusIcon('scheduled'),
		borderClass: 'border-t-warning',
		dotClass: 'bg-warning',
	},
	assigned: {
		color: 'brand',
		icon: getRunStatusIcon('assigned'),
		borderClass: 'border-t-brand',
		dotClass: 'bg-brand',
	},
	upcoming: {
		color: 'brand',
		icon: getRunStatusIcon('upcoming'),
		borderClass: 'border-t-brand',
		dotClass: 'bg-brand',
	},
	ongoing: {
		color: 'secondary',
		icon: getRunStatusIcon('ongoing'),
		borderClass: 'border-t-secondary',
		dotClass: 'bg-secondary',
	},
	complete: {
		color: 'success',
		icon: getRunStatusIcon('complete'),
		borderClass: 'border-t-success',
		dotClass: 'bg-success',
	},
	cancelled: {
		color: 'error',
		icon: getRunStatusIcon('cancelled'),
		borderClass: 'border-t-error',
		dotClass: 'bg-error',
	},
}

const FALLBACK_META: ActivityStatusMeta = {
	color: 'neutral',
	icon: getRunStatusIcon(null),
	borderClass: 'border-t-default',
	dotClass: 'bg-default',
}

export const getActivityStatusMeta = (status: string): ActivityStatusMeta => (
	STATUS_META[status as ActivityStatus] ?? FALLBACK_META
);

export const getActivityStatusBorderClass = (status: string) => getActivityStatusMeta(status).borderClass;

export const getActivityStatusDotClass = (status: string) => getActivityStatusMeta(status).dotClass;
