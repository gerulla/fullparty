<script setup lang="ts">
import {useI18n} from "vue-i18n";
import {ref} from "vue";
import {useForm} from "@inertiajs/vue3";
import {route} from "ziggy-js";
const props = defineProps({
	user: Object
})
const { t } = useI18n();

const form = useForm({
	public_profile: props.user.public_profile,
	public_characters: props.user.public_characters,
});

const items = ref([
	{
		label: t('general.public'),
		value: true
	},
	{
		label: t('general.private'),
		value: false
	}
]);

const submit = () => {
	form.post(route('settings.privacy'));
}
</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-row items-center font-semibold text-md">
				<UIcon name="i-lucide-lock" class="mr-2" size="22"/>
				<p>{{ t('settings.privacy.title') }}</p>
			</div>
		</template>
			<form @submit.prevent="submit" class="w-full flex flex-col items-stretch gap-4 mb-4">
				<div class="option">
					<div>
						<p class="font-semibold">{{ t('settings.privacy.profile_visibility') }}</p>
						<p class="text-sm">{{ t('settings.privacy.profile_visibility_description') }}</p>
					</div>
					<USelect class="min-w-24" v-model="form.public_profile" :items="items"/>
				</div>
				<div class="option">
					<div>
						<p class="font-semibold">{{ t('settings.privacy.show_character_data') }}</p>
						<p class="text-sm">{{ t('settings.privacy.show_character_data_description') }}</p>
					</div>
					<UCheckbox v-model="form.public_characters" />
				</div>
				<div class="flex">
					<UButton type="submit" :label="t('settings.privacy.save')" size="lg" color="neutral"/>
				</div>
			</form>
			<div class="flex flex-col items-start gap-4 mb-4 border-t border-neutral-200 pt-4">
				<div class="w-full">
					<p class="font-semibold">{{ t('settings.privacy.danger_zone') }}</p>
					<p class="text-sm">{{ t('settings.privacy.delete_account_description') }}</p>
				</div>
				<UButton :label="t('settings.privacy.delete_account')" size="lg" color="error"/>
			</div>
	</UCard>
</template>

<style scoped>
@reference "../../../../css/app.css";
.option {
	@apply w-full flex flex-row items-center justify-between;
}
</style>
