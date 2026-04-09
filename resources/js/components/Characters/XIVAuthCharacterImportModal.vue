<script setup lang="ts">

import {ref, watch} from "vue";
import {useI18n} from "vue-i18n";
import {useForm, usePage} from "@inertiajs/vue3";
import {route} from "ziggy-js";
import {useToast} from "@nuxt/ui/composables";
const { t } = useI18n();
const page = usePage()
const toast = useToast()
const self_open = ref(false);
const step = ref(1);
const max_steps = ref(4);
const characters = ref([]);
const character = ref(null);

const props = defineProps<{
	provider: null
}>();

const form = useForm();
const character_form = useForm({
	name: '',
	world:'',
	datacenter: '',
	lodestone_id: '',
	avatar_url: '',
})

const fetch_characters = () => {
	form.post(route('characters.xivauth'), {
		preserveState: true,
		preserveScroll: true,
		onSuccess: () => {
			const data = page.props.flash?.data;
			characters.value = data.characters;
			step.value = 2;
		},
		onError: () => {
			const data = page.props;
			if(!data || !data.error) return;
			toast.add({
				title: t('general.error'),
				description: t('characters.manual.errors.'+data.error),
				icon: 'i-lucide-circle-alert',
				color: 'error'
			})
		}
	});
}

const import_character = (index: number) => {
	step.value = 3;
	const char = characters.value[index];
	character.value = characters.value[index];
	character_form.name = char.name;
	character_form.world = char.home_world;
	character_form.datacenter = char.data_center;
	character_form.lodestone_id = char.lodestone_id;
	character_form.avatar_url = char.avatar_url;

	character_form.post(route('characters.xivauth.import'), {
		onSuccess: () => {
			const result = page.props.flash?.data?.xivauth_character_import
			console.log(result);
			step.value = 4;
		},
		onError: () => {
			const data = page.props;
			if(!data || !data.error) return;
			toast.add({
				title: t('general.error'),
				description: t('characters.manual.errors.'+data.error),
				icon: 'i-lucide-circle-alert',
				color: 'error'
			})
		}
	})
}

// Display toggles n stuff
const emit = defineEmits<{ close: [boolean] }>();
const open = () => {
	if(props.provider == null) return;
	step.value = 1;
	self_open.value = true;
	fetch_characters()
	console.log(props.provider)
}
const hide = () => {
	self_open.value = false;
}
const close = (status = false) => {
	step.value = 1;
	characters.value = [];
	character.value = null;
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
						{{t('characters.xivauth.progress', {current: step, total: max_steps})}}
					</p>
					<p class="text-xs text muted uppercase">{{t('characters.xivauth.steps.'+(step-1))}}</p>
				</div>
				<UProgress v-model="step" :max="max_steps" :ui="{base:'rounded-none', indicator: 'rounded-none'}"/>
			</div>
		</template>

		<template #body>
			<div v-if="step==1" class="step-container">
				<div class="w-full flex flex-col items-center justify-center gap-2 py-8 text-center">
					<div class="bg-brand/30 p-4 mb-4 rounded-full">
						<UIcon
							name="i-lucide-loader-circle"
							class="animate-spin text-brand-500"
							size="42"
						/>
					</div>
					<p class="text-xl font-bold">{{ t('characters.xivauth.step1.title') }}</p>
					<p class="text-muted">{{ t('characters.xivauth.step1.subtitle') }}</p>
				</div>
			</div>
			<div v-if="step==2" class="step-container">
				<div class="flex flex-col gap-1 mb-4">
					<p class="font-bold">{{t('characters.xivauth.step2.title')}}</p>
					<p class="text-sm text-muted">{{t('characters.xivauth.step2.subtitle')}}</p>
				</div>
				<div
					v-if="characters.length > 0"
					v-for="(character, index) in characters" :key="index"
					@click.prevent="import_character(index)"
					class="character-option">
					<div class="h-full">
						<img class="h-18 w-18 rounded-sm" :src="character.avatar_url" :alt="character.name + ' avatar'">
					</div>
					<div class="flex flex-col items-start ">
						<p class="font-bold">{{character.name}}</p>
						<p class="text-sm text-muted">{{character.home_world}} - {{character.data_center}}</p>
					</div>
					<div class="ml-auto">
						<UIcon name="i-lucide-chevron-right" size="24" class="" />
					</div>
				</div>
			</div>
			<div v-if="step==3" class="step-container">
				<div class="w-full flex flex-col items-center justify-center gap-2 py-8 text-center">
					<div class="bg-brand/30 p-4 mb-4 rounded-full">
						<UIcon
							name="i-lucide-loader-circle"
							class="animate-spin text-brand-500"
							size="42"
						/>
					</div>
					<p class="text-xl font-bold">{{ t('characters.xivauth.step3.title') }}</p>
					<p class="text-muted">{{ t('characters.xivauth.step3.subtitle') }}</p>
				</div>
			</div>
			<div v-if="step==4" class="step-container">
				<div class="">
					<div class="w-full flex flex-col items-center justify-center gap-2 mb-2">
						<div class="bg-success/10 p-4 mb-4 rounded-full">
							<UIcon
								name="i-lucide-check-circle"
								class="text-success-600"
								size="42"
							/>
						</div>
						<p class="text-lg font-bold">{{ t('characters.xivauth.success.title') }}</p>
						<p class=" text-sm text-muted">{{ t('characters.xivauth.success.subtitle') }}</p>
					</div>
					<div v-if="character" class="w-full flex flex-row items-center p-4 border border-muted rounded-sm gap-4">
						<div class="h-full">
							<img class="h-18 w-18 rounded-sm" :src="character.avatar_url" :alt="character.name + ' avatar'">
						</div>
						<div class="flex flex-col items-start ">
							<p class="font-bold">{{character.name}}</p>
							<p class="text-sm text-muted">{{character.home_world}} - {{character.data_center}}</p>
						</div>
					</div>
					<UButton
						:label="t('characters.xivauth.success.button')"
						color="neutral"
						size="lg"
						class="w-full my-4 justify-center rounded-none"
						@click.prevent="close(true)"
					/>
				</div>
			</div>
		</template>
	</UModal>
</template>

<style scoped>
@reference '../../../css/app.css';
.step-container {
	@apply w-full h-full flex flex-col items-stretch gap-4
}
.character-option {
	@apply w-full flex flex-row items-center p-4 border border-muted rounded-sm gap-4
		hover:border-brand cursor-pointer transition-all hover:text-brand text-muted
}
</style>