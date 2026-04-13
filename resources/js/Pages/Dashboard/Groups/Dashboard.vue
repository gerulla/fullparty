<script setup lang="ts">
import {useI18n} from "vue-i18n";
import PageHeader from "@/components/PageHeader.vue";

const props = defineProps<{
	group: Object
}>();

const {t} = useI18n();
</script>

<template>
	<div class="w-full">
		<PageHeader :title="group.name" :subtitle="t('groups.index.subtitle')">
<!--			Buttons will come later-->
		</PageHeader>
	</div>
</template>

<style scoped>

</style>
