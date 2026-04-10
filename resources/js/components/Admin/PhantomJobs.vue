<script setup lang="ts">
import { getPaginationRowModel } from "@tanstack/vue-table";
import { useI18n } from "vue-i18n";
import { useForm } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { computed, ref, useTemplateRef } from "vue";
import { useToast } from "@nuxt/ui/composables";

const props = defineProps({
	phantomJobs: {
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
const editingJob = ref(null);
const deletingJob = ref(null);
const pagination = ref({
	pageIndex: 0,
	pageSize: 6
});
const globalFilter = ref('');

const createForm = useForm({
	name: '',
	max_level: 1,
	icon_url: '',
	black_icon_url: '',
	transparent_icon_url: '',
	sprite_url: ''
});

const editForm = useForm({
	name: '',
	max_level: 1,
	icon_url: '',
	black_icon_url: '',
	transparent_icon_url: '',
	sprite_url: ''
});

const openCreateModal = () => {
	createForm.reset();
	createForm.max_level = 1;
	isCreateModalOpen.value = true;
};

const openEditModal = (phantomJob) => {
	editingJob.value = phantomJob;
	editForm.name = phantomJob.original.name;
	editForm.max_level = phantomJob.original.max_level;
	editForm.icon_url = phantomJob.original.icon_url || '';
	editForm.black_icon_url = phantomJob.original.black_icon_url || '';
	editForm.transparent_icon_url = phantomJob.original.transparent_icon_url || '';
	editForm.sprite_url = phantomJob.original.sprite_url || '';
	isEditModalOpen.value = true;
};

const openDeleteModal = (phantomJob) => {
	deletingJob.value = phantomJob;
	isDeleteModalOpen.value = true;
};

const submitCreate = () => {
	createForm.post(route('admin.phantom-jobs.store'), {
		onSuccess: () => {
			isCreateModalOpen.value = false;
			toast.add({
				title: t('general.success'),
				description: t('admin.phantom_jobs.toasts.created'),
				color: 'success',
				icon: 'i-lucide-check'
			});
			createForm.reset();
			createForm.max_level = 1;
		}
	});
};

const submitEdit = () => {
	editForm.put(route('admin.phantom-jobs.update', editingJob.value.original.id), {
		onSuccess: () => {
			isEditModalOpen.value = false;
			toast.add({
				title: t('general.success'),
				description: t('admin.phantom_jobs.toasts.updated'),
				color: 'success',
				icon: 'i-lucide-check'
			});
		}
	});
};

const confirmDelete = () => {
	useForm({}).delete(route('admin.phantom-jobs.destroy', deletingJob.value.original.id), {
		onSuccess: () => {
			isDeleteModalOpen.value = false;
			deletingJob.value = null;
			toast.add({
				title: t('general.success'),
				description: t('admin.phantom_jobs.toasts.deleted'),
				color: 'success',
				icon: 'i-lucide-check'
			});
		}
	});
};

const columns = computed(() => [
	{ accessorKey: 'name', header: t('admin.phantom_jobs.table.name') },
	{ accessorKey: 'max_level', header: t('admin.phantom_jobs.table.max_level') },
	{ accessorKey: 'icon_url', header: t('admin.phantom_jobs.table.icon') },
	{ accessorKey: 'black_icon_url', header: t('admin.phantom_jobs.table.black_icon') },
	{ accessorKey: 'transparent_icon_url', header: t('admin.phantom_jobs.table.transparent_icon') },
	{ accessorKey: 'sprite_url', header: t('admin.phantom_jobs.table.sprite') },
	{ id: 'actions' }
]);

const shouldFixTableHeight = () => {
	return (table.value?.tableApi?.getFilteredRowModel().rows.length ?? 0) > pagination.value.pageSize;
};
</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-row items-center justify-between">
				<div class="flex flex-row items-center font-semibold text-md gap-2">
					<UIcon name="i-lucide-ghost" size="22" />
					<p>{{ t('admin.phantom_jobs.section_title') }}</p>
					<UBadge :label="t('admin.phantom_jobs.section_badge', { count: phantomJobs.length })" color="neutral" variant="subtle" />
				</div>
				<div class="flex items-center gap-2">
					<UInput
						v-model="globalFilter"
						class="w-72"
						icon="i-lucide-search"
						:placeholder="t('admin.phantom_jobs.search_placeholder')"
					/>
					<UButton
						@click.prevent="openCreateModal"
						type="button"
						icon="i-lucide-plus"
						:label="t('admin.phantom_jobs.create_modal.title')"
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
					:data="phantomJobs"
					:columns="columns"
					:pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
					class="w-full"
				>
					<template #name-cell="{ row }">
						<span class="font-semibold">{{ row.original.name }}</span>
					</template>

					<template #max_level-cell="{ row }">
						<UBadge :label="`${row.original.max_level}`" color="neutral" variant="subtle" />
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
								{{ t('admin.phantom_jobs.table.missing_icon') }}
							</span>
						</div>
					</template>

					<template #black_icon_url-cell="{ row }">
						<div class="flex items-center gap-3">
							<img
								v-if="row.original.black_icon_url"
								:src="row.original.black_icon_url"
								:alt="`${row.original.name} black icon`"
								class="h-8 w-8 rounded-sm object-contain"
							>
							<span v-else class="text-sm text-gray-600 dark:text-gray-400">
								{{ t('admin.phantom_jobs.table.missing_black_icon') }}
							</span>
						</div>
					</template>

					<template #transparent_icon_url-cell="{ row }">
						<div class="flex items-center gap-3">
							<img
								v-if="row.original.transparent_icon_url"
								:src="row.original.transparent_icon_url"
								:alt="`${row.original.name} transparent icon`"
								class="h-8 w-8 rounded-sm object-contain"
							>
							<span v-else class="text-sm text-gray-600 dark:text-gray-400">
								{{ t('admin.phantom_jobs.table.missing_transparent_icon') }}
							</span>
						</div>
					</template>

					<template #sprite_url-cell="{ row }">
						<div class="flex items-center gap-3">
							<img
								v-if="row.original.sprite_url"
								:src="row.original.sprite_url"
								:alt="`${row.original.name} sprite`"
								class="h-8 w-8 rounded-sm object-contain"
							>
							<span v-else class="text-sm text-gray-600 dark:text-gray-400">
								{{ t('admin.phantom_jobs.table.missing_sprite') }}
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
		:title="t('admin.phantom_jobs.create_modal.title')"
		:description="t('admin.phantom_jobs.create_modal.subtitle')"
		:ui="{ content: 'rounded-sm', header: 'border-0'}"
	>
		<template #body>
			<form @submit.prevent="submitCreate" class="w-full flex flex-col gap-4">
				<div class="w-full flex flex-row items-start justify-evenly gap-4">
					<UFormField :label="t('admin.phantom_jobs.fields.name.label')" class="w-3/5" required>
						<UInput
							v-model="createForm.name"
							:placeholder="t('admin.phantom_jobs.fields.name.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>

					<UFormField :label="t('admin.phantom_jobs.fields.max_level.label')" class="w-2/5" required>
						<UInput
							v-model="createForm.max_level"
							type="number"
							:min="1"
							:placeholder="t('admin.phantom_jobs.fields.max_level.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>
				</div>

				<div class="w-full flex flex-col items-start justify-evenly gap-4">
					<UFormField :label="t('admin.phantom_jobs.fields.icon_url.label')" class="w-full">
						<UInput
							v-model="createForm.icon_url"
							:placeholder="t('admin.phantom_jobs.fields.icon_url.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>

					<UFormField :label="t('admin.phantom_jobs.fields.black_icon_url.label')" class="w-full">
						<UInput
							v-model="createForm.black_icon_url"
							:placeholder="t('admin.phantom_jobs.fields.black_icon_url.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>

					<UFormField :label="t('admin.phantom_jobs.fields.transparent_icon_url.label')" class="w-full">
						<UInput
							v-model="createForm.transparent_icon_url"
							:placeholder="t('admin.phantom_jobs.fields.transparent_icon_url.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>

					<UFormField :label="t('admin.phantom_jobs.fields.sprite_url.label')" class="w-full">
						<UInput
							v-model="createForm.sprite_url"
							:placeholder="t('admin.phantom_jobs.fields.sprite_url.placeholder')"
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
		:title="t('admin.phantom_jobs.edit_modal.title')"
		:description="t('admin.phantom_jobs.edit_modal.subtitle', { name: editForm.name })"
		:ui="{ content: 'rounded-sm', header: 'border-0'}"
	>
		<template #body>
			<form @submit.prevent="submitEdit" class="w-full flex flex-col gap-4">
				<div class="w-full flex flex-row items-start justify-evenly gap-4">
					<UFormField :label="t('admin.phantom_jobs.fields.name.label')" class="w-3/5" required>
						<UInput
							v-model="editForm.name"
							:placeholder="t('admin.phantom_jobs.fields.name.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>

					<UFormField :label="t('admin.phantom_jobs.fields.max_level.label')" class="w-2/5" required>
						<UInput
							v-model="editForm.max_level"
							type="number"
							:min="1"
							:placeholder="t('admin.phantom_jobs.fields.max_level.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>
				</div>

				<div class="w-full flex flex-col items-start justify-evenly gap-4">
					<UFormField :label="t('admin.phantom_jobs.fields.icon_url.label')" class="w-full">
						<UInput
							v-model="editForm.icon_url"
							:placeholder="t('admin.phantom_jobs.fields.icon_url.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>

					<UFormField :label="t('admin.phantom_jobs.fields.black_icon_url.label')" class="w-full">
						<UInput
							v-model="editForm.black_icon_url"
							:placeholder="t('admin.phantom_jobs.fields.black_icon_url.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>

					<UFormField :label="t('admin.phantom_jobs.fields.transparent_icon_url.label')" class="w-full">
						<UInput
							v-model="editForm.transparent_icon_url"
							:placeholder="t('admin.phantom_jobs.fields.transparent_icon_url.placeholder')"
							size="xl"
							class="w-full"
						/>
					</UFormField>

					<UFormField :label="t('admin.phantom_jobs.fields.sprite_url.label')" class="w-full">
						<UInput
							v-model="editForm.sprite_url"
							:placeholder="t('admin.phantom_jobs.fields.sprite_url.placeholder')"
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
		v-if="deletingJob"
		:title="t('admin.phantom_jobs.delete_modal.title', { name: deletingJob.original.name ?? '' })"
		:description="t('admin.phantom_jobs.delete_modal.subtitle', { name: deletingJob.original.name ?? '' })"
		:ui="{ content: 'rounded-sm', header: 'border-0'}"
	>
		<template #body>
			<div class="flex flex-col gap-4">
				<p class="text-sm text-red-600 dark:text-red-400">
					{{ t('admin.phantom_jobs.delete_modal.warning') }}
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
