export type ActivityStatus = 'draft' | 'planned' | 'scheduled' | 'upcoming' | 'ongoing' | 'complete' | 'cancelled';

type ActivityStatusMeta = {
	color: string
	icon: string
	borderClass: string
	dotClass: string
};

const STATUS_META: Record<ActivityStatus, ActivityStatusMeta> = {
	draft: {
		color: 'info',
		icon: 'i-lucide-file-pen-line',
		borderClass: 'border-t-info',
		dotClass: 'bg-info',
	},
	planned: {
		color: 'neutral',
		icon: 'i-lucide-clipboard-list',
		borderClass: 'border-t-zinc-950 dark:border-t-white',
		dotClass: 'bg-zinc-950 dark:bg-white',
	},
	scheduled: {
		color: 'warning',
		icon: 'i-lucide-calendar-check-2',
		borderClass: 'border-t-warning',
		dotClass: 'bg-warning',
	},
	upcoming: {
		color: 'primary',
		icon: 'i-lucide-sparkles',
		borderClass: 'border-t-primary',
		dotClass: 'bg-primary',
	},
	ongoing: {
		color: 'secondary',
		icon: 'i-lucide-activity',
		borderClass: 'border-t-secondary',
		dotClass: 'bg-secondary',
	},
	complete: {
		color: 'success',
		icon: 'i-lucide-flag',
		borderClass: 'border-t-success',
		dotClass: 'bg-success',
	},
	cancelled: {
		color: 'error',
		icon: 'i-lucide-ban',
		borderClass: 'border-t-error',
		dotClass: 'bg-error',
	},
};

const FALLBACK_META: ActivityStatusMeta = {
	color: 'neutral',
	icon: 'i-lucide-calendar-range',
	borderClass: 'border-t-default',
	dotClass: 'bg-default',
};

export const getActivityStatusMeta = (status: string): ActivityStatusMeta => (
	STATUS_META[status as ActivityStatus] ?? FALLBACK_META
);

export const getActivityStatusBorderClass = (status: string) => getActivityStatusMeta(status).borderClass;

export const getActivityStatusDotClass = (status: string) => getActivityStatusMeta(status).dotClass;
