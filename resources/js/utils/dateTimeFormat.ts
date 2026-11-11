import { fromAbsolute, parseDate, parseDateTime, toZoned } from '@internationalized/date';
import type { DateTimeValue, DisplayDateTimeOptions } from '@/Types/DateTime';

export type DateTimeLocale = string | null | undefined;

export const resolveDateTimeLocale = (locale?: DateTimeLocale): string => {
	const normalizedLocale = typeof locale === "string" ? locale.trim() : "";

	if (normalizedLocale === "") {
		return "en-GB";
	}

	const lowerLocale = normalizedLocale.toLowerCase();

	if (lowerLocale === "en" || lowerLocale.startsWith("en-")) {
		return "en-GB";
	}

	return normalizedLocale;
};

export const createDateTimeFormatter = (
	locale: DateTimeLocale,
	options?: Intl.DateTimeFormatOptions,
): Intl.DateTimeFormat => {
	return new Intl.DateTimeFormat(resolveDateTimeLocale(locale), options);
};

export const createRelativeTimeFormatter = (
	locale: DateTimeLocale,
	options?: Intl.RelativeTimeFormatOptions,
): Intl.RelativeTimeFormat => {
	return new Intl.RelativeTimeFormat(resolveDateTimeLocale(locale), options);
};


export function parseTimestamp(value: DateTimeValue): Date | null {
	if (value === null || value === undefined || value === '') return null;
	const date = value instanceof Date ? value : new Date(value);
	return Number.isNaN(date.getTime()) ? null : date;
}

/** Machine-readable wall time in a chosen timezone, never the current UTC offset. */
export function dateTimeInputInZone(value: DateTimeValue, timeZone?: string): string {
	const date = parseTimestamp(value);
	if (!date) return '';
	const zoned = fromAbsolute(date.getTime(), timeZone ?? Intl.DateTimeFormat().resolvedOptions().timeZone);
	const pad = (part: number) => String(part).padStart(2, '0');
	return `${String(zoned.year).padStart(4, '0')}-${pad(zoned.month)}-${pad(zoned.day)}T${pad(zoned.hour)}:${pad(zoned.minute)}`;
}

/** Reject missing/ambiguous DST clock times, preserving an unchanged repeated-hour selection. */
export function dateTimeInputToInstant(value: string, timeZone?: string, reference?: DateTimeValue): Date | null {
	const original = parseTimestamp(reference);
	if (original && dateTimeInputInZone(original, timeZone) === value) return original;
	try {
		if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)) return null;
		return toZoned(parseDateTime(value), timeZone ?? Intl.DateTimeFormat().resolvedOptions().timeZone, 'reject').toDate();
	} catch {
		return null;
	}
}

/** Calendar dates and recurring clock values are not instants and must not shift with LT/ST. */
export function formatCalendarDate(locale: DateTimeLocale, value: string, options: DisplayDateTimeOptions = { dateStyle: 'medium' }): string {
	try {
		return createDateTimeFormatter(locale, { ...options, timeZone: 'UTC' }).format(parseDate(value).toDate('UTC'));
	} catch {
		return '—';
	}
}

export function formatWallClock(locale: DateTimeLocale, value: string): string {
	try {
		const time = value === '24:00' ? '00:00' : value;
		return createDateTimeFormatter(locale, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: 'UTC' })
			.format(toZoned(parseDateTime(`2000-01-01T${time}`), 'UTC').toDate());
	} catch {
		return '—';
	}
}
