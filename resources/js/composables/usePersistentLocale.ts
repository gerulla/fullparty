import { usePage } from "@inertiajs/vue3";
import axios from "axios";
import { computed, watch } from "vue";
import { useI18n } from "vue-i18n";
import { de, en, fr, ja } from "@nuxt/ui/locale";
import { route } from "ziggy-js";

const uiLocales = { en, de, fr, ja };

export function usePersistentLocale() {
	const page = usePage();
	const { locale } = useI18n({ useScope: 'global' });

	const syncZiggyLocaleDefault = (value: string) => {
		if (typeof globalThis === 'undefined' || !('Ziggy' in globalThis)) {
			return;
		}

		const ziggy = globalThis.Ziggy as { defaults?: Record<string, unknown> } | undefined;

		if (!ziggy) {
			return;
		}

		ziggy.defaults = {
			...(ziggy.defaults ?? {}),
			locale: value,
		};
	};

	const availableLocaleCodes = computed(() => {
		const available = page.props.locale?.available;

		return Array.isArray(available) && available.length > 0
			? available
			: ['en', 'de', 'fr', 'ja'];
	});

	const currentLocale = computed(() => {
		const current = page.props.locale?.current;

		return typeof current === 'string' && current.length > 0
			? current
			: 'en';
	});

	const localeOptions = computed(() => Object.values(uiLocales)
		.filter((uiLocale) => availableLocaleCodes.value.includes(uiLocale.code)));

	const currentUiLocale = computed(() => {
		return uiLocales[locale.value as keyof typeof uiLocales]
			?? uiLocales[currentLocale.value as keyof typeof uiLocales]
			?? uiLocales.en;
	});

	watch(currentLocale, (value) => {
		syncZiggyLocaleDefault(value);

		if (locale.value !== value) {
			locale.value = value;
		}
	}, { immediate: true });

	const updateLocale = async (value: string) => {
		if (!availableLocaleCodes.value.includes(value)) {
			return;
		}

		locale.value = value;
		syncZiggyLocaleDefault(value);
		const currentRoute = route();
		const currentRouteName = currentRoute.current();

		if (currentRouteName) {
			const nextRouteParameters = {
				...currentRoute.routeParams,
				locale: value,
			};
			const currentQueryParameters = currentRoute.queryParams;
			const targetUrl = route(currentRouteName, {
				...nextRouteParameters,
				...(Object.keys(currentQueryParameters).length > 0
					? { _query: currentQueryParameters }
					: {}),
			});

			await persistLocale(value);
			window.location.assign(targetUrl);

			return;
		}

		const currentUrl = new URL(window.location.href);
		const segments = currentUrl.pathname.split('/').filter(Boolean);

		if (segments.length > 0 && availableLocaleCodes.value.includes(segments[0])) {
			segments[0] = value;
		} else {
			segments.unshift(value);
		}

		currentUrl.pathname = `/${segments.join('/')}`;
		await persistLocale(value);
		window.location.assign(currentUrl.toString());
	};

	const persistLocale = async (value: string) => {
		await axios.post(route('locale.update'), { locale: value }, {
			headers: {
				Accept: 'application/json',
			},
		});
	};

	return {
		currentLocale,
		currentUiLocale,
		localeOptions,
		updateLocale,
	};
}
