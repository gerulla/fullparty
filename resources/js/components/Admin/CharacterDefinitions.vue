<script setup lang="ts">
import { getPaginationRowModel } from "@tanstack/vue-table";
import { useI18n } from "vue-i18n";
import { useForm } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { computed, ref, useTemplateRef } from "vue";
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

const fieldTypes = computed(() => [
	{ value: 'text', label: t('admin.character_definitions.fields.type.options.text') },
	{ value: 'number', label: t('admin.character_definitions.fields.type.options.number') },
	{ value: 'date', label: t('admin.character_definitions.fields.type.options.date') },
	{ value: 'textarea', label: t('admin.character_definitions.fields.type.options.textarea') },
	{ value: 'select', label: t('admin.character_definitions.fields.type.options.select') },
	{ value: 'checkbox', label: t('admin.character_definitions.fields.type.options.checkbox') }
]);

const groupOptions = computed(() => [
	{ value: 'profile', label: t('admin.character_definitions.fields.group.options.profile') },
	{ value: 'metadata', label: t('admin.character_definitions.fields.group.options.metadata') },
	{ value: 'social', label: t('admin.character_definitions.fields.group.options.social') },
	{ value: 'recruitment', label: t('admin.character_definitions.fields.group.options.recruitment') },
	{ value: 'system', label: t('admin.character_definitions.fields.group.options.system') }
]);

const displayContextOptions = computed(() => [
	{ value: 'profile', label: t('admin.character_definitions.fields.display_contexts.options.profile') },
	{ value: 'account', label: t('admin.character_definitions.fields.display_contexts.options.account') },
	{ value: 'admin', label: t('admin.character_definitions.fields.display_contexts.options.admin') }
]);

const sourceTypeOptions = computed(() => [
	{ value: 'user', label: t('admin.character_definitions.fields.source_type.options.user') },
	{ value: 'system', label: t('admin.character_definitions.fields.source_type.options.system') },
	{ value: 'hybrid', label: t('admin.character_definitions.fields.source_type.options.hybrid') }
]);

const createForm = useForm({
	name: '',
	type: 'text',
	description: '',
	group: 'profile',
	display_contexts: ['profile'],
	source_type: 'user',
	is_editable: true,
	is_visible: true,
	tags: '',
	validation_rules: [],
	is_active: true
});

const editForm = useForm({
	name: '',
	type: 'text',
	description: '',
	group: 'profile',
	display_contexts: ['profile'],
	source_type: 'user',
	is_editable: true,
	is_visible: true,
	tags: '',
	validation_rules: [],
	is_active: true
});

const openCreateModal = () => {
	createForm.reset();
	createForm.type = 'text';
	createForm.group = 'profile';
	createForm.display_contexts = ['profile'];
	createForm.source_type = 'user';
	createForm.is_editable = true;
	createForm.is_visible = true;
	createForm.tags = '';
	createForm.is_active = true;
	isCreateModalOpen.value = true;
};

const openEditModal = (definition) => {
	editingDefinition.value = definition;
	editForm.name = definition.original.name;
	editForm.type = definition.original.type;
	editForm.description = definition.original.description || '';
	editForm.group = definition.original.group || 'profile';
	editForm.display_contexts = definition.original.display_contexts || [];
	editForm.source_type = definition.original.source_type || 'user';
	editForm.is_editable = definition.original.is_editable ?? true;
	editForm.is_visible = definition.original.is_visible ?? true;
	editForm.tags = (definition.original.tags || []).join(', ');
	editForm.validation_rules = definition.original.validation_rules || [];
	editForm.is_active = definition.original.is_active;
	isEditModalOpen.value = true;
};

const transformDefinitionPayload = (form) => {
	return {
		...form.data(),
		display_contexts: form.display_contexts,
		tags: form.tags
			.split(',')
			.map((tag) => tag.trim())
			.filter(Boolean)
	};
};

const submitCreate = () => {
	createForm.transform(() => transformDefinitionPayload(createForm)).post(route('admin.characters.definitions.store'), {
		onSuccess: () => {
			isCreateModalOpen.value = false;
			toast.add({
				title: t('general.success'),
				description: t('admin.character_definitions.toasts.created'),
				color: 'success',
				icon: 'i-lucide-check'
			});
			createForm.reset();
			createForm.type = 'text';
			createForm.group = 'profile';
			createForm.display_contexts = ['profile'];
			createForm.source_type = 'user';
			createForm.is_editable = true;
			createForm.is_visible = true;
			createForm.tags = '';
			createForm.is_active = true;
		}
	});
};

const submitEdit = () => {
	editForm.transform(() => transformDefinitionPayload(editForm)).put(route('admin.characters.definitions.update', editingDefinition.value.original.id), {
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
	{ accessorKey: 'group', header: t('admin.character_definitions.table.group') },
	{ accessorKey: 'source_type', header: t('admin.character_definitions.table.source_type') },
	{ accessorKey: 'description', header: t('admin.character_definitions.table.description') },
	{ accessorKey: 'is_visible', header: t('admin.character_definitions.table.visibility') },
	{ accessorKey: 'is_active', header: t('admin.character_definitions.table.active') },
	{ id: 'actions' }
];

const shouldFixTableHeight = () => {
	return (table.value?.tableApi?.getFilteredRowModel().rows.length ?? 0) > pagination.value.pageSize;
};

const getGroupLabel = (group: string) => {
	const groupTranslationMap = {
		'profile': t('admin.character_definitions.fields.group.options.profile'),
		'metadata': t('admin.character_definitions.fields.group.options.metadata'),
		'social': t('admin.character_definitions.fields.group.options.social'),
		'recruitment': t('admin.character_definitions.fields.group.options.recruitment'),
		'system': t('admin.character_definitions.fields.group.options.system')
	};

	return groupTranslationMap[group] || group;
};

const getSourceTypeLabel = (sourceType: string) => {
	const sourceTypeTranslationMap = {
		'user': t('admin.character_definitions.fields.source_type.options.user'),
		'system': t('admin.character_definitions.fields.source_type.options.system'),
		'hybrid': t('admin.character_definitions.fields.source_type.options.hybrid')
	};

	return sourceTypeTranslationMap[sourceType] || sourceType;
};

const getDisplayContextLabels = (displayContexts: string[] = []) => {
	return displayContexts.map((context) => {
		const contextTranslationMap = {
			'profile': t('admin.character_definitions.fields.display_contexts.options.profile'),
			'account': t('admin.character_definitions.fields.display_contexts.options.account'),
			'admin': t('admin.character_definitions.fields.display_contexts.options.admin')
		};

		return contextTranslationMap[context] || context;
	});
};
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
			<div :class="shouldFixTableHeight() ? 'h-[28rem] overflow-auto' : 'overflow-auto'">
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

					<template #group-cell="{ row }">
						<div class="flex flex-col gap-1">
							<UBadge :label="getGroupLabel(row.original.group)" color="neutral" variant="subtle" />
							<span class="text-xs text-gray-500">
								{{ getDisplayContextLabels(row.original.display_contexts).join(', ') || '-' }}
							</span>
						</div>
					</template>

					<template #source_type-cell="{ row }">
						<div class="flex flex-col gap-1">
							<UBadge :label="getSourceTypeLabel(row.original.source_type)" color="neutral" variant="subtle" />
							<span class="text-xs text-gray-500">
								{{ row.original.is_editable ? t('admin.character_definitions.table.editable_badge') : t('admin.character_definitions.table.readonly_badge') }}
							</span>
						</div>
					</template>

					<template #is_visible-cell="{ row }">
						<UBadge
							:label="row.original.is_visible ? t('admin.character_definitions.table.visible_badge') : t('admin.character_definitions.table.hidden_badge')"
							:color="row.original.is_visible ? 'primary' : 'neutral'"
							variant="subtle"
						/>
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

				<div class="w-full flex flex-row items-start justify-evenly gap-4">
					<UFormField :label="t('admin.character_definitions.fields.group.label')" class="w-1/2" required>
						<USelect
							v-model="createForm.group"
							:items="groupOptions"
							size="xl"
							class="w-full"
						/>
					</UFormField>

					<UFormField :label="t('admin.character_definitions.fields.source_type.label')" class="w-1/2" required>
						<USelect
							v-model="createForm.source_type"
							:items="sourceTypeOptions"
							size="xl"
							class="w-full"
						/>
					</UFormField>
				</div>

				<UFormField
					:label="t('admin.character_definitions.fields.display_contexts.label')"
					:description="t('admin.character_definitions.fields.display_contexts.description')"
					required
				>
					<USelectMenu
						v-model="createForm.display_contexts"
						:items="displayContextOptions"
						value-key="value"
						multiple
						class="w-full"
						size="xl"
					/>
				</UFormField>

				<UFormField
					:label="t('admin.character_definitions.fields.active.label')"
					:description="t('admin.character_definitions.fields.active.description')"
					class="w-full flex flex-row items-center"
					orientation="horizontal"
					required
				>
					<USwitch class="w-full" v-model="createForm.is_active" size="xl"/>
				</UFormField>

				<UFormField
					:label="t('admin.character_definitions.fields.editable.label')"
					:description="t('admin.character_definitions.fields.editable.description')"
					class="w-full flex flex-row items-center"
					orientation="horizontal"
					required
				>
					<USwitch class="w-full" v-model="createForm.is_editable" size="xl"/>
				</UFormField>

				<UFormField
					:label="t('admin.character_definitions.fields.visible.label')"
					:description="t('admin.character_definitions.fields.visible.description')"
					class="w-full flex flex-row items-center"
					orientation="horizontal"
					required
				>
					<USwitch class="w-full" v-model="createForm.is_visible" size="xl"/>
				</UFormField>

				<UFormField :label="t('admin.character_definitions.fields.description.label')" class="w-full">
					<UTextarea
						v-model="createForm.description"
						:placeholder="t('admin.character_definitions.fields.description.placeholder')"
						:rows="3"
						class="w-full"
					/>
				</UFormField>

				<UFormField :label="t('admin.character_definitions.fields.tags.label')" class="w-full">
					<UInput
						v-model="createForm.tags"
						:placeholder="t('admin.character_definitions.fields.tags.placeholder')"
						size="xl"
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

				<div class="w-full flex flex-row items-start justify-evenly gap-4">
					<UFormField :label="t('admin.character_definitions.fields.group.label')" class="w-1/2" required>
						<USelect
							v-model="editForm.group"
							:items="groupOptions"
							size="xl"
							class="w-full"
						/>
					</UFormField>

					<UFormField :label="t('admin.character_definitions.fields.source_type.label')" class="w-1/2" required>
						<USelect
							v-model="editForm.source_type"
							:items="sourceTypeOptions"
							size="xl"
							class="w-full"
						/>
					</UFormField>
				</div>

				<UFormField
					:label="t('admin.character_definitions.fields.display_contexts.label')"
					:description="t('admin.character_definitions.fields.display_contexts.description')"
					required
				>
					<USelectMenu
						v-model="editForm.display_contexts"
						:items="displayContextOptions"
						value-key="value"
						multiple
						class="w-full"
						size="xl"
					/>
				</UFormField>

				<UFormField
					:label="t('admin.character_definitions.fields.active.label')"
					:description="t('admin.character_definitions.fields.active.description')"
					class="w-full flex flex-row items-center"
					orientation="horizontal"
					required
				>
					<USwitch class="w-full" v-model="editForm.is_active" size="xl"/>
				</UFormField>

				<UFormField
					:label="t('admin.character_definitions.fields.editable.label')"
					:description="t('admin.character_definitions.fields.editable.description')"
					class="w-full flex flex-row items-center"
					orientation="horizontal"
					required
				>
					<USwitch class="w-full" v-model="editForm.is_editable" size="xl"/>
				</UFormField>

				<UFormField
					:label="t('admin.character_definitions.fields.visible.label')"
					:description="t('admin.character_definitions.fields.visible.description')"
					class="w-full flex flex-row items-center"
					orientation="horizontal"
					required
				>
					<USwitch class="w-full" v-model="editForm.is_visible" size="xl"/>
				</UFormField>

				<UFormField :label="t('admin.character_definitions.fields.description.label')" class="w-full">
					<UTextarea
						v-model="editForm.description"
						:placeholder="t('admin.character_definitions.fields.description.placeholder')"
						:rows="3"
						class="w-full"
					/>
				</UFormField>

				<UFormField :label="t('admin.character_definitions.fields.tags.label')" class="w-full">
					<UInput
						v-model="editForm.tags"
						:placeholder="t('admin.character_definitions.fields.tags.placeholder')"
						size="xl"
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
