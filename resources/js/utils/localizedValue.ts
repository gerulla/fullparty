export function localizedValue(
	value: Record<string, string | null | undefined> | null | undefined,
	locale: string,
	fallbackLocale = 'en',
): string {
	if (!value || typeof value !== 'object') {
		return '';
	}

	const current = value[locale];

	if (typeof current === 'string' && current.trim().length > 0) {
		return current;
	}

	const fallback = value[fallbackLocale];

	if (typeof fallback === 'string' && fallback.trim().length > 0) {
		return fallback;
	}

	const firstNonEmpty = Object.values(value).find((entry) => typeof entry === 'string' && entry.trim().length > 0);

	return typeof firstNonEmpty === 'string' ? firstNonEmpty : '';
}
