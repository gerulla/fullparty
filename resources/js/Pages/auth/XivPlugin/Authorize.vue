<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthLayout from "@/Layouts/AuthLayout.vue";
import SeoHead from "@/components/Shared/SeoHead.vue";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	client: {
		id: string
		name: string
	}
	scopes: Array<{
		id: string
		description: string
	}>
	authToken: string
	state?: string | null
	userCode?: string | null
}>();

const { t } = useI18n({ useScope: "global" });

const authorizationPayload = {
	client_id: props.client.id,
	auth_token: props.authToken,
	state: props.state,
};

const approveForm = useForm({ ...authorizationPayload });
const denyForm = useForm({ ...authorizationPayload });

const approve = () => {
	approveForm.post(route("passport.device.authorizations.approve"), {
		preserveScroll: false,
	});
};

const deny = () => {
	denyForm.delete(route("passport.device.authorizations.deny"), {
		preserveScroll: false,
	});
};

const scopeDescription = (scope: { id: string; description: string }) => {
	if (scope.id === "xivplugin:read") {
		return t("xivplugin.scopes.xivplugin_read");
	}

	return scope.description;
};

defineOptions({
	layout: AuthLayout,
});
</script>

<template>
	<SeoHead
		:title="t('xivplugin.authorize.title')"
		:description="t('xivplugin.authorize.description')"
		noindex
	/>

	<div>
		<div class="mb-6 text-center">
			<div class="mx-auto mb-4 flex size-14 items-center justify-center border border-brand/40 bg-brand/10 text-brand">
				<UIcon name="i-lucide-plug-zap" class="size-7" />
			</div>
			<p class="text-2xl font-semibold text-toned">{{ t('xivplugin.authorize.title') }}</p>
			<p class="mt-2 text-sm leading-6 text-muted">
				{{ t('xivplugin.authorize.subtitle', { client: client.name }) }}
			</p>
		</div>

		<div class="space-y-4">
			<div class="border border-default bg-muted/10 p-4">
				<p class="text-xs font-semibold uppercase tracking-[0.24em] text-muted">
					{{ t('xivplugin.authorize.plugin') }}
				</p>
				<p class="mt-2 text-lg font-semibold text-highlighted">{{ client.name }}</p>
				<p v-if="userCode" class="mt-1 text-sm text-muted">
					{{ t('xivplugin.authorize.code', { code: userCode }) }}
				</p>
			</div>

			<div class="border border-default bg-muted/10 p-4">
				<p class="text-sm font-semibold text-toned">{{ t('xivplugin.authorize.access_title') }}</p>
				<ul v-if="scopes.length > 0" class="mt-3 space-y-2 text-sm text-muted">
					<li v-for="scope in scopes" :key="scope.id" class="flex gap-2">
						<UIcon name="i-lucide-check" class="mt-0.5 size-4 shrink-0 text-success" />
						<span>{{ scopeDescription(scope) }}</span>
					</li>
				</ul>
				<p v-else class="mt-2 text-sm text-muted">
					{{ t('xivplugin.authorize.basic_access') }}
				</p>
			</div>
		</div>

		<div class="mt-6 grid gap-3 sm:grid-cols-2">
			<UButton
				color="brand"
				size="xl"
				class="justify-center"
				:disabled="approveForm.processing || denyForm.processing"
				@click="approve"
			>
				{{ t('xivplugin.authorize.approve') }}
			</UButton>

			<UButton
				color="neutral"
				variant="outline"
				size="xl"
				class="justify-center"
				:disabled="approveForm.processing || denyForm.processing"
				@click="deny"
			>
				{{ t('xivplugin.authorize.deny') }}
			</UButton>
		</div>
	</div>
</template>
