import type { ActivityIndexItem } from "@/Types/ActivityCore";
import type { MyRunsCommitment, MyRunsDaySection, MyRunsToolState } from "@/Types/MyRuns";
import { groupActivitiesByDisplayDate, sortActivitiesByStart, toDisplayDateKey } from "./activityCalendar.ts";

const normalizeSearch = (value: string) => value.normalize('NFKC').toLowerCase();

const runInterval = (run: MyRunsCommitment) => {
	const start = run.starts_at ? new Date(run.starts_at).getTime() : NaN;
	const hours = Number(run.duration_hours);
	return {
		start,
		end: start + (Number.isFinite(hours) && hours > 0 ? hours * 3_600_000 : 0),
	};
};

export const filterMyRuns = (
	activities: ActivityIndexItem[],
	filters: MyRunsToolState,
	commitments: MyRunsCommitment[],
	today: string,
	timeZone?: string,
): ActivityIndexItem[] => {
	const groupIds = new Set(filters.groupIds);
	const searchTerms = normalizeSearch(filters.search).trim().split(/\s+/).filter(Boolean);
	const committedIds = new Set(commitments.map((run) => run.id));
	const busyIntervals = commitments.map(runInterval).filter((interval) => Number.isFinite(interval.start));

	return activities.filter((activity) => {
		if (!activity.group || !groupIds.has(activity.group.id)) return false;
		if (filters.appliedOnly && !activity.has_existing_application) return false;
		if (searchTerms.length > 0) {
			const searchableText = normalizeSearch([
				activity.group.name,
				activity.title,
				...Object.values(activity.activity_type?.draft_name ?? {}),
			].filter(Boolean).join(' '));
			if (!searchTerms.every((term) => searchableText.includes(term))) return false;
		}
		const interval = runInterval(activity);
		if (!Number.isFinite(interval.start)) return false;
		if (toDisplayDateKey(new Date(interval.start), timeZone) < today) return false;
		if (!filters.hideOverlapping || activity.has_existing_application || committedIds.has(activity.id)) return true;

		// Compare absolute timestamps across midnight and timezones; touching endpoints do not clash.
		return !busyIntervals.some((busy) => interval.start === busy.start
			|| (interval.start < busy.end && interval.end > busy.start));
	});
};

export const groupMyRunsByDay = (activities: ActivityIndexItem[], timeZone?: string): MyRunsDaySection[] => (
	Object.entries(groupActivitiesByDisplayDate(sortActivitiesByStart(activities), timeZone))
		.sort(([left], [right]) => left.localeCompare(right))
		.map(([date, items]) => ({ date, activities: items }))
);
