<script setup lang="ts">
import { Link, useForm } from "@inertiajs/vue3";
import AuthLayoutV2 from "@/Layouts/AuthLayoutV2.vue";
import SeoHead from "@/components/Shared/SeoHead.vue";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { usePasswordVisibility } from "@/composables/usePasswordVisibility";

const props = defineProps<{
	token: string
	email: string | null
}>();

const { t } = useI18n();
const passwordVisibility = usePasswordVisibility(['password', 'password_confirmation'] as const);

const form = useForm({
	token: props.token,
	email: props.email ?? '',
	password: '',
	password_confirmation: '',
});

const submit = () => {
	form.post(route('password.update'));
};

defineOptions({
	layout: AuthLayoutV2,
});
</script>

<template>
	<SeoHead
		:title="t('auth.reset_password_page.title')"
		:description="t('meta.seo.auth.reset_password_description')"
		noindex
	/>

	<div>
		<div class="mb-6 text-center">
			<p class="text-2xl font-semibold text-toned">{{ t('auth.reset_password_page.title') }}</p>
			<p class="mt-2 text-sm text-muted">{{ t('auth.reset_password_page.subtitle') }}</p>
		</div>

		<form class="space-y-4" @submit.prevent="submit">
			<UFormField name="email" class="w-full" :error="form.errors.email">
				<UInput
					:model-value="form.email"
					type="email"
					size="xl"
					class="w-full"
					:placeholder="t('general.email')"
					disabled
				/>
			</UFormField>

			<UFormField name="password" class="w-full" :error="form.errors.password">
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

			<UFormField name="password_confirmation" class="w-full">
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

			<UButton
				type="submit"
				color="brand"
				size="xl"
				class="w-full justify-center"
				:disabled="form.processing"
			>
				{{ form.processing ? t('auth.reset_password_page.resetting') : t('auth.reset_password_page.submit') }}
			</UButton>
		</form>

		<div class="flex items-center justify-center pt-8 text-center text-sm">
			<Link :href="route('login')" class="text-brand">{{ t('auth.back_to_login') }}</Link>
		</div>
	</div>
</template>
