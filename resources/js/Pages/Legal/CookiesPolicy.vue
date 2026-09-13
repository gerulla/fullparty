<script setup lang="ts">
import LandingLayout from "@/Layouts/LandingLayout.vue";
import LegalDocument from "@/components/LegalDocument.vue";
import SeoHead from "@/components/Shared/SeoHead.vue";
import { useLegalDocument } from "@/composables/useLegalDocument";
import { useI18n } from "vue-i18n";

const { t } = useI18n();
const { intro, sections } = useLegalDocument('cookies');
</script>

<template>
	<SeoHead
		:title="t('meta.seo.legal.cookies_title')"
		:description="t('meta.seo.legal.cookies_description')"
	/>

	<LegalDocument
		:title="t('legal.cookies.title')"
		last-updated="2026-05-07"
		:intro="intro"
		:sections="sections"
	/>
</template>

<script lang="ts">
export default {
	layout: LandingLayout,
};
</script>
