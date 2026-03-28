<script setup lang="ts">
import {useI18n} from "vue-i18n";
import PageHeader from "@/components/PageHeader.vue";
const { t } = useI18n()
</script>

<template>
	<div class="w-full min-h-screen sm:px-4 md:px-6 bg-neutral-100 dark:bg-neutral-900">
		<PageHeader title="Dashboard" subtitle="Welcome back! Here's what's happening with your runs." />
	</div>
</template>

<style scoped>

</style>