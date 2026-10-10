import type { ActivityIndexItem, ActivityListType } from "@/Types/ActivityCore";

export const availableActivityTypes = (activities: ActivityIndexItem[]): ActivityListType[] => {
	const types = new Map<number, ActivityListType>();
	for (const activity of activities) {
		const type = activity.activity_type;
		if (type?.id != null && !types.has(type.id)) types.set(type.id, { ...type, id: type.id });
	}
	return [...types.values()];
};
