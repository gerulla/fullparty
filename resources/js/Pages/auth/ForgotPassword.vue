<script setup lang="ts">
import { computed } from "vue";
import { Link, useForm, usePage } from "@inertiajs/vue3";
import AuthLayoutV2 from "@/Layouts/AuthLayoutV2.vue";
import SeoHead from "@/components/Shared/SeoHead.vue";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";

const { t } = useI18n();
const page = usePage();
const successFlags = computed(() => (page.props.flash?.success ?? []) as string[]);

const form = useForm({
	email: '',
});

const submit = () => {
	form.post(route('password.email'));
};

const wasResetLinkSent = computed(() => successFlags.value.includes('password_reset_link_sent'));

defineOptions({
	layout: AuthLayoutV2,
});
</script>

<template>
	<SeoHead
		:title="t('auth.forgot_password_page.title')"
		:description="t('meta.seo.auth.forgot_password_description')"
		noindex
	/>

	<div>
		<div class="mb-6 text-center">
			<p class="text-2xl font-semibold text-toned">{{ t('auth.forgot_password_page.title') }}</p>
			<p class="mt-2 text-sm text-muted">{{ t('auth.forgot_password_page.subtitle') }}</p>
		</div>

		<UAlert
			v-if="wasResetLinkSent"
			color="success"
			variant="subtle"
			icon="i-lucide-mail-check"
			:title="t('auth.forgot_password_page.success_title')"
			:description="t('auth.forgot_password_page.success_description')"
			class="mb-4 bg-success-50 text-success-800 ring-success-200 dark:bg-success/10 dark:text-success dark:ring-success/25"
		/>

		<form class="space-y-4" @submit.prevent="submit">
			<UFormField name="email" class="w-full" :error="form.errors.email">
				<UInput
					v-model="form.email"
					type="email"
					size="xl"
					class="w-full"
					:placeholder="t('general.email')"
				/>
			</UFormField>

			<UButton
				type="submit"
				color="brand"
				size="xl"
				class="w-full justify-center"
				:disabled="form.processing"
			>
				{{ form.processing ? t('auth.forgot_password_page.sending') : t('auth.forgot_password_page.submit') }}
			</UButton>
		</form>

		<div class="flex items-center justify-center pt-8 text-center text-sm">
			<Link :href="route('login')" class="text-brand">{{ t('auth.back_to_login') }}</Link>
		</div>
	</div>
</template>
