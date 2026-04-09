<script setup lang="ts">

import {ref} from "vue";
import {useI18n} from "vue-i18n";
import {useForm} from "@inertiajs/vue3";
import {route} from "ziggy-js";
const { t } = useI18n();
const self_open = ref(false);
const step = ref(1);
const max_steps = ref(4);

const props = defineProps<{
	provider: null
}>();

const form = useForm({
	xivauth_token: props.provider ? props.provider.access_token : null,
	xivauth_refresh_token: props.provider ? props.provider.refresh_token : null
});

const submit = () => {
	form.post(route('characters.xivauth'));
}

// Display toggles n stuff
const emit = defineEmits<{ close: [boolean] }>();
const open = () => {
	if(props.provider == null) return;
	step.value = 1;
	self_open.value = true;
	console.log(props.provider)
}
const hide = () => {
	self_open.value = false;
}
const close = (status = false) => {
	step.value = 1;
	emit('close', status);
}

defineExpose({
	open, hide
})
</script>

<template>
	<UModal
		v-model:open="self_open"
		:ui="{ content: 'rounded-sm', header: 'border-0'}"
	>
		<template #header>
			<div class="w-full flex flex-col items-stretch">
				<div class="w-full flex flex-row items-stretch justify-between mb-1">
					<p class="text-xs text muted uppercase">
						{{t('characters.manual.progress', {current: step, total: max_steps})}}
					</p>
					<p class="text-xs text muted uppercase">{{t('characters.manual.steps.'+(step-1))}}</p>
				</div>
				<UProgress v-model="step" :max="max_steps" :ui="{base:'rounded-none', indicator: 'rounded-none'}"/>
			</div>
		</template>

		<template #body>
			<form @submit.prevent="submit" class="w-full flex flex-col items-stretch gap-4">
				<UButton type="submit"> Click LOL </UButton>
			</form>
			<p>{{JSON.stringify(props.provider, null, 4)}}</p>
		</template>
	</UModal>
</template>

<style scoped>

</style>