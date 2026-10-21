import { computed, ref, watch } from "vue";
import { usePage } from "@inertiajs/vue3";

export type TimeDisplayMode = "local" | "server";

type SharedTimeDisplayPageProps = {
	auth?: {
		user?: {
			time_display_mode?: string | null
		} | null
	}
};

export const SERVER_TIME_ZONE = "UTC";

const sharedTimeDisplayMode = ref<TimeDisplayMode>("local");
let initialized = false;

export function normalizeTimeDisplayMode(value: unknown): TimeDisplayMode {
	return value === "server" ? "server" : "local";
}

export function setSharedTimeDisplayMode(mode: TimeDisplayMode): void {
	sharedTimeDisplayMode.value = mode;
}

export function useTimeDisplayMode() {
	const page = usePage<SharedTimeDisplayPageProps>();

	const pageTimeDisplayMode = computed(() => normalizeTimeDisplayMode(
		page.props.auth?.user?.time_display_mode,
	));

	if (!initialized) {
		sharedTimeDisplayMode.value = pageTimeDisplayMode.value;
		initialized = true;
	}

	watch(pageTimeDisplayMode, (mode) => {
		sharedTimeDisplayMode.value = mode;
	});

	const isServerTime = computed(() => sharedTimeDisplayMode.value === "server");
	const displayTimeZone = computed(() => (isServerTime.value ? SERVER_TIME_ZONE : undefined));
	const displayTimeZoneLabel = computed(() => (
		isServerTime.value
			? SERVER_TIME_ZONE
			: Intl.DateTimeFormat().resolvedOptions().timeZone
	));

	const withDisplayTimeZone = (options: Intl.DateTimeFormatOptions = {}): Intl.DateTimeFormatOptions => ({
		...options,
		timeZone: displayTimeZone.value,
	});

	return {
		timeDisplayMode: sharedTimeDisplayMode,
		isServerTime,
		displayTimeZone,
		displayTimeZoneLabel,
		withDisplayTimeZone,
		setTimeDisplayMode: setSharedTimeDisplayMode,
	};
}
