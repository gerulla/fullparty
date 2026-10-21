<script setup lang="ts">
import {useForm, Link} from '@inertiajs/vue3'
import AuthLayoutV2 from '@/Layouts/AuthLayoutV2.vue'
import SeoHead from "@/components/Shared/SeoHead.vue";
import LoginWithXIVAuth from "@/components/LoginWithXIVAuth.vue";
import LoginWithGoogle from "@/components/LoginWithGoogle.vue";
import LoginWithDiscord from "@/components/LoginWithDiscord.vue";
import { route } from "ziggy-js";
import {useI18n} from "vue-i18n";
import { usePasswordVisibility } from "@/composables/usePasswordVisibility";

const { t } = useI18n();
const passwordVisibility = usePasswordVisibility(['password', 'password_confirmation'] as const);
const form = useForm({
	username: '',
	email: '',
	password: '',
	password_confirmation: '',
})

const submit = () => {
	form.post(route('register.store'))
}
defineOptions({
	layout: AuthLayoutV2
})
</script>

<template>
	<SeoHead
		:title="t('auth.register')"
		:description="t('meta.seo.auth.register_description')"
		noindex
	/>
	<div>
		<div class="space-y-2 mb-4">
			<LoginWithXIVAuth />
			<LoginWithGoogle />
			<LoginWithDiscord />
		</div>

		<USeparator color="brand" :label="t('general.or')" class="mb-4 py-4"
			:ui="{ border: 'border-brand/50', label: 'text-xs font-semibold uppercase tracking-wide text-brand' }" />

		<div class="flex items-center w-full mb-4">
			<form class="space-y-4 w-full" @submit.prevent="submit">
				<UFormField name="username" class="w-full" :error="form.errors.username">
					<UInput v-model="form.username" size="xl" class="w-full" :placeholder="t('general.username')"/>
				</UFormField>

				<UFormField name="email" class="w-full" :error="form.errors.email">
					<UInput v-model="form.email" type="email" size="xl" class="w-full" :placeholder="t('general.email')"/>
				</UFormField>

				<UFormField name="password" :error="form.errors.password">
					<UInput
						v-model="form.password"
						:type="passwordVisibility.inputType('password')"
						size="xl"
						class="w-full"
						:placeholder="t('auth.password')"
						:ui="{ trailing: 'pe-1' }"
					>
						<template #trailing>
							<UButton
								type="button"
								color="neutral"
								variant="ghost"
								size="sm"
								:icon="passwordVisibility.icon('password')"
								:aria-label="t('auth.password')"
								@click="passwordVisibility.toggle('password')"
							/>
						</template>
					</UInput>
				</UFormField>

				<UFormField name="password_confirmation">
					<UInput
						v-model="form.password_confirmation"
						:type="passwordVisibility.inputType('password_confirmation')"
						size="xl"
						class="w-full"
						:placeholder="t('auth.password_confirmation')"
						:ui="{ trailing: 'pe-1' }"
					>
						<template #trailing>
							<UButton
								type="button"
								color="neutral"
								variant="ghost"
								size="sm"
								:icon="passwordVisibility.icon('password_confirmation')"
								:aria-label="t('auth.password_confirmation')"
								@click="passwordVisibility.toggle('password_confirmation')"
							/>
						</template>
					</UInput>
				</UFormField>
				<UButton type="submit" color="brand" size="xl" class="w-full justify-center" :disabled="form.processing">
					{{ t('auth.register') }}
				</UButton>
			</form>
		</div>

		<div class="flex w-full flex-col items-center justify-center gap-2 pt-4 text-center text-sm">
			<p>{{ t('auth.existing_account') }} <Link :href="route('login')" class="text-brand">{{ t('auth.log_in_now') }}</Link></p>
		</div>
	</div>
</template>

<style scoped>

</style>
