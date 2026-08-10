import type { GroupQuickCreateShortcut } from "@/Types/ActivityCore"

const padDatePart = (value: number): string => String(value).padStart(2, "0")

export const quickCreateTimeModeLabel = (shortcut: GroupQuickCreateShortcut): "ST" | "LT" => (
	shortcut.time_mode === "local" ? "LT" : "ST"
)

export const resolveQuickCreateStartsAt = (
	dateKey: string,
	shortcut: GroupQuickCreateShortcut,
): string => {
	if (shortcut.time_mode === "server") {
		return `${dateKey}T${shortcut.time}`
	}

	const [year, month, day] = dateKey.split("-").map(Number)
	const [hour, minute] = shortcut.time.split(":").map(Number)
	const localDate = new Date(year, month - 1, day, hour, minute)

	return [
		localDate.getUTCFullYear(),
		padDatePart(localDate.getUTCMonth() + 1),
		padDatePart(localDate.getUTCDate()),
	].join("-") + `T${padDatePart(localDate.getUTCHours())}:${padDatePart(localDate.getUTCMinutes())}`
}
