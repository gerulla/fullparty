<script setup lang="ts">
import {useI18n} from "vue-i18n";
import PageHeader from "@/components/PageHeader.vue";
const { t } = useI18n()
</script>

<template>
	<div class="w-full">
		<PageHeader title="Dashboard" subtitle="Welcome back! Here's what's happening with your runs." />
	</div>
</template>

<style scoped>

</style>