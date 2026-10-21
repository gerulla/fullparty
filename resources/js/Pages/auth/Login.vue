<script setup lang="ts">
import { computed } from "vue";
import {useForm, Link, usePage} from '@inertiajs/vue3'
import AuthLayoutV2 from '@/Layouts/AuthLayoutV2.vue'
import SeoHead from "@/components/Shared/SeoHead.vue";
import LoginWithXIVAuth from "@/components/LoginWithXIVAuth.vue";
import LoginWithGoogle from "@/components/LoginWithGoogle.vue";
import LoginWithDiscord from "@/components/LoginWithDiscord.vue";
import {useI18n} from "vue-i18n";
import { route } from "ziggy-js";
import { usePasswordVisibility } from "@/composables/usePasswordVisibility";

const { t } = useI18n();
const page = usePage();
const successFlags = computed(() => (page.props.flash?.success ?? []) as string[]);
const wasPasswordReset = computed(() => successFlags.value.includes('password_reset'));
const passwordVisibility = usePasswordVisibility(['password'] as const);

const form = useForm({
	login: '',
	password: '',
	remember: false,
})

const submit = () => {
	form.post(route('login.store'))
}
defineOptions({
	layout: AuthLayoutV2
})

</script>

<template>
	<SeoHead
		:title="t('auth.login')"
		:description="t('meta.seo.auth.login_description')"
		noindex
	/>
	<div>
		<UAlert
			v-if="wasPasswordReset"
			color="success"
			variant="subtle"
			icon="i-lucide-badge-check"
			:title="t('auth.reset_password_page.complete_title')"
			:description="t('auth.reset_password_page.complete_description')"
			class="mb-4 bg-success-50 text-success-800 ring-success-200 dark:bg-success/10 dark:text-success dark:ring-success/25"
		/>

		<div class="space-y-2 mb-4">
			<LoginWithXIVAuth />
			<LoginWithGoogle />
			<LoginWithDiscord />
		</div>

		<USeparator color="brand" :label="t('general.or')" class="mb-4 py-4"
			:ui="{ border: 'border-brand/50', label: 'text-xs font-semibold uppercase tracking-wide text-brand' }" />

		<div class="flex items-center w-full mb-4">
			<form class="space-y-4 w-full" @submit.prevent="submit">
				<UFormField name="login" class="w-full" :error="form.errors.login">
					<UInput v-model="form.login" size="xl" class="w-full" :placeholder="t('general.email_or_username')"/>
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
				<UCheckbox color="brand" :label="t('auth.remember_me')" name="remember" v-model="form.remember"/>
				<UButton type="submit" color="brand" size="xl" class="w-full justify-center" :disabled="form.processing">
					{{ t('auth.login') }}
				</UButton>
			</form>
		</div>

		<div class="flex w-full flex-col items-center justify-center gap-2 pt-4 text-center text-sm">
			<Link :href="route('password.request')" class="text-brand">{{ t('auth.forgot_password') }}</Link>
			<p>{{ t('auth.no_account') }} <Link :href="route('register')" class="text-brand">{{ t('auth.sign_up_now') }}</Link></p>
		</div>
	</div>
</template>

<style scoped>

</style>
