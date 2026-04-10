<script setup lang="ts">
import { useI18n } from "vue-i18n";
import { useForm, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { computed, ref } from "vue";
import { useToast } from "@nuxt/ui/composables";
import PageHeader from "@/components/PageHeader.vue";

const props = defineProps({
	definitions: Array
});

const { t } = useI18n();
const page = usePage();
const toast = useToast();

const isCreateModalOpen = ref(false);
const isEditModalOpen = ref(false);
const isDeleteModalOpen = ref(false);
const editingDefinition = ref(null);
const deletingDefinition = ref(null);

const fieldTypes = [
	{ value: 'text', label: 'Text' },
	{ value: 'number', label: 'Number' },
	{ value: 'date', label: 'Date' },
	{ value: 'textarea', label: 'Text Area' },
	{ value: 'select', label: 'Select' },
	{ value: 'checkbox', label: 'Checkbox' }
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
	console.log(definition)
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
				title: 'Success',
				description: 'Field definition created successfully',
				color: 'success',
				icon: 'i-lucide-check'
			});
			createForm.reset();
		}
	});
};

const submitEdit = () => {
	console.log(editingDefinition.value);
	editForm.put(route('admin.characters.definitions.update', editingDefinition.value.original.id), {
		onSuccess: () => {
			isEditModalOpen.value = false;
			toast.add({
				title: 'Success',
				description: 'Field definition updated successfully',
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
				title: 'Success',
				description: 'Field definition deleted successfully',
				color: 'success',
				icon: 'i-lucide-check'
			});
		}
	});
};

const columns = [
	{ accessorKey: 'name', header: 'Name' },
	{ accessorKey: 'type', header: 'Type' },
	{ accessorKey: 'description', header: 'Description' },
	{ accessorKey: 'is_active', header: 'Active?' },
	{ id: 'actions' }
];
</script>

<template>
	<div class="w-full min-h-screen sm:px-4 md:px-6 bg-neutral-100 dark:bg-neutral-900">
		<PageHeader :title="t('admin.characters.title')" :subtitle="t('admin.characters.subtitle')" />

		<div class="w-full flex flex-col items-start mt-4">
			<UCard class="w-full dark:bg-elevated/25">
				<template #header>
					<div class="flex flex-row items-center justify-between">
						<div class="flex flex-row items-center font-semibold text-md">
							<UIcon name="i-lucide-database" class="mr-2" size="22"/>
							<p>{{t('admin.characters.table_header')}}</p>
						</div>
						<UButton
							@click.prevent="openCreateModal"
							type="button"
							icon="i-lucide-plus"
							label="Create Field"
							color="primary"
							size="md"
						/>
					</div>
				</template>

				<UTable :data="definitions" :columns="columns" class="w-full">
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
							{{ row.original.description || 'No description' }}
						</span>
					</template>

					<template #is_active-cell="{ row }">
						<UBadge
							:label="row.original.is_active ? 'Active' : 'Inactive'"
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
			</UCard>
		</div>

		<!-- Create Modal -->
		<UModal
			v-model:open="isCreateModalOpen"
			:title="t('admin.characters.create_modal.title')"
			:description="t('admin.characters.create_modal.subtitle')"
			:ui="{ content: 'rounded-sm', header: 'border-0'}"
		>
			<template #body>
				<form @submit.prevent="submitCreate" class="w-full flex flex-col gap-4">

					<div class="w-full flex flex-row items-start justify-evenly gap-4">
						<UFormField label="Field Name" class="w-3/5" required>
							<UInput
								v-model="createForm.name"
								placeholder="e.g., Discord Username"
								size="xl"
								class="w-full"
							/>
						</UFormField>

						<UFormField label="Field Type" class="w-2/5" required>
							<USelect
								v-model="createForm.type"
								:items="fieldTypes"
								size="xl"
								class="w-full"
							/>
						</UFormField>

					</div>
					<UFormField
						label="Active?"
						description="Make the field value available for use"
						class="w-full flex flex-row items-center"
						orientation="horizontal"
						required
					>
						<USwitch class="w-full" v-model="createForm.is_active" size="xl"/>
					</UFormField>
					<UFormField label="Description"
								class="w-full">
						<UTextarea
							v-model="createForm.description"
							placeholder="Optional description for this field"
							:rows="3"
							class="w-full"
						/>
					</UFormField>


					<div class="flex gap-2 justify-end mt-4">
						<UButton
							@click="isCreateModalOpen = false"
							label="Cancel"
							color="neutral"
							variant="ghost"
						/>
						<UButton
							type="submit"
							label="Create"
							color="primary"
							:loading="createForm.processing"
						/>
					</div>
				</form>
			</template>
		</UModal>

		<!-- Edit Modal -->
		<UModal
			v-model:open="isEditModalOpen"
			:title="t('admin.characters.edit_modal.title')"
			:description="t('admin.characters.edit_modal.subtitle',{field: editForm.name})"
			:ui="{ content: 'rounded-sm', header: 'border-0'}"
		>
			<template #body>
				<form @submit.prevent="submitEdit" class="w-full flex flex-col gap-4">

					<div class="w-full flex flex-row items-start justify-evenly gap-4">
						<UFormField label="Field Name" class="w-3/5" required>
							<UInput
								v-model="editForm.name"
								placeholder="e.g., Discord Username"
								size="xl"
								class="w-full"
							/>
						</UFormField>

						<UFormField label="Field Type" class="w-2/5" required>
							<USelect
								v-model="editForm.type"
								:items="fieldTypes"
								size="xl"
								class="w-full"
							/>
						</UFormField>

					</div>
					<UFormField
						label="Active?"
						description="Make the field value available for use"
						class="w-full flex flex-row items-center"
						orientation="horizontal"
						required
					>
						<USwitch class="w-full" v-model="editForm.is_active" size="xl"/>
					</UFormField>
					<UFormField label="Description"
								class="w-full">
						<UTextarea
							v-model="editForm.description"
							placeholder="Optional description for this field"
							:rows="3"
							class="w-full"
						/>
					</UFormField>


					<div class="flex gap-2 justify-end mt-4">
						<UButton
							@click="isEditModalOpen = false"
							label="Cancel"
							color="neutral"
							variant="ghost"
						/>
						<UButton
							type="submit"
							label="Update"
							color="primary"
							:loading="editForm.processing"
						/>
					</div>
				</form>
			</template>
		</UModal>

		<!-- Delete Confirmation Modal -->
		<UModal
			v-model:open="isDeleteModalOpen"
			v-if="deletingDefinition"
			:title="t('admin.characters.delete_modal.title', {field: deletingDefinition.original.name ?? ''})"
			:description="t('admin.characters.delete_modal.subtitle', {field: deletingDefinition.original.name ?? ''})"
			:ui="{ content: 'rounded-sm', header: 'border-0'}"
		>
			<template #body>
				<div class="flex flex-col gap-4">
					<p class="text-sm text-red-600 dark:text-red-400">
						{{t('admin.characters.delete_modal.warning')}}
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
	</div>
</template>

<style scoped>
</style>
