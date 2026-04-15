<script setup lang="ts">
import {useForm, Link, Head} from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import LoginWithXIVAuth from "@/components/LoginWithXIVAuth.vue";
import LoginWithGoogle from "@/components/LoginWithGoogle.vue";
import LoginWithDiscord from "@/components/LoginWithDiscord.vue";
import {useI18n} from "vue-i18n";

const { t } = useI18n();

const form = useForm({
	email: '',
	password: '',
	remember: false,
})

const submit = () => {
	form.post('/auth/login')
}
defineOptions({
	layout: AuthLayout
})

</script>

<template>
	<Head title="Login -" />
	<div>
		<div class="mb-1 mx-auto">
			<p class="italic text-center text-gray-600 dark:text-gray-300">{{ t('auth.express_options') }}</p>
		</div>
		<div class="space-y-2 mb-4">
			<LoginWithXIVAuth />
			<LoginWithGoogle />
			<LoginWithDiscord />
		</div>

		<div class="flex items-center gap-4 mb-4">
			<div class="h-px flex-1 bg-slate-300 dark:bg-slate-600"></div>
			<span class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Or</span>
			<div class="h-px flex-1 bg-slate-300 dark:bg-slate-600"></div>
		</div>

		<div class="flex items-center w-full mb-4">
			<form class="space-y-4 w-full" @submit.prevent="submit">
				<UFormField name="email" class="w-full" :error="form.errors.email">
					<UInput v-model="form.email" size="xl" class="w-full" :placeholder="t('general.email')"/>
				</UFormField>

				<UFormField name="password" :error="form.errors.password">
					<UInput v-model="form.password" type="password" size="xl" class="w-full" :placeholder="t('auth.password')" />
				</UFormField>
				<UCheckbox :label="t('auth.remember_me')" name="remember" v-model="form.remember"/>
				<UButton type="submit" color="brand" size="xl" class="w-full justify-center" :disabled="form.processing">
					{{ t('auth.login') }}
				</UButton>
			</form>
		</div>

		<div class="flex items-center justify-center flex-col space-y-2 w-full">
			<Link href="/auth/password" class="text-brand">{{ t('auth.forgot_password') }}</Link>
			<p>{{ t('auth.no_account') }} <Link href="/auth/register" class="text-brand">{{ t('auth.sign_up_now') }}</Link></p>
		</div>
	</div>
</template>

<style scoped>

</style>
