<script setup lang="ts">
// @ts-ignore
import { useConfirmationModal } from "@/composables/useConfirmationModal";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { route } from "ziggy-js";

type RaidPositionRecord = {
	id: number
	key: string
	name: string
	icon_url: string | null
	sort_order: number
	is_active: boolean
}

const props = defineProps<{
	raidPositions: RaidPositionRecord[]
}>();

const { t } = useI18n();
const toast = useToast();
const confirmationModal = useConfirmationModal();
const search = ref("");
const isCreateModalOpen = ref(false);
const isEditModalOpen = ref(false);
const editingPosition = ref<RaidPositionRecord | null>(null);

const createForm = useForm({
	key: "",
	name: "",
	icon_url: "",
	sort_order: 0,
	is_active: true,
});

const editForm = useForm({
	key: "",
	name: "",
	icon_url: "",
	sort_order: 0,
	is_active: true,
});

const filteredRaidPositions = computed(() => {
	const query = search.value.trim().toLowerCase();

	if (query === "") {
		return props.raidPositions;
	}

	return props.raidPositions.filter((position) => [
		position.name,
		position.key,
	].some((value) => value.toLowerCase().includes(query)));
});

const openCreateModal = () => {
	createForm.defaults({
		key: "",
		name: "",
		icon_url: "",
		sort_order: props.raidPositions.length,
		is_active: true,
	});
	createForm.reset();
	createForm.clearErrors();
	isCreateModalOpen.value = true;
};

const openEditModal = (raidPosition: RaidPositionRecord) => {
	editingPosition.value = raidPosition;
	editForm.defaults({
		key: raidPosition.key,
		name: raidPosition.name,
		icon_url: raidPosition.icon_url ?? "",
		sort_order: raidPosition.sort_order,
		is_active: raidPosition.is_active,
	});
	editForm.reset();
	editForm.clearErrors();
	isEditModalOpen.value = true;
};

const submitCreate = () => {
	createForm.post(route("admin.raid-positions.store"), {
		onSuccess: () => {
			isCreateModalOpen.value = false;
			toast.add({
				title: t("general.success"),
				description: t("admin.raid_positions.toasts.created"),
				color: "success",
				icon: "i-lucide-check",
			});
		},
	});
};

const submitEdit = () => {
	if (!editingPosition.value) {
		return;
	}

	editForm.put(route("admin.raid-positions.update", editingPosition.value.id), {
		onSuccess: () => {
			isEditModalOpen.value = false;
			editingPosition.value = null;
			toast.add({
				title: t("general.success"),
				description: t("admin.raid_positions.toasts.updated"),
				color: "success",
				icon: "i-lucide-check",
			});
		},
	});
};

const deleteRaidPosition = async (raidPosition: RaidPositionRecord) => {
	await confirmationModal.open({
		title: t("admin.raid_positions.delete_modal.title", { name: raidPosition.name }),
		description: t("admin.raid_positions.delete_modal.description"),
		severity: "warning",
		confirmLabel: t("admin.raid_positions.delete_modal.confirm"),
		confirmIcon: "i-lucide-trash-2",
		onConfirm: async ({ patch }) => {
			patch({ confirmLoading: true });

			return await new Promise<boolean>((resolve) => {
				useForm({}).delete(route("admin.raid-positions.destroy", raidPosition.id), {
					onSuccess: () => {
						toast.add({
							title: t("general.success"),
							description: t("admin.raid_positions.toasts.deleted"),
							color: "success",
							icon: "i-lucide-check",
						});
						resolve(true);
					},
					onError: () => resolve(false),
					onFinish: () => patch({ confirmLoading: false }),
				});
			});
		},
	});
};
</script>

<template>
	<UCard class="dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
				<div class="flex items-center gap-3">
					<div class="flex h-10 w-10 items-center justify-center border border-default bg-muted/30">
						<UIcon name="i-lucide-map-pin" class="h-5 w-5 text-brand-300" />
					</div>
					<div>
						<div class="flex items-center gap-2">
							<h2 class="text-base font-semibold">{{ t("admin.raid_positions.section_title") }}</h2>
							<UBadge
								:label="t('admin.raid_positions.section_badge', { count: raidPositions.length })"
								color="neutral"
								variant="subtle"
							/>
						</div>
						<p class="text-sm text-muted">{{ t("admin.raid_positions.section_description") }}</p>
					</div>
				</div>

				<div class="flex flex-col gap-2 sm:flex-row sm:items-center">
					<UInput
						v-model="search"
						icon="i-lucide-search"
						:placeholder="t('admin.raid_positions.search_placeholder')"
						class="w-full sm:w-72"
					/>
					<UButton
						type="button"
						icon="i-lucide-plus"
						:label="t('admin.raid_positions.create_modal.title')"
						color="primary"
						@click="openCreateModal"
					/>
				</div>
			</div>
		</template>

		<UAlert
			v-if="filteredRaidPositions.length === 0"
			color="neutral"
			variant="soft"
			icon="i-lucide-search-x"
			:title="t('admin.raid_positions.empty.title')"
			:description="t('admin.raid_positions.empty.description')"
		/>

		<div v-else class="grid grid-cols-1 gap-3 xl:grid-cols-2">
			<div
				v-for="raidPosition in filteredRaidPositions"
				:key="raidPosition.id"
				class="flex items-center justify-between gap-4 border border-default bg-muted/10 p-4"
			>
				<div class="flex min-w-0 items-center gap-3">
					<div class="flex h-12 w-12 shrink-0 items-center justify-center border border-default bg-muted/30">
						<img
							v-if="raidPosition.icon_url"
							:src="raidPosition.icon_url"
							:alt="raidPosition.name"
							class="h-9 w-9 object-contain"
						>
						<UIcon v-else name="i-lucide-map-pin" class="h-5 w-5 text-muted" />
					</div>

					<div class="min-w-0">
						<div class="flex flex-wrap items-center gap-2">
							<h3 class="truncate text-sm font-semibold text-highlighted">{{ raidPosition.name }}</h3>
							<UBadge :label="raidPosition.key" color="neutral" variant="subtle" />
							<UBadge
								:label="raidPosition.is_active ? t('admin.raid_positions.status.active') : t('admin.raid_positions.status.inactive')"
								:color="raidPosition.is_active ? 'success' : 'neutral'"
								variant="subtle"
							/>
						</div>
						<p class="mt-1 text-xs text-muted">
							{{ t("admin.raid_positions.sort_order", { order: raidPosition.sort_order }) }}
						</p>
					</div>
				</div>

				<div class="flex shrink-0 items-center gap-1">
					<UButton
						type="button"
						icon="i-lucide-pencil"
						color="neutral"
						variant="ghost"
						:aria-label="t('admin.raid_positions.actions.edit')"
						@click="openEditModal(raidPosition)"
					/>
					<UButton
						type="button"
						icon="i-lucide-trash-2"
						color="error"
						variant="ghost"
						:aria-label="t('admin.raid_positions.actions.delete')"
						@click="deleteRaidPosition(raidPosition)"
					/>
				</div>
			</div>
		</div>
	</UCard>

	<UModal
		v-model:open="isCreateModalOpen"
		:title="t('admin.raid_positions.create_modal.title')"
		:description="t('admin.raid_positions.create_modal.subtitle')"
		:ui="{ content: 'rounded-sm', header: 'border-0' }"
	>
		<template #body>
			<form class="flex flex-col gap-4" @submit.prevent="submitCreate">
				<div class="grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,1fr)_12rem]">
					<UFormField :label="t('admin.raid_positions.fields.name.label')" :error="createForm.errors.name" required>
						<UInput v-model="createForm.name" :placeholder="t('admin.raid_positions.fields.name.placeholder')" class="w-full" />
					</UFormField>
					<UFormField :label="t('admin.raid_positions.fields.key.label')" :error="createForm.errors.key" required>
						<UInput v-model="createForm.key" :placeholder="t('admin.raid_positions.fields.key.placeholder')" class="w-full" />
					</UFormField>
				</div>

				<UFormField :label="t('admin.raid_positions.fields.icon_url.label')" :error="createForm.errors.icon_url">
					<UInput v-model="createForm.icon_url" :placeholder="t('admin.raid_positions.fields.icon_url.placeholder')" class="w-full" />
				</UFormField>

				<div class="grid grid-cols-1 gap-4 sm:grid-cols-[12rem_minmax(0,1fr)]">
					<UFormField :label="t('admin.raid_positions.fields.sort_order.label')" :error="createForm.errors.sort_order" required>
						<UInput v-model="createForm.sort_order" type="number" :min="0" class="w-full" />
					</UFormField>
					<UFormField :label="t('admin.raid_positions.fields.is_active.label')" :error="createForm.errors.is_active">
						<div class="flex h-10 items-center">
							<USwitch v-model="createForm.is_active" />
						</div>
					</UFormField>
				</div>

				<div class="mt-2 flex justify-end gap-2">
					<UButton type="button" :label="t('general.cancel')" color="neutral" variant="ghost" @click="isCreateModalOpen = false" />
					<UButton type="submit" :label="t('general.create')" color="primary" :loading="createForm.processing" />
				</div>
			</form>
		</template>
	</UModal>

	<UModal
		v-model:open="isEditModalOpen"
		:title="t('admin.raid_positions.edit_modal.title')"
		:description="t('admin.raid_positions.edit_modal.subtitle', { name: editForm.name })"
		:ui="{ content: 'rounded-sm', header: 'border-0' }"
	>
		<template #body>
			<form class="flex flex-col gap-4" @submit.prevent="submitEdit">
				<div class="grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,1fr)_12rem]">
					<UFormField :label="t('admin.raid_positions.fields.name.label')" :error="editForm.errors.name" required>
						<UInput v-model="editForm.name" :placeholder="t('admin.raid_positions.fields.name.placeholder')" class="w-full" />
					</UFormField>
					<UFormField :label="t('admin.raid_positions.fields.key.label')" :error="editForm.errors.key" required>
						<UInput v-model="editForm.key" :placeholder="t('admin.raid_positions.fields.key.placeholder')" class="w-full" />
					</UFormField>
				</div>

				<UFormField :label="t('admin.raid_positions.fields.icon_url.label')" :error="editForm.errors.icon_url">
					<UInput v-model="editForm.icon_url" :placeholder="t('admin.raid_positions.fields.icon_url.placeholder')" class="w-full" />
				</UFormField>

				<div class="grid grid-cols-1 gap-4 sm:grid-cols-[12rem_minmax(0,1fr)]">
					<UFormField :label="t('admin.raid_positions.fields.sort_order.label')" :error="editForm.errors.sort_order" required>
						<UInput v-model="editForm.sort_order" type="number" :min="0" class="w-full" />
					</UFormField>
					<UFormField :label="t('admin.raid_positions.fields.is_active.label')" :error="editForm.errors.is_active">
						<div class="flex h-10 items-center">
							<USwitch v-model="editForm.is_active" />
						</div>
					</UFormField>
				</div>

				<div class="mt-2 flex justify-end gap-2">
					<UButton type="button" :label="t('general.cancel')" color="neutral" variant="ghost" @click="isEditModalOpen = false" />
					<UButton type="submit" :label="t('general.update')" color="primary" :loading="editForm.processing" />
				</div>
			</form>
		</template>
	</UModal>
</template>
