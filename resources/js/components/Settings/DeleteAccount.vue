<script setup lang="ts">
import { useConfirmationModal } from "@/composables/useConfirmationModal";
import { router } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";

const { t } = useI18n();
const toast = useToast();
const confirmationModal = useConfirmationModal();
const deletionEffects = [
    { key: 'account', icon: 'i-lucide-user-x' },
    { key: 'connections', icon: 'i-lucide-unplug' },
    { key: 'characters', icon: 'i-lucide-contact-round' },
    { key: 'runs', icon: 'i-lucide-calendar-x' },
];

const promptDeleteAccount = async () => {
	await confirmationModal.open({
		title: t('settings.privacy.delete_account_modal.title'),
		description: t('settings.privacy.delete_account_modal.description'),
		severity: 'error',
		warningText: t('settings.privacy.delete_account_modal.warning'),
		confirmLabel: t('settings.privacy.delete_account_modal.confirm'),
		confirmIcon: 'i-lucide-trash-2',
		onConfirm: async ({ patch }) => {
			patch({ confirmLoading: true });

			return await new Promise<boolean>((resolve) => {
				router.delete(route('settings.account.destroy'), {
					preserveScroll: true,
					onSuccess: () => {
						resolve(true);
					},
					onError: (errors) => {
						if (errors.error === 'account_delete_group_owner') {
							toast.add({
								title: t('general.error'),
								description: t('settings.privacy.delete_account_owner_blocked'),
								color: 'error',
								icon: 'i-lucide-triangle-alert',
							});
						}

						resolve(false);
					},
					onFinish: () => {
						patch({ confirmLoading: false });
					},
				});
			});
		},
	});
};
</script>

<template>
    <UCard class="w-full bg-elevation-2">
        <div class="space-y-6">
            <UAlert color="error" variant="subtle" icon="i-lucide-triangle-alert"
                :title="t('settings.privacy.deletion_details.warning_title')"
                :description="t('settings.privacy.deletion_details.warning_description')" />

            <section>
                <h3 class="mb-4 font-semibold text-highlighted">{{ t('settings.privacy.deletion_details.effects_title') }}</h3>
                <ul class="grid gap-5 md:grid-cols-2">
                    <li v-for="effect in deletionEffects" :key="effect.key" class="flex items-start gap-3">
                        <UIcon :name="effect.icon" class="mt-0.5 size-5 shrink-0 text-muted" />
                        <div>
                            <h4 class="text-sm font-semibold text-highlighted">{{ t('settings.privacy.deletion_details.effects.' + effect.key + '.title') }}</h4>
                            <p class="mt-1 text-sm leading-relaxed text-muted">{{ t('settings.privacy.deletion_details.effects.' + effect.key + '.description') }}</p>
                        </div>
                    </li>
                </ul>
            </section>

            <section class="border-t border-default pt-6">
                <h3 class="font-semibold text-highlighted">{{ t('settings.privacy.deletion_details.history_title') }}</h3>
                <p class="mt-2 text-sm leading-relaxed text-muted">{{ t('settings.privacy.deletion_details.history_description') }}</p>
                <p class="mt-2 text-sm leading-relaxed text-muted">{{ t('settings.privacy.deletion_details.external_accounts') }}</p>
            </section>

            <section class="flex items-start gap-3 rounded-lg bg-elevation-3 p-4">
                <UIcon name="i-lucide-users" class="mt-0.5 size-5 shrink-0 text-brand" />
                <div>
                    <h3 class="text-sm font-semibold text-highlighted">{{ t('settings.privacy.deletion_details.groups_title') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-muted">{{ t('settings.privacy.delete_account_owner_blocked') }}</p>
                </div>
            </section>

            <div class="flex flex-col items-start gap-4 border-t border-default pt-6 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-muted">{{ t('settings.privacy.deletion_details.confirmation_hint') }}</p>
                <UButton :label="t('settings.privacy.delete_account')" color="error" icon="i-lucide-trash-2"
                    class="shrink-0" @click="promptDeleteAccount" />
            </div>
        </div>
    </UCard>
</template>
