<script setup lang="ts">
import {useI18n} from "vue-i18n";
import {useForm, usePage} from "@inertiajs/vue3";
import {route} from "ziggy-js";

const props = defineProps({
	user: Object
})

const { t } = useI18n();
const form = useForm({
	username: props.user.name ?? ''
})
const submit = () => {
	form.post(route('settings.username'));
}
</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-row items-center font-semibold text-md">
				<UIcon name="i-lucide-user" class="mr-2" size="22"/>
				<p>{{t('settings.account.title')}}</p>
			</div>
		</template>
		<form @submit.prevent="submit" class="w-full flex flex-col items-start gap-4">
			<UFormField class="w-full" :label="t('general.username')">
				<UInput v-model="form.username" :placeholder="t('general.username')" size="xl" class="w-full"/>
			</UFormField>
			<UFormField class="w-full" :label="t('general.email')">
				<UInput v-model="user.email" :placeholder="t('general.email')" size="xl" class="w-full" disabled/>
			</UFormField>
			<UButton type="submit" :label="t('settings.account.save')" size="lg" color="neutral" :loading="form.processing"/>
		</form>
	</UCard>
</template>

<style scoped>

</style>
