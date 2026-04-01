<script setup lang="ts">
import {useI18n} from "vue-i18n";
import {computed, ref} from 'vue';
import {Link, useForm, usePage} from '@inertiajs/vue3'
import {route} from 'ziggy-js';
import {useToast} from "@nuxt/ui/composables";
import {useClipboard} from '@vueuse/core';
import LoginWithGoogle from "@/resources/js/components/LoginWithGoogle.vue";

const { t } = useI18n()
const toast = useToast()
const page = usePage()

const self_open = ref(false);
const step = ref(1);
const max_steps = ref(4);
const verification_success = ref(false);
const character = ref(null);
// Verification Process
const checkExistsForm = useForm({
	lodestone_id: ''
});
const checkExists = () => {
	checkExistsForm.post(route('characters.exists'), {
		preserveState: true,
		preserveScroll: true,
		onSuccess: () => {
			const result = page.props.flash?.data?.manual_character_lookup
			console.log(result)

			if(result?.taken){
				toast.add({
					title: t('characters.manual.step2.toast_taken.title'),
					description: t('characters.manual.step2.toast_taken.description'),
					icon: 'i-lucide-circle-alert',
					color: 'error'
				})
				return;
			}

			if (result?.exists) {
				character.value = result.character
				verificationCode.value = character.value.token
				step.value = 2
			}
		},
		onError: (data) => {
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

const verifyForm = useForm({
	token: '',
	character_id: ''
});
const verify = () => {
	//Move to loading section
	step.value = 3;
	//Run Verification
	verifyForm.token = verificationCode.value;
	verifyForm.character_id = character.value.id;
	verifyForm.post(route('characters.verify'), {
		preserveState: true,
		preserveScroll: true,
		onSuccess: () => {
			const result = page.props.flash?.data?.character_verification
			console.log(result)
			// Move to end Screen
			verification_success.value = true;
			step.value = 4;
		},
		onError: (data) => {
			if(!data || !data.error) return;
			toast.add({
				title: t('general.error'),
				description: t('characters.manual.errors.'+data.error),
				icon: 'i-lucide-circle-alert',
				color: 'error'
			})
			// Move to end Screen
			verification_success.value = false;
			step.value = 4;
		}
	})
}

// Verification Code
const verificationCode = ref('FP-088322KJS');
const {copy, copied, isSupported} = useClipboard({source: verificationCode})

const copyVerificationCode = async () => {
	try {
		await copy();

		toast.add({
			title: t('characters.manual.step2.toast_success.title'),
			description: t('characters.manual.step2.toast_success.description'),
			icon: 'i-lucide-check',
			color: 'success'
		})
	} catch (error) {
		toast.add({
			title: t('characters.manual.step2.toast_error.title'),
			description: t('characters.manual.step2.toast_error.description'),
			icon: 'i-lucide-circle-alert',
			color: 'error'
		})
	}
}

// Display toggles n stuff
const emit = defineEmits<{ close: [boolean] }>();
const open = () => {
	step.value = 1;
	self_open.value = true;
}
const hide = () => {
	self_open.value = false;
}
const close = (status = false) => {
	step.value = 1;
	verification_success.value = false;
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
			<div v-if="step != 4" class="w-full flex flex-col items-stretch">
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
			<form @submit.prevent="checkExists" v-if="step==1" class="step-container">
				<div class="flex flex-col gap-1 mb-4">
					<p class="font-bold">{{t('characters.manual.step1.title')}}</p>
					<p class="text-sm text-muted">{{t('characters.manual.step1.subtitle')}}</p>
				</div>
				<UFormField
					class="w-full"
					:label="t('characters.manual.step1.input.label')"
					:help="t('characters.manual.step1.input.hint')"
					:error="checkExistsForm.errors.lodestone_id"
				>
					<UInput
						:ui="{base: 'rounded-none'}"
						class="w-full"
						size="xl"
						:placeholder="t('characters.manual.step1.input.placeholder')"
						v-model="checkExistsForm.lodestone_id"
					/>
				</UFormField>
				<div class="w-full flex flex-col p-4 border border-muted rounded-sm">
					<p class="text-sm text-muted">
						<I18nT keypath="characters.manual.step1.help_line" tag="span">
							<template #label>
								<strong>{{ $t('characters.manual.step1.where_to_find') }}</strong>
							</template>

							<template #lodestone>
								<a
									href="https://na.finalfantasyxiv.com/lodestone/"
									target="_blank"
									rel="noopener noreferrer"
									class="text-brand hover:underline"
								>
									{{ $t('characters.manual.step1.lodestone') }}
								</a>
							</template>
						</I18nT>
					</p>
				</div>
				<div class="w-full flex flex-row items-start gap-2 my-2">
					<UButton
						type="button"
						color="neutral"
						variant="outline"
						class="w-full"
						size="lg"
						:ui="{base:'rounded-none'}"
						:label="t('general.back')"
						@click.prevent="close();"
					/>
					<UButton
						type="submit"
						color="brand"
						class="w-full"
						size="lg"
						:ui="{base:'rounded-none'}"
						:label="t('general.continue')"
						:loading="checkExistsForm.processing"
						:disabled="checkExistsForm.lodestone_id.length == 0"
					/>
				</div>
			</form>
			<form @submit.prevent="verify" v-if="step==2" class="step-container">
				<div class="flex flex-col gap-1 mb-4">
					<p class="font-bold">{{t('characters.manual.step2.title')}}</p>
					<p class="text-sm text-muted">{{t('characters.manual.step2.subtitle')}}</p>
				</div>
				<div v-if="character" class="w-full flex flex-row items-center p-4 border border-muted rounded-sm gap-4">
					<div class="h-full">
						<img class="h-18 w-18 rounded-sm" :src="character.avatar" :alt="character.name + ' avatar'">
					</div>
					<div class="flex flex-col items-start ">
						<p class="font-bold">{{character.name}}</p>
						<p class="text-sm text-muted">{{character.world}} - {{character.datacenter}}</p>
					</div>
				</div>
				<div class="w-full flex flex-col gap-2">
					<p class="text-sm font-semibold">{{t('characters.manual.step2.code_label')}}</p>
					<div class="w-full flex flex-row items-center">
						<div class="w-full flex items-center justify-center py-4 border border-brand rounded-sm bg-brand/10">
							<p class="font-black uppercase text-xl">{{verificationCode}}</p>
						</div>
						<UButton
							:icon="copied ? 'i-lucide-check' : 'i-lucide-copy'"
							:color="copied ? 'success' : 'neutral'"
							variant="outline"
							class="ml-2 h-full"
							@click="copyVerificationCode"
						/>
					</div>
				</div>
				<div class="w-full flex flex-col p-4 border border-muted rounded-sm gap-2">
					<p class="text-sm font-bold">{{t('characters.manual.step2.instructions_label')}}</p>
					<p class="text-sm text-muted">{{t('characters.manual.step2.instructions.0')}}</p>
					<p class="text-sm text-muted">
						<I18nT keypath="characters.manual.step2.instructions.1" tag="span">
							<template #profile>
								<a
									href="https://na.finalfantasyxiv.com/lodestone/"
									target="_blank"
									rel="noopener noreferrer"
									class="text-brand hover:underline"
								>
									{{ $t('characters.manual.step2.profile') }} &nearr;
								</a>
							</template>
						</I18nT>
					</p>
					<p class="text-sm text-muted">{{t('characters.manual.step2.instructions.2')}}</p>
					<p class="text-sm text-muted">{{t('characters.manual.step2.instructions.3')}}</p>
					<p class="text-sm text-muted">{{t('characters.manual.step2.instructions.4')}}</p>
				</div>
				<div class="w-full flex flex-row items-start gap-2 my-2">
					<UButton
						type="button"
						color="neutral"
						variant="outline"
						class="w-full"
						size="lg"
						:ui="{base:'rounded-none'}"
						:label="t('general.back')"
						@click.prevent="step=1"
					/>
					<UButton
						type="submit"
						color="brand"
						class="w-full"
						size="lg"
						:ui="{base:'rounded-none'}"
						:label="t('general.verify_now')"
						:loading="verifyForm.processing"
					/>
				</div>
			</form>
			<div v-if="step==3" class="step-container">
				<div class="w-full flex flex-col items-center justify-center gap-2 py-8">
					<div class="bg-brand/30 p-4 mb-4 rounded-full">
						<UIcon
							name="i-lucide-loader-circle"
							class="animate-spin text-brand-500"
							size="42"
						/>
					</div>
					<p class="text-xl font-bold">{{ t('characters.manual.step3.title') }}</p>
					<p class="text-muted">{{ t('characters.manual.step3.subtitle') }}</p>
				</div>
			</div>
			<div v-if="step==4" class="step-container">
				<div v-if="verification_success" class="">
					<div class="w-full flex flex-col items-center justify-center gap-2 mb-2">
						<div class="bg-success/10 p-4 mb-4 rounded-full">
							<UIcon
								name="i-lucide-check-circle"
								class="text-success-600"
								size="42"
							/>
						</div>
						<p class="text-lg font-bold">{{ t('characters.manual.success.title') }}</p>
						<p class=" text-sm text-muted">{{ t('characters.manual.success.subtitle') }}</p>
					</div>
					<div v-if="character" class="w-full flex flex-row items-center p-4 border border-muted rounded-sm gap-4">
						<div class="h-full">
							<img class="h-18 w-18 rounded-sm" :src="character.avatar" :alt="character.name + ' avatar'">
						</div>
						<div class="flex flex-col items-start ">
							<p class="font-bold">{{character.name}}</p>
							<p class="text-sm text-muted">{{character.world}} - {{character.datacenter}}</p>
						</div>
					</div>
					<UButton
						:label="t('characters.manual.success.button')"
						color="neutral"
						size="lg"
						class="w-full my-4 justify-center rounded-none"
						@click.prevent="close(true)"
					/>
				</div>
				<div v-else class="">
					<div class="w-full flex flex-col items-center justify-center gap-2">
						<div class="bg-error/10 p-4 mb-4 rounded-full">
							<UIcon
								name="i-lucide-x"
								class="text-error-600"
								size="42"
							/>
						</div>
						<p class="text-lg font-bold">{{ t('characters.manual.fail.title') }}</p>
						<p class=" text-sm text-muted">{{ t('characters.manual.fail.subtitle') }}</p>
					</div>
					<div class="w-full flex flex-col gap-2 p-4 pb-0">
						<div class="w-full flex flex-row items-center">
							<div class="w-full flex items-center justify-center py-4 border border-brand rounded-sm bg-brand/10">
								<p class="font-black uppercase text-xl">{{verificationCode}}</p>
							</div>
						</div>
					</div>
					<div class="w-full flex flex-col p-4 my-4 border border-muted rounded-sm gap-2">
						<p class="text-sm font-bold">{{t('characters.manual.fail.common_label')}}</p>
						<p class="text-sm text-muted">{{t('characters.manual.fail.common_issues.0')}}</p>
						<p class="text-sm text-muted">{{t('characters.manual.fail.common_issues.1')}}</p>
						<p class="text-sm text-muted">{{t('characters.manual.fail.common_issues.2')}}</p>
					</div>
					<UButton
						:label="t('general.try_again')"
						icon="i-lucide-refresh-ccw"
						color="neutral"
						size="lg"
						class="w-full mb-2 mt-4 justify-center"
						@click.prevent="verify"
					/>
					<UButton
						:label="t('characters.manual.fail.button')"
						color="neutral"
						size="lg"
						variant="outline"
						class="w-full justify-center"
						@click.prevent="close"
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
</style>