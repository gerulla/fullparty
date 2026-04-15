import { router, usePage } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import { useI18n } from "vue-i18n";
import { de, en, fr, ja } from "@nuxt/ui/locale";

const uiLocales = { en, de, fr, ja };

export function usePersistentLocale() {
	const page = usePage();
	const { locale } = useI18n({ useScope: 'global' });

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
		if (locale.value !== value) {
			locale.value = value;
		}
	}, { immediate: true });

	const updateLocale = (value: string) => {
		if (!availableLocaleCodes.value.includes(value)) {
			return;
		}

		locale.value = value;

		router.post('/locale', {
			locale: value,
		}, {
			preserveScroll: true,
			preserveState: true,
			replace: true,
		});
	};

	return {
		currentLocale,
		currentUiLocale,
		localeOptions,
		updateLocale,
	};
}
