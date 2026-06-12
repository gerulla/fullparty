export const ARCHIVED_ACTIVITY_STATUSES = ['complete', 'cancelled'] as const;
export const APPLICATION_OPEN_ACTIVITY_STATUSES = ['draft', 'scheduled', 'assigned', 'upcoming'] as const;
export const SCHEDULABLE_ACTIVITY_STATUSES = ['draft'] as const;
export const ASSIGNABLE_ACTIVITY_STATUSES = ['scheduled'] as const;
export const COMPLETABLE_ACTIVITY_STATUSES = ['assigned', 'upcoming', 'ongoing'] as const;
export const CANCELLABLE_ACTIVITY_STATUSES = ['assigned', 'upcoming', 'ongoing'] as const;
export const DELETABLE_ACTIVITY_STATUSES = ['draft', 'scheduled'] as const;

export const isArchivedActivityStatus = (status: string | null | undefined): boolean => (
	ARCHIVED_ACTIVITY_STATUSES.includes((status ?? '') as typeof ARCHIVED_ACTIVITY_STATUSES[number])
);

export const canAcceptActivityApplications = (
	status: string | null | undefined,
	startsAt: string | null | undefined = null,
): boolean => {
	if (!APPLICATION_OPEN_ACTIVITY_STATUSES.includes((status ?? '') as typeof APPLICATION_OPEN_ACTIVITY_STATUSES[number])) {
		return false;
	}

	return !startsAt || new Date(startsAt).getTime() > Date.now();
};

export const canPublishActivityRoster = (status: string | null | undefined): boolean => (
	ASSIGNABLE_ACTIVITY_STATUSES.includes((status ?? '') as typeof ASSIGNABLE_ACTIVITY_STATUSES[number])
);

export const canScheduleActivity = (status: string | null | undefined): boolean => (
	SCHEDULABLE_ACTIVITY_STATUSES.includes((status ?? '') as typeof SCHEDULABLE_ACTIVITY_STATUSES[number])
);

export const canCompleteActivity = (status: string | null | undefined): boolean => (
	COMPLETABLE_ACTIVITY_STATUSES.includes((status ?? '') as typeof COMPLETABLE_ACTIVITY_STATUSES[number])
);

export const canCancelActivity = (status: string | null | undefined): boolean => (
	CANCELLABLE_ACTIVITY_STATUSES.includes((status ?? '') as typeof CANCELLABLE_ACTIVITY_STATUSES[number])
);

export const canDeleteActivity = (status: string | null | undefined): boolean => (
	DELETABLE_ACTIVITY_STATUSES.includes((status ?? '') as typeof DELETABLE_ACTIVITY_STATUSES[number])
);
