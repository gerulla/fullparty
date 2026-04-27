export const ARCHIVED_ACTIVITY_STATUSES = ['complete', 'cancelled'] as const;
export const ASSIGNABLE_ACTIVITY_STATUSES = ['planned', 'scheduled'] as const;
export const COMPLETABLE_ACTIVITY_STATUSES = ['planned', 'scheduled', 'assigned', 'upcoming', 'ongoing'] as const;

export const isArchivedActivityStatus = (status: string | null | undefined): boolean => (
	ARCHIVED_ACTIVITY_STATUSES.includes((status ?? '') as typeof ARCHIVED_ACTIVITY_STATUSES[number])
);

export const canPublishActivityRoster = (status: string | null | undefined): boolean => (
	ASSIGNABLE_ACTIVITY_STATUSES.includes((status ?? '') as typeof ASSIGNABLE_ACTIVITY_STATUSES[number])
);

export const canCompleteActivity = (status: string | null | undefined): boolean => (
	COMPLETABLE_ACTIVITY_STATUSES.includes((status ?? '') as typeof COMPLETABLE_ACTIVITY_STATUSES[number])
);
