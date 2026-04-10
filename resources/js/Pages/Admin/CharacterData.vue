<script setup lang="ts">
import { useI18n } from "vue-i18n";
import PageHeader from "@/components/PageHeader.vue";
import CharacterDefinitions from "@/components/Admin/CharacterDefinitions.vue";
import CharacterClasses from "@/components/Admin/CharacterClasses.vue";

defineProps({
	definitions: Array,
	characterClasses: Array
});

const { t } = useI18n();
</script>

<template>
	<div class="w-full">
		<PageHeader :title="t('admin.character_definitions.title')" :subtitle="t('admin.character_definitions.subtitle')" />

		<div class="w-full flex flex-col items-start gap-6 mt-4">
			<CharacterDefinitions :definitions="definitions" />
			<CharacterClasses :character-classes="characterClasses" />
		</div>
	</div>
</template>

<style scoped>
</style>
