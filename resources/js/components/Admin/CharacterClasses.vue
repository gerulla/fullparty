<script setup lang="ts">
import { getPaginationRowModel } from "@tanstack/vue-table";
import { useI18n } from "vue-i18n";
import { useForm } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { computed, ref, useTemplateRef } from "vue";
import { useToast } from "@nuxt/ui/composables";

const props = defineProps({
	characterClasses: {
		type: Array,
		default: () => []
	}
});

const { t } = useI18n();
const toast = useToast();
const table = useTemplateRef('table');

const isCreateModalOpen = ref(false);
const isEditModalOpen = ref(false);
const isDeleteModalOpen = ref(false);
const editingClass = ref(null);
const deletingClass = ref(null);
const pagination = ref({
	pageIndex: 0,
	pageSize: 6
});
const globalFilter = ref('');

const roleOptions = computed(() => [
	{ label: t('admin.character_classes.fields.role.options.healer'), value: 'healer' },
	{ label: t('admin.character_classes.fields.role.options.tank'), value: 'tank' },
	{ label: t('admin.character_classes.fields.role.options.melee_dps'), value: 'melee dps' },
	{ label: t('admin.character_classes.fields.role.options.magic_ranged_dps'), value: 'magic ranged dps' },
	{ label: t('admin.character_classes.fields.role.options.physical_ranged_dps'), value: 'physical ranged dps' }
]);

const createForm = useForm({
	name: '',
	shorthand: '',
	icon_url: '',
	flaticon_url: '',
	role: 'healer'
});

const editForm = useForm({
	name: '',
	shorthand: '',
	icon_url: '',
	flaticon_url: '',
	role: 'healer'
});

const openCreateModal = () => {
	createForm.reset();
	createForm.role = 'healer';
	isCreateModalOpen.value = true;
};

const openEditModal = (characterClass) => {
	editingClass.value = characterClass;
	editForm.name = characterClass.original.name;
	editForm.shorthand = characterClass.original.shorthand;
	editForm.icon_url = characterClass.original.icon_url || '';
	editForm.flaticon_url = characterClass.original.flaticon_url || '';
	editForm.role = characterClass.original.role;
	isEditModalOpen.value = true;
};

const openDeleteModal = (characterClass) => {
	deletingClass.value = characterClass;
	isDeleteModalOpen.value = true;
};

const submitCreate = () => {
	createForm.post(route('admin.character-classes.store'), {
		onSuccess: () => {
			isCreateModalOpen.value = false;
			toast.add({
				title: t('general.success'),
				description: t('admin.character_classes.toasts.created'),
				color: 'success',
				icon: 'i-lucide-check'
			});
			createForm.reset();
			createForm.role = 'healer';
		}
	});
};

const submitEdit = () => {
	editForm.put(route('admin.character-classes.update', editingClass.value.original.id), {
		onSuccess: () => {
			isEditModalOpen.value = false;
			toast.add({
				title: t('general.success'),
				description: t('admin.character_classes.toasts.updated'),
				color: 'success',
				icon: 'i-lucide-check'
			});
		}
	});
};

const confirmDelete = () => {
	useForm({}).delete(route('admin.character-classes.destroy', deletingClass.value.original.id), {
		onSuccess: () => {
			isDeleteModalOpen.value = false;
			deletingClass.value = null;
			toast.add({
				title: t('general.success'),
				description: t('admin.character_classes.toasts.deleted'),
				color: 'success',
				icon: 'i-lucide-check'
			});
		}
	});
};

const columns = computed(() => [
	{ accessorKey: 'name', header: t('general.name') },
	{ accessorKey: 'shorthand', header: t('admin.character_classes.table.shorthand') },
	{ accessorKey: 'role', header: t('general.role') },
	{ accessorKey: 'icon_url', header: t('admin.character_classes.table.icon') },
	{ accessorKey: 'flaticon_url', header: t('admin.character_classes.table.flaticon') },
	{ id: 'actions' }
]);

const shouldFixTableHeight = () => {
	return (table.value?.tableApi?.getFilteredRowModel().rows.length ?? 0) > pagination.value.pageSize;
};

const getRoleLabel = (role: string) => {
	const roleTranslationMap = {
		'healer': t('admin.character_classes.fields.role.options.healer'),
		'tank': t('admin.character_classes.fields.role.options.tank'),
		'melee dps': t('admin.character_classes.fields.role.options.melee_dps'),
		'magic ranged dps': t('admin.character_classes.fields.role.options.magic_ranged_dps'),
		'physical ranged dps': t('admin.character_classes.fields.role.options.physical_ranged_dps')
	};

	return roleTranslationMap[role] || role;
};

</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-row items-center justify-between">
				<div class="flex flex-row items-center font-semibold text-md gap-2">
					<UIcon name="i-lucide-swords" size="22" />
					<p>{{ t('admin.character_classes.section_title') }}</p>
					<UBadge :label="t('admin.character_classes.section_badge', { count: characterClasses.length })" color="neutral" variant="subtle" />
				</div>
				<div class="flex items-center gap-2">
					<UInput
						v-model="globalFilter"
						class="w-72"
						icon="i-lucide-search"
						:placeholder="t('admin.character_classes.search_placeholder')"
					/>
					<UButton
						@click.prevent="openCreateModal"
						type="button"
						icon="i-lucide-plus"
						:label="t('admin.character_classes.create_modal.title')"
						color="primary"
						size="md"
					/>
				</div>
			</div>
		</template>

		<div class="flex flex-col gap-4">
			<div :class="shouldFixTableHeight() ? 'h-[28rem] overflow-auto' : 'overflow-auto'">
				<UTable
					ref="table"
					v-model:pagination="pagination"
					v-model:global-filter="globalFilter"
					:data="characterClasses"
					:columns="columns"
					:pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
					class="w-full"
				>
					<template #name-cell="{ row }">
						<span class="font-semibold">{{ row.original.name }}</span>
					</template>

					<template #shorthand-cell="{ row }">
						<UBadge :label="row.original.shorthand" color="neutral" variant="subtle" />
					</template>

					<template #role-cell="{ row }">
						<span class="text-sm">{{ getRoleLabel(row.original.role) }}</span>
					</template>

					<template #icon_url-cell="{ row }">
						<div class="flex items-center gap-3">
							<img
								v-if="row.original.icon_url"
								:src="row.original.icon_url"
								:alt="`${row.original.name} icon`"
								class="h-8 w-8 rounded-sm object-contain"
							>
							<span v-else class="text-sm text-gray-600 dark:text-gray-400">
								{{ t('admin.character_classes.table.missing_icon') }}
							</span>
						</div>
					</template>

					<template #flaticon_url-cell="{ row }">
						<div class="flex items-center gap-3">
							<img
								v-if="row.original.flaticon_url"
								:src="row.original.flaticon_url"
								:alt="`${row.original.name} flaticon`"
								class="h-8 w-8 rounded-sm object-contain"
							>
							<span v-else class="text-sm text-gray-600 dark:text-gray-400">
								{{ t('admin.character_classes.table.missing_flaticon') }}
							</span>
						</div>
					</template>

					<template #actions-cell="{ row }">
						<div class="w-full flex gap-2">
							<UButton
								@click="openEditModal(row)"
								icon="i-lucide-pencil"
								color="neutral"
								variant="ghost"
							/>
							<UButton
								@click="openDeleteModal(row)"
								icon="i-lucide-trash-2"
								color="error"
								variant="ghost"
							/>
						</div>
					</template>
				</UTable>
			</div>

			<div class="flex justify-end border-t border-default pt-4 px-4">
				<UPagination
					:page="(table?.tableApi?.getState().pagination.pageIndex || 0) + 1"
					:items-per-page="table?.tableApi?.getState().pagination.pageSize"
					:total="table?.tableApi?.getFilteredRowModel().rows.length"
					@update:page="(page) => table?.tableApi?.setPageIndex(page - 1)"
				/>
			</div>
		</div>
	</UCard>

	<UModal
		v-model:open="isCreateModalOpen"
		:title="t('admin.character_classes.create_modal.title')"
		:description="t('admin.character_classes.create_modal.subtitle')"
		:ui="{ content: 'rounded-sm', header: 'border-0'}"
	>
		<template #body>
			<form @submit.prevent="submitCreate" class="w-full flex flex-col gap-4">
				<div class="w-full flex flex-row items-start justify-evenly gap-4">
					<UFormField :label="t('admin.character_classes.fields.name.label')" class="w-3/5" required>
						<UInput
							v-model="createForm.name"
							:placeholder="t('admin.character_classes.fields.name.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>

					<UFormField :label="t('admin.character_classes.fields.shorthand.label')" class="w-2/5" required>
						<UInput
							v-model="createForm.shorthand"
							:placeholder="t('admin.character_classes.fields.shorthand.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>
				</div>

				<UFormField :label="t('admin.character_classes.fields.role.label')" required>
					<USelect
						v-model="createForm.role"
						:items="roleOptions"
						:placeholder="t('admin.character_classes.fields.role.placeholder')"
						size="xl"
						class="w-full"
					/>
				</UFormField>

				<div class="w-full flex flex-row items-start justify-evenly gap-4">
					<UFormField :label="t('admin.character_classes.fields.icon_url.label')" class="w-1/2">
						<UInput
							v-model="createForm.icon_url"
							:placeholder="t('admin.character_classes.fields.icon_url.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>

					<UFormField :label="t('admin.character_classes.fields.flaticon_url.label')" class="w-1/2">
						<UInput
							v-model="createForm.flaticon_url"
							:placeholder="t('admin.character_classes.fields.flaticon_url.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>
				</div>

				<div class="flex gap-2 justify-end mt-4">
					<UButton
						@click="isCreateModalOpen = false"
						:label="t('general.cancel')"
						color="neutral"
						variant="ghost"
					/>
					<UButton
						type="submit"
						:label="t('general.create')"
						color="primary"
						:loading="createForm.processing"
					/>
				</div>
			</form>
		</template>
	</UModal>

	<UModal
		v-model:open="isEditModalOpen"
		:title="t('admin.character_classes.edit_modal.title')"
		:description="t('admin.character_classes.edit_modal.subtitle', { name: editForm.name })"
		:ui="{ content: 'rounded-sm', header: 'border-0'}"
	>
		<template #body>
			<form @submit.prevent="submitEdit" class="w-full flex flex-col gap-4">
				<div class="w-full flex flex-row items-start justify-evenly gap-4">
					<UFormField :label="t('admin.character_classes.fields.name.label')" class="w-3/5" required>
						<UInput
							v-model="editForm.name"
							:placeholder="t('admin.character_classes.fields.name.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>

					<UFormField :label="t('admin.character_classes.fields.shorthand.label')" class="w-2/5" required>
						<UInput
							v-model="editForm.shorthand"
							:placeholder="t('admin.character_classes.fields.shorthand.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>
				</div>

				<UFormField :label="t('admin.character_classes.fields.role.label')" required>
					<USelect
						v-model="editForm.role"
						:items="roleOptions"
						:placeholder="t('admin.character_classes.fields.role.placeholder')"
						size="xl"
						class="w-full"
					/>
				</UFormField>

				<div class="w-full flex flex-row items-start justify-evenly gap-4">
					<UFormField :label="t('admin.character_classes.fields.icon_url.label')" class="w-1/2">
						<UInput
							v-model="editForm.icon_url"
							:placeholder="t('admin.character_classes.fields.icon_url.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>

					<UFormField :label="t('admin.character_classes.fields.flaticon_url.label')" class="w-1/2">
						<UInput
							v-model="editForm.flaticon_url"
							:placeholder="t('admin.character_classes.fields.flaticon_url.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>
				</div>

				<div class="flex gap-2 justify-end mt-4">
					<UButton
						@click="isEditModalOpen = false"
						:label="t('general.cancel')"
						color="neutral"
						variant="ghost"
					/>
					<UButton
						type="submit"
						:label="t('general.update')"
						color="primary"
						:loading="editForm.processing"
					/>
				</div>
			</form>
		</template>
	</UModal>

	<UModal
		v-model:open="isDeleteModalOpen"
		v-if="deletingClass"
		:title="t('admin.character_classes.delete_modal.title', { name: deletingClass.original.name ?? '' })"
		:description="t('admin.character_classes.delete_modal.subtitle', { name: deletingClass.original.name ?? '' })"
		:ui="{ content: 'rounded-sm', header: 'border-0'}"
	>
		<template #body>
			<div class="flex flex-col gap-4">
				<p class="text-sm text-red-600 dark:text-red-400">
					{{ t('admin.character_classes.delete_modal.warning') }}
				</p>
				<div class="flex gap-2 justify-end mt-4">
					<UButton
						@click="isDeleteModalOpen = false"
						:label="t('general.cancel')"
						color="neutral"
						variant="ghost"
					/>
					<UButton
						@click="confirmDelete"
						:label="t('general.delete')"
						color="error"
						icon="i-lucide-trash-2"
					/>
				</div>
			</div>
		</template>
	</UModal>
</template>

<style scoped>
</style>
