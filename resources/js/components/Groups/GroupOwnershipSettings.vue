<script setup lang="ts">
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useToast } from "@nuxt/ui/composables";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	group: {
		slug: string
		owner: {
			id: number | null
			name: string | null
		}
		permissions: {
			can_transfer_ownership: boolean
		}
		members: Array<{
			id: number
			name: string
			role: string
		}>
	}
}>();

const { t } = useI18n();
const toast = useToast();
const modalOpen = ref(false);
const selectedMemberId = ref<number | null>(null);
const isSubmitting = ref(false);

const eligibleMembers = computed(() => props.group.members.filter((member) => member.id !== props.group.owner.id));
const memberOptions = computed(() => eligibleMembers.value.map((member) => ({
	label: member.name,
	value: member.id,
})));
const selectedMember = computed(() => eligibleMembers.value.find((member) => member.id === selectedMemberId.value) ?? null);

const openModal = () => {
	if (!props.group.permissions.can_transfer_ownership || eligibleMembers.value.length === 0) {
		return;
	}

	selectedMemberId.value = null;
	modalOpen.value = true;
};

const submitTransfer = () => {
	if (!props.group.permissions.can_transfer_ownership || !selectedMemberId.value) {
		return;
	}

	isSubmitting.value = true;

	router.post(route('groups.transfer-ownership', props.group.slug), {
		user_id: selectedMemberId.value,
	}, {
		preserveScroll: true,
		onSuccess: () => {
			toast.add({
				title: t('general.success'),
				description: t('groups.settings.ownership.toasts.transferred'),
				color: 'success',
				icon: 'i-lucide-crown',
			});
			modalOpen.value = false;
		},
		onFinish: () => {
			isSubmitting.value = false;
		},
	});
};
</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-col gap-1">
				<p class="font-semibold text-md">{{ t('groups.settings.ownership.title') }}</p>
				<p class="text-sm text-muted">{{ t('groups.settings.ownership.subtitle') }}</p>
			</div>
		</template>

		<div class="flex flex-col gap-4">
			<UAlert
				v-if="!group.permissions.can_transfer_ownership"
				color="warning"
				variant="subtle"
				icon="i-lucide-shield-alert"
				:title="t('groups.settings.ownership.owner_only_notice')"
			/>

			<div class="rounded-sm border border-warning/40 bg-warning/10 px-4 py-4">
				<p class="font-medium text-toned">{{ t('groups.settings.ownership.warning_title') }}</p>
				<p class="mt-1 text-sm text-muted">{{ t('groups.settings.ownership.warning_description') }}</p>
			</div>

			<div v-if="eligibleMembers.length === 0" class="rounded-sm border border-default bg-muted/20 px-4 py-4 text-sm text-muted">
				{{ t('groups.settings.ownership.empty') }}
			</div>

			<div class="flex justify-end">
				<UModal v-model:open="modalOpen">
					<UButton
						color="error"
						variant="outline"
						:label="t('groups.settings.ownership.transfer_button')"
						icon="i-lucide-arrow-right-left"
						:disabled="!group.permissions.can_transfer_ownership || eligibleMembers.length === 0"
						@click="openModal"
					/>

					<template #header>
						<div class="flex flex-col gap-1">
							<p class="font-semibold">{{ t('groups.settings.ownership.confirm_modal.title') }}</p>
							<p class="text-sm text-muted">{{ t('groups.settings.ownership.confirm_modal.subtitle') }}</p>
						</div>
					</template>

					<template #body>
						<form class="flex flex-col gap-4" @submit.prevent="submitTransfer">
							<UFormField :label="t('groups.settings.ownership.confirm_modal.member.label')">
								<USelect
									v-model="selectedMemberId"
									class="w-full"
									value-key="value"
									:items="memberOptions"
									:placeholder="t('groups.settings.ownership.confirm_modal.member.placeholder')"
								/>
							</UFormField>

							<div class="rounded-sm border border-error/40 bg-error/10 px-4 py-4">
								<p class="text-sm text-toned">{{ t('groups.settings.ownership.confirm_modal.warning') }}</p>
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
									type="submit"
									color="error"
									:label="t('groups.settings.ownership.confirm_modal.confirm_button')"
									:loading="isSubmitting"
									:disabled="!selectedMemberId"
								/>
							</div>
						</form>
					</template>
				</UModal>
			</div>
		</div>
	</UCard>
</template>
