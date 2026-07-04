<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthLayout from "@/Layouts/AuthLayout.vue";
import SeoHead from "@/components/Shared/SeoHead.vue";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	prefilledUserCode?: string
	status?: string | null
}>();

const { t } = useI18n({ useScope: "global" });

const form = useForm({
	user_code: props.prefilledUserCode ?? "",
});

const submit = () => {
	form.get(route("xivplugin.device.authorize"), {
		preserveScroll: false,
	});
};

defineOptions({
	layout: AuthLayout,
});
</script>

<template>
	<SeoHead
		:title="t('xivplugin.device.title')"
		:description="t('xivplugin.device.description')"
		noindex
	/>

	<div>
		<UAlert
			v-if="status === 'authorization-approved'"
			color="success"
			variant="subtle"
			icon="i-lucide-circle-check"
			:title="t('xivplugin.device.approved_title')"
			:description="t('xivplugin.device.approved_description')"
			class="mb-4"
		/>

		<UAlert
			v-if="status === 'authorization-denied'"
			color="warning"
			variant="subtle"
			icon="i-lucide-circle-alert"
			:title="t('xivplugin.device.denied_title')"
			:description="t('xivplugin.device.denied_description')"
			class="mb-4"
		/>

		<div class="mb-6 text-center">
			<div class="mx-auto mb-4 flex size-14 items-center justify-center border border-brand/40 bg-brand/10 text-brand">
				<UIcon name="i-lucide-gamepad-2" class="size-7" />
			</div>
			<p class="text-2xl font-semibold text-toned">{{ t('xivplugin.device.title') }}</p>
			<p class="mt-2 text-sm leading-6 text-muted">{{ t('xivplugin.device.subtitle') }}</p>
		</div>

		<form class="space-y-4" @submit.prevent="submit">
			<UFormField name="user_code" :label="t('xivplugin.device.code_label')" :error="form.errors.user_code">
				<UInput
					v-model="form.user_code"
					size="xl"
					class="w-full"
					:placeholder="t('xivplugin.device.code_placeholder')"
					autocomplete="one-time-code"
				/>
			</UFormField>

			<UButton
				type="submit"
				color="brand"
				size="xl"
				class="w-full justify-center"
				:disabled="form.processing"
			>
				{{ form.processing ? t('xivplugin.device.continuing') : t('xivplugin.device.continue') }}
			</UButton>
		</form>
	</div>
</template>
