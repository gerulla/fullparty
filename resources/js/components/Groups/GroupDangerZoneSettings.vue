<script setup lang="ts">
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useToast } from "@nuxt/ui/composables";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	group: {
		name: string
		slug: string
		permissions: {
			can_manage_group: boolean
		}
	}
}>();

const { t } = useI18n();
const toast = useToast();
const modalOpen = ref(false);
const isDeleting = ref(false);

const confirmDelete = () => {
	if (!props.group.permissions.can_manage_group) {
		return;
	}

	isDeleting.value = true;

	router.delete(route('groups.destroy', props.group.slug), {
		preserveScroll: true,
		onSuccess: () => {
			toast.add({
				title: t('general.success'),
				description: t('groups.settings.danger_zone.toasts.deleted'),
				color: 'success',
				icon: 'i-lucide-trash-2',
			});
		},
		onFinish: () => {
			isDeleting.value = false;
			modalOpen.value = false;
		},
	});
};
</script>

<template>
	<UCard class="w-full border border-error/30 dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-col gap-1">
				<p class="font-semibold text-md text-error">{{ t('groups.settings.danger_zone.title') }}</p>
				<p class="text-sm text-muted">{{ t('groups.settings.danger_zone.subtitle') }}</p>
			</div>
		</template>

		<div class="flex flex-col gap-4">
			<UAlert
				v-if="!group.permissions.can_manage_group"
				color="warning"
				variant="subtle"
				icon="i-lucide-shield-alert"
				:title="t('groups.settings.danger_zone.owner_only_notice')"
			/>

			<div class="rounded-sm border border-error/40 bg-error/10 px-4 py-4">
				<p class="font-medium text-toned">{{ t('groups.settings.danger_zone.warning_title') }}</p>
				<p class="mt-1 text-sm text-muted">{{ t('groups.settings.danger_zone.warning_description') }}</p>
			</div>

			<div class="flex justify-end">
				<UModal v-model:open="modalOpen">
					<UButton
						color="error"
						variant="outline"
						icon="i-lucide-trash-2"
						:label="t('groups.settings.danger_zone.delete_button')"
						:disabled="!group.permissions.can_manage_group"
					/>

					<template #header>
						<div class="flex flex-col gap-1">
							<p class="font-semibold">{{ t('groups.settings.danger_zone.confirm_modal.title') }}</p>
							<p class="text-sm text-muted">
								{{ t('groups.settings.danger_zone.confirm_modal.subtitle', { name: group.name }) }}
							</p>
						</div>
					</template>

					<template #body>
						<div class="flex flex-col gap-4">
							<div class="rounded-sm border border-error/40 bg-error/10 px-4 py-4">
								<p class="text-sm text-toned">{{ t('groups.settings.danger_zone.confirm_modal.warning') }}</p>
							</div>

							<div class="flex justify-end gap-2 pt-2">
								<UButton
									type="button"
									color="neutral"
									variant="ghost"
									:label="t('general.cancel')"
									@click="modalOpen = false"
								/>
								<UButton
									type="button"
									color="error"
									:label="t('groups.settings.danger_zone.confirm_modal.confirm_button')"
									:loading="isDeleting"
									@click="confirmDelete"
								/>
							</div>
						</div>
					</template>
				</UModal>
			</div>
		</div>
	</UCard>
</template>
