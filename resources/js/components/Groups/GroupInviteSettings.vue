<script setup lang="ts">
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useToast } from "@nuxt/ui/composables";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	group: {
		slug: string
		is_public: boolean
		permissions: {
			can_manage_invites: boolean
		}
		invites: Array<{
			id: number
			token: string
			is_system: boolean
			uses: number
			max_uses: number | null
			expires_at: string | null
			created_by: string | null
			created_at: string | null
		}>
	}
}>();

const { t } = useI18n();
const toast = useToast();
const createModalOpen = ref(false);
const form = ref({
	max_uses: '',
	expires_in: 'unlimited',
});
const isCreating = ref(false);
const deletingInviteId = ref<number | null>(null);

const expiryOptions = computed(() => [
	{ label: t('groups.settings.invites.create_modal.expires_in.options.12_hours'), value: '12h' },
	{ label: t('groups.settings.invites.create_modal.expires_in.options.24_hours'), value: '24h' },
	{ label: t('groups.settings.invites.create_modal.expires_in.options.1_day'), value: '1d' },
	{ label: t('groups.settings.invites.create_modal.expires_in.options.2_days'), value: '2d' },
	{ label: t('groups.settings.invites.create_modal.expires_in.options.3_days'), value: '3d' },
	{ label: t('groups.settings.invites.create_modal.expires_in.options.4_days'), value: '4d' },
	{ label: t('groups.settings.invites.create_modal.expires_in.options.1_week'), value: '7d' },
	{ label: t('groups.settings.invites.create_modal.expires_in.options.unlimited'), value: 'unlimited' },
]);

const inviteItems = computed(() => props.group.invites);

const formatDate = (value: string | null) => {
	if (!value) {
		return t('groups.settings.invites.never');
	}

	return new Intl.DateTimeFormat(undefined, {
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	}).format(new Date(value));
};

const resolveStatus = (invite: { is_system: boolean, uses: number, max_uses: number | null, expires_at: string | null }) => {
	const isExpired = invite.expires_at ? new Date(invite.expires_at).getTime() < Date.now() : false;
	const hasReachedUsageLimit = invite.max_uses !== null && invite.uses >= invite.max_uses;

	if (invite.is_system) {
		return {
			label: t('groups.settings.invites.statuses.system'),
			color: 'primary',
		};
	}

	if (isExpired || hasReachedUsageLimit) {
		return {
			label: t('groups.settings.invites.statuses.expired'),
			color: 'error',
		};
	}

	return {
		label: t('groups.settings.invites.statuses.active'),
		color: 'success',
	};
};

const inviteUrl = (token: string) => {
	return `${window.location.origin}${route('groups.invites.show', token, false)}`;
};

const copyInvite = async (token: string) => {
	await navigator.clipboard.writeText(inviteUrl(token));

	toast.add({
		title: t('general.success'),
		description: t('groups.settings.invites.toasts.copied'),
		color: 'success',
		icon: 'i-lucide-copy-check',
	});
};

const resetForm = () => {
	form.value.max_uses = '';
	form.value.expires_in = 'unlimited';
};

const resolveExpiresAt = (value: string) => {
	if (value === 'unlimited') {
		return null;
	}

	const now = new Date();
	const next = new Date(now);

	if (value.endsWith('h')) {
		next.setHours(now.getHours() + Number.parseInt(value, 10));

		return next.toISOString();
	}

	if (value.endsWith('d')) {
		next.setDate(now.getDate() + Number.parseInt(value, 10));

		return next.toISOString();
	}

	return null;
};

const createInvite = () => {
	if (!props.group.permissions.can_manage_invites) {
		return;
	}

	isCreating.value = true;

	router.post(route('groups.invites.store', props.group.slug), {
		max_uses: form.value.max_uses ? Number(form.value.max_uses) : null,
		expires_at: resolveExpiresAt(form.value.expires_in),
	}, {
		preserveScroll: true,
		onSuccess: () => {
			toast.add({
				title: t('general.success'),
				description: t('groups.settings.invites.toasts.created'),
				color: 'success',
				icon: 'i-lucide-check',
			});
			createModalOpen.value = false;
			resetForm();
		},
		onFinish: () => {
			isCreating.value = false;
		},
	});
};

const revokeInvite = (inviteId: number) => {
	if (!props.group.permissions.can_manage_invites) {
		return;
	}

	deletingInviteId.value = inviteId;

	router.delete(route('groups.invites.destroy', {
		group: props.group.slug,
		invite: inviteId,
	}), {
		preserveScroll: true,
		onSuccess: () => {
			toast.add({
				title: t('general.success'),
				description: t('groups.settings.invites.toasts.revoked'),
				color: 'success',
				icon: 'i-lucide-trash-2',
			});
		},
		onFinish: () => {
			deletingInviteId.value = null;
		},
	});
};
</script>

<template>
	<UCard class="w-full dark:bg-elevated/25" :ui="{ body: 'p-0 sm:p-0' }">
		<template #header>
			<div class="flex items-start justify-between gap-4">
				<div class="flex flex-col gap-1">
					<p class="font-semibold text-md">{{ t('groups.settings.invites.title') }}</p>
					<p class="text-sm text-muted">{{ t('groups.settings.invites.subtitle') }}</p>
				</div>

				<UModal v-model:open="createModalOpen">
					<UButton
						:label="t('groups.settings.invites.create_button')"
						icon="i-lucide-plus"
						color="neutral"
						variant="outline"
						:disabled="!group.permissions.can_manage_invites"
					/>

					<template #header>
						<div class="flex flex-col gap-1">
							<p class="font-semibold">{{ t('groups.settings.invites.create_modal.title') }}</p>
							<p class="text-sm text-muted">{{ t('groups.settings.invites.create_modal.subtitle') }}</p>
						</div>
					</template>

					<template #body>
						<form class="flex flex-col gap-4" @submit.prevent="createInvite">
							<UFormField :label="t('groups.settings.invites.create_modal.max_uses.label')">
								<UInput
									v-model="form.max_uses"
									type="number"
									min="1"
									class="w-full"
									:placeholder="t('groups.settings.invites.create_modal.max_uses.placeholder')"
								/>
							</UFormField>

							<UFormField :label="t('groups.settings.invites.create_modal.expires_in.label')">
								<USelect
									v-model="form.expires_in"
									class="w-full"
									value-key="value"
									:items="expiryOptions"
									:placeholder="t('groups.settings.invites.create_modal.expires_in.placeholder')"
								/>
							</UFormField>

							<div class="flex justify-end gap-2 pt-2">
								<UButton
									type="button"
									color="neutral"
									variant="ghost"
									:label="t('general.cancel')"
									@click="createModalOpen = false"
								/>
								<UButton
									type="submit"
									color="neutral"
									:label="t('general.create')"
									:loading="isCreating"
								/>
							</div>
						</form>
					</template>
				</UModal>
			</div>
		</template>

		<div v-if="inviteItems.length === 0" class="px-4 py-8 text-sm text-muted">
			{{ t('groups.settings.invites.empty') }}
		</div>

		<div v-else class="flex flex-col">
			<div
				v-for="invite in inviteItems"
				:key="invite.id"
				class="border-t border-default first:border-t-0"
			>
				<div class="flex flex-col gap-4 px-4 py-5 lg:flex-row lg:items-center lg:justify-between">
					<div class="min-w-0 flex-1">
						<div class="flex items-center gap-3">
							<p class="truncate font-medium text-toned">{{ invite.token }}</p>
							<UBadge
								size="sm"
								variant="subtle"
								:label="resolveStatus(invite).label"
								:color="resolveStatus(invite).color"
							/>
						</div>

						<p class="mt-1 text-sm text-muted">
							{{
								t('groups.settings.invites.created_by', {
									name: invite.created_by || t('groups.settings.invites.system_creator'),
									date: formatDate(invite.created_at),
								})
							}}
						</p>
					</div>

					<div class="flex flex-wrap items-center gap-6 lg:gap-8">
						<div class="flex min-w-20 flex-col items-center">
							<p class="font-medium text-toned">
								{{ invite.max_uses === null ? `${invite.uses}/${t('groups.settings.invites.unlimited')}` : `${invite.uses}/${invite.max_uses}` }}
							</p>
							<p class="text-xs text-muted">{{ t('groups.settings.invites.uses') }}</p>
						</div>

						<div class="flex min-w-20 flex-col items-center">
							<p class="font-medium text-toned">{{ formatDate(invite.expires_at) }}</p>
							<p class="text-xs text-muted">{{ t('groups.settings.invites.expires') }}</p>
						</div>

						<div class="flex items-center gap-1">
							<UButton
								color="neutral"
								variant="ghost"
								icon="i-lucide-copy"
								@click="copyInvite(invite.token)"
							/>
							<UButton
								v-if="!invite.is_system"
								color="error"
								variant="ghost"
								icon="i-lucide-x"
								:loading="deletingInviteId === invite.id"
								:disabled="!group.permissions.can_manage_invites"
								@click="revokeInvite(invite.id)"
							/>
						</div>
					</div>
				</div>
			</div>
		</div>
	</UCard>
</template>
