<script setup lang="ts">
import {useI18n} from "vue-i18n";
import PageHeader from "@/components/PageHeader.vue";
import CharacterCard from "@/components/Pages/Characters/CharacterCard.vue";
const { t } = useI18n()
</script>

<template>
	<div class="w-full min-h-screen sm:px-4 md:px-6 bg-neutral-100 dark:bg-neutral-900">
		<PageHeader title="My Characters" subtitle="Manage your FFXIV characters and verification status." />
		<div class="w-full flex-col items-stretch space-y-4">
			<CharacterCard />
			<CharacterCard />
			<CharacterCard />
		</div>
	</div>
</template>

<style scoped>

</style>