<script setup lang="ts">
import { computed } from "vue";
import { Head, usePage } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";

const props = withDefaults(defineProps<{
	title?: string | null
	description?: string | null
	canonical?: string | null
	noindex?: boolean
	ogType?: string
	image?: string | null
	structuredData?: Record<string, unknown> | Array<Record<string, unknown>> | null
	appendSiteName?: boolean
}>(), {
	title: null,
	description: null,
	canonical: null,
	noindex: false,
	ogType: "website",
	image: null,
	structuredData: null,
	appendSiteName: true,
});

const { t } = useI18n();
const page = usePage();

const siteName = computed(() => t("meta.title"));

const currentUrl = computed(() => {
	const location = page.props.ziggy?.location;

	if (typeof location === "string" && location.length > 0) {
		return location;
	}

	if (typeof window !== "undefined") {
		return window.location.href;
	}

	return null;
});

const canonicalUrl = computed(() => props.canonical || currentUrl.value);
const fullTitle = computed(() => props.title ? `${props.title} - ${siteName.value}` : siteName.value);
const robots = computed(() => props.noindex ? "noindex, nofollow" : "index, follow");
const description = computed(() => props.description || t("meta.seo.defaults.description"));
const structuredDataJson = computed(() => (
	props.structuredData
		? JSON.stringify(props.structuredData)
		: null
));
</script>

<template>
	<Head>
		<title>{{ appendSiteName ? fullTitle : title }}</title>
		<meta head-key="description" name="description" :content="description">
		<meta head-key="robots" name="robots" :content="robots">
		<link v-if="canonicalUrl" head-key="canonical" rel="canonical" :href="canonicalUrl">

		<meta head-key="og:title" property="og:title" :content="fullTitle">
		<meta head-key="og:description" property="og:description" :content="description">
		<meta head-key="og:type" property="og:type" :content="ogType">
		<meta head-key="og:site_name" property="og:site_name" :content="siteName">
		<meta v-if="canonicalUrl" head-key="og:url" property="og:url" :content="canonicalUrl">
		<meta v-if="image" head-key="og:image" property="og:image" :content="image">

		<meta head-key="twitter:card" name="twitter:card" :content="image ? 'summary_large_image' : 'summary'">
		<meta head-key="twitter:title" name="twitter:title" :content="fullTitle">
		<meta head-key="twitter:description" name="twitter:description" :content="description">
		<meta v-if="image" head-key="twitter:image" name="twitter:image" :content="image">

		<component
			:is="'script'"
			v-if="structuredDataJson"
			head-key="structured-data"
			type="application/ld+json"
		>{{ structuredDataJson }}</component>
	</Head>
</template>
