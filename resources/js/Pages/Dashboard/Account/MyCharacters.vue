<script setup lang="ts">
import {useI18n} from "vue-i18n";
import { ref } from "vue";
import PageHeader from "@/components/PageHeader.vue";
import CharacterCard from "@/components/Pages/Characters/CharacterCard.vue";
import AddCharacterModal from "@/components/Characters/AddCharacterModal.vue";
import ManualVerificationModal from "@/components/Characters/ManualVerificationModal.vue";
const { t } = useI18n()

const addModal = ref<InstanceType<typeof AddCharacterModal> | null>(null);
const manualModal = ref<InstanceType<typeof ManualVerificationModal> | null>(null);
const xivModal = ref<InstanceType<typeof ManualVerificationModal> | null>(null);
const handleChoice = (param) => {
	switch (param) {
		case 'xivauth':
			break;
		default:
			addModal.value?.hide();
			setTimeout(() => manualModal.value?.open(), 250);

			break;
	}
}

const modalResult = (result) => {
	if(!result) {
		manualModal.value?.hide();
		setTimeout(() => addModal.value?.open(), 250);
	}
}

defineProps({
	characters: {
		type: Array,
		required: true,
	},
})
</script>

<template>
	<div class="w-full min-h-screen sm:px-4 md:px-6 bg-neutral-100 dark:bg-neutral-900">
		<PageHeader :title="t('characters.title')" :subtitle="t('characters.subtitle')">
			<AddCharacterModal @choice="handleChoice" ref="addModal"/>
			<ManualVerificationModal @close="modalResult" ref="manualModal"/>
		</PageHeader>
		<div class="w-full flex-col items-stretch space-y-4">
			<CharacterCard
				v-for="character in characters"
				:key="character.id"
				:character="character"
			/>
		</div>
	</div>
</template>

<style scoped>

</style>