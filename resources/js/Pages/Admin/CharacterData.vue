<script setup lang="ts">
import { useI18n } from "vue-i18n";
import PageHeader from "@/components/PageHeader.vue";
import CharacterDefinitions from "@/components/Admin/CharacterDefinitions.vue";
import CharacterClasses from "@/components/Admin/CharacterClasses.vue";
import PhantomJobs from "@/components/Admin/PhantomJobs.vue";

defineProps({
	definitions: Array,
	characterClasses: Array,
	phantomJobs: Array
});

const { t } = useI18n();
</script>

<template>
	<div class="w-full">
		<PageHeader :title="t('admin.character_definitions.title')" :subtitle="t('admin.character_definitions.subtitle')" />

		<div class="w-full flex flex-col items-start gap-6 mt-4">
			<div class="w-full flex flex-row items-start gap-6">
				<CharacterClasses :character-classes="characterClasses" />
				<PhantomJobs :phantom-jobs="phantomJobs" />
			</div>
			<CharacterDefinitions :definitions="definitions" />
		</div>
	</div>
</template>

<style scoped>
</style>
