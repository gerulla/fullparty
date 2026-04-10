<script setup lang="ts">
import { getPaginationRowModel } from "@tanstack/vue-table";
import { useI18n } from "vue-i18n";
import { useForm } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { ref, useTemplateRef } from "vue";
import { useToast } from "@nuxt/ui/composables";

defineProps({
	definitions: Array
});

const { t } = useI18n();
const toast = useToast();
const table = useTemplateRef('table');

const isCreateModalOpen = ref(false);
const isEditModalOpen = ref(false);
const isDeleteModalOpen = ref(false);
const editingDefinition = ref(null);
const deletingDefinition = ref(null);
const pagination = ref({
	pageIndex: 0,
	pageSize: 6
});
const globalFilter = ref('');

const fieldTypes = [
	{ value: 'text', label: t('admin.character_definitions.fields.type.options.text') },
	{ value: 'number', label: t('admin.character_definitions.fields.type.options.number') },
	{ value: 'date', label: t('admin.character_definitions.fields.type.options.date') },
	{ value: 'textarea', label: t('admin.character_definitions.fields.type.options.textarea') },
	{ value: 'select', label: t('admin.character_definitions.fields.type.options.select') },
	{ value: 'checkbox', label: t('admin.character_definitions.fields.type.options.checkbox') }
];

const createForm = useForm({
	name: '',
	type: 'text',
	description: '',
	validation_rules: [],
	is_active: true
});

const editForm = useForm({
	name: '',
	type: 'text',
	description: '',
	validation_rules: [],
	is_active: true
});

const openCreateModal = () => {
	createForm.reset();
	isCreateModalOpen.value = true;
};

const openEditModal = (definition) => {
	editingDefinition.value = definition;
	editForm.name = definition.original.name;
	editForm.type = definition.original.type;
	editForm.description = definition.original.description || '';
	editForm.validation_rules = definition.original.validation_rules || [];
	editForm.is_active = definition.original.is_active;
	isEditModalOpen.value = true;
};

const submitCreate = () => {
	createForm.post(route('admin.characters.definitions.store'), {
		onSuccess: () => {
			isCreateModalOpen.value = false;
			toast.add({
				title: t('general.success'),
				description: t('admin.character_definitions.toasts.created'),
				color: 'success',
				icon: 'i-lucide-check'
			});
			createForm.reset();
		}
	});
};

const submitEdit = () => {
	editForm.put(route('admin.characters.definitions.update', editingDefinition.value.original.id), {
		onSuccess: () => {
			isEditModalOpen.value = false;
			toast.add({
				title: t('general.success'),
				description: t('admin.character_definitions.toasts.updated'),
				color: 'success',
				icon: 'i-lucide-check'
			});
		}
	});
};

const openDeleteModal = (definition) => {
	deletingDefinition.value = definition;
	isDeleteModalOpen.value = true;
};

const confirmDelete = () => {
	useForm({}).delete(route('admin.characters.definitions.destroy', deletingDefinition.value.original.id), {
		onSuccess: () => {
			isDeleteModalOpen.value = false;
			deletingDefinition.value = null;
			toast.add({
				title: t('general.success'),
				description: t('admin.character_definitions.toasts.deleted'),
				color: 'success',
				icon: 'i-lucide-check'
			});
		}
	});
};

const columns = [
	{ accessorKey: 'name', header: t('admin.character_definitions.table.name') },
	{ accessorKey: 'type', header: t('admin.character_definitions.table.type') },
	{ accessorKey: 'description', header: t('admin.character_definitions.table.description') },
	{ accessorKey: 'is_active', header: t('admin.character_definitions.table.active') },
	{ id: 'actions' }
];
</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-row items-center justify-between">
				<div class="flex flex-row items-center font-semibold text-md">
					<UIcon name="i-lucide-database" class="mr-2" size="22"/>
					<p>{{ t('admin.character_definitions.table_header') }}</p>
				</div>
				<div class="flex items-center gap-3">
					<UInput
						v-model="globalFilter"
						class="w-72"
						icon="i-lucide-search"
						:placeholder="t('admin.character_definitions.search_placeholder')"
					/>
					<UButton
						@click.prevent="openCreateModal"
						type="button"
						icon="i-lucide-plus"
						:label="t('admin.character_definitions.create_modal.title')"
						color="primary"
						size="md"
					/>
				</div>
			</div>
		</template>

		<div class="flex flex-col gap-4">
			<div class="max-h-[32rem] overflow-auto">
				<UTable
					ref="table"
					v-model:pagination="pagination"
					v-model:global-filter="globalFilter"
					:data="definitions"
					:columns="columns"
					:pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
					class="w-full"
				>
					<template #name-cell="{ row }">
						<div class="flex flex-col">
							<span class="font-semibold">{{ row.original.name }}</span>
							<span class="text-xs text-gray-500">{{ row.original.slug }}</span>
						</div>
					</template>

					<template #type-cell="{ row }">
						<UBadge :label="row.original.type" color="neutral" variant="subtle" />
					</template>

					<template #description-cell="{ row }">
						<span class="text-sm text-gray-600 dark:text-gray-400">
							{{ row.original.description || t('admin.character_definitions.table.no_description') }}
						</span>
					</template>

					<template #is_active-cell="{ row }">
						<UBadge
							:label="row.original.is_active ? t('admin.character_definitions.table.active_badge') : t('admin.character_definitions.table.inactive_badge')"
							:color="row.original.is_active ? 'success' : 'neutral'"
							variant="subtle"
						/>
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
		:title="t('admin.character_definitions.create_modal.title')"
		:description="t('admin.character_definitions.create_modal.subtitle')"
		:ui="{ content: 'rounded-sm', header: 'border-0'}"
	>
		<template #body>
			<form @submit.prevent="submitCreate" class="w-full flex flex-col gap-4">
				<div class="w-full flex flex-row items-start justify-evenly gap-4">
					<UFormField :label="t('admin.character_definitions.fields.name.label')" class="w-3/5" required>
						<UInput
							v-model="createForm.name"
							:placeholder="t('admin.character_definitions.fields.name.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>

					<UFormField :label="t('admin.character_definitions.fields.type.label')" class="w-2/5" required>
						<USelect
							v-model="createForm.type"
							:items="fieldTypes"
							size="xl"
							class="w-full"
						/>
					</UFormField>
				</div>

				<UFormField
					:label="t('admin.character_definitions.fields.active.label')"
					:description="t('admin.character_definitions.fields.active.description')"
					class="w-full flex flex-row items-center"
					orientation="horizontal"
					required
				>
					<USwitch class="w-full" v-model="createForm.is_active" size="xl"/>
				</UFormField>

				<UFormField :label="t('admin.character_definitions.fields.description.label')" class="w-full">
					<UTextarea
						v-model="createForm.description"
						:placeholder="t('admin.character_definitions.fields.description.placeholder')"
						:rows="3"
						class="w-full"
					/>
				</UFormField>

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
		:title="t('admin.character_definitions.edit_modal.title')"
		:description="t('admin.character_definitions.edit_modal.subtitle',{field: editForm.name})"
		:ui="{ content: 'rounded-sm', header: 'border-0'}"
	>
		<template #body>
			<form @submit.prevent="submitEdit" class="w-full flex flex-col gap-4">
				<div class="w-full flex flex-row items-start justify-evenly gap-4">
					<UFormField :label="t('admin.character_definitions.fields.name.label')" class="w-3/5" required>
						<UInput
							v-model="editForm.name"
							:placeholder="t('admin.character_definitions.fields.name.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>

					<UFormField :label="t('admin.character_definitions.fields.type.label')" class="w-2/5" required>
						<USelect
							v-model="editForm.type"
							:items="fieldTypes"
							size="xl"
							class="w-full"
						/>
					</UFormField>
				</div>

				<UFormField
					:label="t('admin.character_definitions.fields.active.label')"
					:description="t('admin.character_definitions.fields.active.description')"
					class="w-full flex flex-row items-center"
					orientation="horizontal"
					required
				>
					<USwitch class="w-full" v-model="editForm.is_active" size="xl"/>
				</UFormField>

				<UFormField :label="t('admin.character_definitions.fields.description.label')" class="w-full">
					<UTextarea
						v-model="editForm.description"
						:placeholder="t('admin.character_definitions.fields.description.placeholder')"
						:rows="3"
						class="w-full"
					/>
				</UFormField>

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
		v-if="deletingDefinition"
		:title="t('admin.character_definitions.delete_modal.title', {field: deletingDefinition.original.name ?? ''})"
		:description="t('admin.character_definitions.delete_modal.subtitle', {field: deletingDefinition.original.name ?? ''})"
		:ui="{ content: 'rounded-sm', header: 'border-0'}"
	>
		<template #body>
			<div class="flex flex-col gap-4">
				<p class="text-sm text-red-600 dark:text-red-400">
					{{ t('admin.character_definitions.delete_modal.warning') }}
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
