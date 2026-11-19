<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import AdminPageHeader from "@/components/Admin/AdminPageHeader.vue";
import CharacterDefinitions from "@/components/Admin/CharacterDefinitions.vue";
import CharacterClasses from "@/components/Admin/CharacterClasses.vue";
import PhantomJobs from "@/components/Admin/PhantomJobs.vue";

defineProps({
	definitions: Array,
	characterClasses: Array,
	phantomJobs: Array
});

const { t } = useI18n();
const selectedSection = ref('classes');
const sectionTabs = computed(() => [
	{ value: 'classes', label: t('admin.character_classes.section_title'), icon: 'i-lucide-swords', slot: 'classes' },
	{ value: 'phantom', label: t('admin.phantom_jobs.section_title'), icon: 'i-lucide-wand-sparkles', slot: 'phantom' },
	{ value: 'definitions', label: t('admin.character_definitions.table_header'), icon: 'i-lucide-database', slot: 'definitions' },
]);
</script>

<template>
	<div class="min-w-0">
		<AdminPageHeader :title="t('admin.character_definitions.title')" :subtitle="t('admin.character_definitions.subtitle')" />

		<UTabs v-model="selectedSection" :items="sectionTabs" :unmount-on-hide="false" variant="link" class="mt-6 min-w-0 gap-5" :ui="{ list: 'max-w-full justify-start overflow-x-auto', trigger: 'shrink-0 grow-0' }">
			<template #classes>
				<CharacterClasses :character-classes="characterClasses" />
			</template>
			<template #phantom>
				<PhantomJobs :phantom-jobs="phantomJobs" />
			</template>
			<template #definitions>
				<CharacterDefinitions :definitions="definitions" />
			</template>
		</UTabs>
	</div>
</template>
