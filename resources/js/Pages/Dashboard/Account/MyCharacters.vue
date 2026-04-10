<script setup lang="ts">
import {useI18n} from "vue-i18n";
import {computed, ref} from "vue";
import PageHeader from "@/components/PageHeader.vue";
import CharacterCard from "@/components/Pages/Characters/CharacterCard.vue";
import AddCharacterModal from "@/components/Characters/AddCharacterModal.vue";
import ManualVerificationModal from "@/components/Characters/ManualVerificationModal.vue";
import XIVAuthCharacterImportModal from "@/components/Characters/XIVAuthCharacterImportModal.vue";
import {usePage} from "@inertiajs/vue3";
const { t } = useI18n()
const page = usePage();

const user = computed(() => page.props.auth?.user)
const hasProvider = (provider_name) => {
	const provider = user.value.social_accounts.find(account => account.provider === provider_name);
	return !!provider
}

const getProvider = (provider_name) => {
	const provider = user.value.social_accounts.find(account => account.provider === provider_name);
	return provider ?? null;
}


const addModal = ref<InstanceType<typeof AddCharacterModal> | null>(null);
const manualModal = ref<InstanceType<typeof ManualVerificationModal> | null>(null);
const xivModal = ref<InstanceType<typeof XIVAuthCharacterImportModal> | null>(null);
const handleChoice = (param) => {
	switch (param) {
		case 'xivauth':
			addModal.value?.hide();
			setTimeout(() => xivModal.value?.open(), 250);
			break;
		default:
			addModal.value?.hide();
			setTimeout(() => manualModal.value?.open(), 250);

			break;
	}
}

const modalResult = (result) => {
	manualModal.value?.hide();
	xivModal.value?.hide();
	if(!result) {
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
	<div class="w-full bg-neutral-100 dark:bg-neutral-900">
		<PageHeader :title="t('characters.title')" :subtitle="t('characters.subtitle')">
			<AddCharacterModal @choice="handleChoice" :xivauth_connected="hasProvider('xivauth')" ref="addModal"/>
			<ManualVerificationModal @close="modalResult" ref="manualModal"/>
			<XIVAuthCharacterImportModal @close="modalResult" :provider="getProvider('xivauth')" ref="xivModal"/>
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