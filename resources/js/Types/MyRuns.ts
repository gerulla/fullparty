import type { ActivityIndexItem } from "@/Types/ActivityCore";

export type MyRunsGroup = Pick<NonNullable<ActivityIndexItem["group"]>, "id" | "name" | "slug" | "profile_picture_url">;

export type MyRunsToolState = {
	date: string
	search: string
	appliedOnly: boolean
	hideOverlapping: boolean
	groupIds: number[]
};

export type MyRunsCommitment = Pick<ActivityIndexItem, "id" | "starts_at" | "duration_hours">;

export type MyRunsDaySection = {
	date: string
	activities: ActivityIndexItem[]
};
