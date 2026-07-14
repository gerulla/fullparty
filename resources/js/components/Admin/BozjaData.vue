<script setup lang="ts">
// @ts-ignore
import { useConfirmationModal } from '@/composables/useConfirmationModal'
import type { BozjaItemRecord } from '@/Types/Bozja'
import LocalizedTextFields from '@/components/Admin/ActivityTypes/LocalizedTextFields.vue'
import { localizedValue } from '@/utils/localizedValue'
import { getPaginationRowModel } from '@tanstack/vue-table'
import { useForm, usePage } from '@inertiajs/vue3'
import { useToast } from '@nuxt/ui/composables'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const props = defineProps<{
	bozjaItems: BozjaItemRecord[]
}>()

const locales = ['en', 'de', 'fr', 'ja']
const categoryValues = [
	'banners',
	'essences',
	'deep_essences',
	'pure_essences',
	'lost_actions',
	'lost_items',
]

const { t, locale } = useI18n()
const page = usePage()
const toast = useToast()
const confirmationModal = useConfirmationModal()
const selectedCategory = ref('all')
const search = ref('')
const isModalOpen = ref(false)
const editingItem = ref<BozjaItemRecord | null>(null)
const pagination = ref({ pageIndex: 0, pageSize: 15 })
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'))

const emptyLocalizedText = () => Object.fromEntries(locales.map((itemLocale) => [itemLocale, '']))

const form = useForm({
	key: '',
	category: 'essences',
	name: emptyLocalizedText(),
	description: emptyLocalizedText(),
	classification: 'essence',
	cache_weight: 0,
	sort_order: 0,
	is_active: true,
	icon: null as File | null,
})

const categoryTabs = computed(() => [
	{
		label: t('admin.bozja_data.tabs.all', { count: props.bozjaItems.length }),
		value: 'all',
	},
	...categoryValues.map((category) => ({
		label: t(`admin.bozja_data.categories.${category}`, {
			count: props.bozjaItems.filter((item) => item.category === category).length,
		}),
		value: category,
	})),
])

const categoryOptions = computed(() => categoryValues.map((category) => ({
	label: t(`admin.bozja_data.categories.${category}`, { count: '' }),
	value: category,
})))

const displayName = (item: BozjaItemRecord) => localizedValue(
	item.name,
	locale.value,
	fallbackLocale.value,
) || item.key

const filteredItems = computed(() => {
	const query = search.value.trim().toLowerCase()

	return props.bozjaItems.filter((item) => {
		if (selectedCategory.value !== 'all' && item.category !== selectedCategory.value) {
			return false
		}

		if (query === '') {
			return true
		}

		return [
			displayName(item),
			item.key,
			item.classification,
			...Object.values(item.name ?? {}),
		].some((value) => String(value ?? '').toLowerCase().includes(query))
	})
})

const columns = computed(() => [
	{ accessorKey: 'name', header: t('admin.bozja_data.table.item') },
	{ accessorKey: 'classification', header: t('admin.bozja_data.table.classification') },
	{ accessorKey: 'cache_weight', header: t('admin.bozja_data.table.weight') },
	{ accessorKey: 'is_active', header: t('admin.bozja_data.table.status') },
	{ id: 'actions' },
])

watch([selectedCategory, search], () => {
	pagination.value.pageIndex = 0
})

const categoryClassification = (category: string) => ({
	banners: 'banner',
	essences: 'essence',
	deep_essences: 'deep_essence',
	pure_essences: 'pure_essence',
	lost_actions: 'lost_action',
	lost_items: 'lost_item',
}[category] ?? '')

const openCreateModal = () => {
	const category = selectedCategory.value === 'all' ? 'essences' : selectedCategory.value

	editingItem.value = null
	form.defaults({
		key: '',
		category,
		name: emptyLocalizedText(),
		description: emptyLocalizedText(),
		classification: categoryClassification(category),
		cache_weight: 0,
		sort_order: props.bozjaItems.length,
		is_active: true,
		icon: null,
	})
	form.reset()
	form.clearErrors()
	isModalOpen.value = true
}

const openEditModal = (item: BozjaItemRecord) => {
	editingItem.value = item
	form.defaults({
		key: item.key,
		category: item.category,
		name: { ...emptyLocalizedText(), ...item.name },
		description: { ...emptyLocalizedText(), ...(item.description ?? {}) },
		classification: item.classification,
		cache_weight: item.cache_weight,
		sort_order: item.sort_order,
		is_active: item.is_active,
		icon: null,
	})
	form.reset()
	form.clearErrors()
	isModalOpen.value = true
}

const updateIcon = (event: Event) => {
	form.icon = (event.target as HTMLInputElement).files?.[0] ?? null
}

const submit = () => {
	const options = {
		forceFormData: true,
		onSuccess: () => {
			isModalOpen.value = false
			toast.add({
				title: t('general.success'),
				description: t(editingItem.value
					? 'admin.bozja_data.toasts.updated'
					: 'admin.bozja_data.toasts.created'),
				color: 'success' as const,
				icon: 'i-lucide-check',
			})
			editingItem.value = null
		},
	}

	if (editingItem.value) {
		form
			.transform((data) => ({ ...data, _method: 'put' }))
			.post(route('admin.bozja-items.update', editingItem.value.id), options)
		return
	}

	form
		.transform((data) => data)
		.post(route('admin.bozja-items.store'), options)
}

const deleteItem = async (item: BozjaItemRecord) => {
	await confirmationModal.open({
		title: t('admin.bozja_data.delete_modal.title', { name: displayName(item) }),
		description: t('admin.bozja_data.delete_modal.description'),
		severity: 'warning',
		confirmLabel: t('admin.bozja_data.delete_modal.confirm'),
		confirmIcon: 'i-lucide-trash-2',
		onConfirm: async ({ patch }) => {
			patch({ confirmLoading: true })

			return await new Promise<boolean>((resolve) => {
				useForm({}).delete(route('admin.bozja-items.destroy', item.id), {
					onSuccess: () => {
						toast.add({
							title: t('general.success'),
							description: t('admin.bozja_data.toasts.deleted'),
							color: 'success',
							icon: 'i-lucide-check',
						})
						resolve(true)
					},
					onError: () => resolve(false),
					onFinish: () => patch({ confirmLoading: false }),
				})
			})
		},
	})
}
</script>

<template>
	<UCard class="w-full dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
				<div class="flex items-center gap-3">
					<div class="flex size-10 items-center justify-center border border-default bg-muted/30">
						<UIcon name="i-lucide-package-open" class="size-5 text-brand-300" />
					</div>
					<div>
						<div class="flex items-center gap-2">
							<h2 class="text-base font-semibold">{{ t('admin.bozja_data.section_title') }}</h2>
							<UBadge :label="t('admin.bozja_data.section_badge', { count: bozjaItems.length })" color="neutral" variant="subtle" />
						</div>
						<p class="text-sm text-muted">{{ t('admin.bozja_data.section_description') }}</p>
					</div>
				</div>

				<div class="flex flex-col gap-2 sm:flex-row">
					<UInput v-model="search" icon="i-lucide-search" :placeholder="t('admin.bozja_data.search_placeholder')" class="w-full sm:w-72" />
					<UButton icon="i-lucide-plus" :label="t('admin.bozja_data.actions.create')" @click="openCreateModal" />
				</div>
			</div>
		</template>

		<UTabs v-model="selectedCategory" :items="categoryTabs" :content="false" variant="link" class="mb-4 w-full" />

		<UAlert
			v-if="filteredItems.length === 0"
			color="neutral"
			variant="soft"
			icon="i-lucide-search-x"
			:title="t('admin.bozja_data.empty.title')"
			:description="t('admin.bozja_data.empty.description')"
		/>

		<div v-else class="overflow-auto">
			<UTable
				v-model:pagination="pagination"
				:data="filteredItems"
				:columns="columns"
				:pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
				class="w-full"
			>
				<template #name-cell="{ row }">
					<div class="flex min-w-64 items-center gap-3">
						<div class="flex size-11 shrink-0 items-center justify-center border border-default bg-muted/30">
							<img v-if="row.original.icon_url" :src="row.original.icon_url" :alt="displayName(row.original)" class="size-9 object-contain">
							<UIcon v-else name="i-lucide-package" class="size-5 text-muted" />
						</div>
						<div class="min-w-0">
							<p class="truncate font-semibold text-highlighted">{{ displayName(row.original) }}</p>
							<p class="truncate text-xs text-muted">{{ row.original.key }}</p>
						</div>
					</div>
				</template>

				<template #classification-cell="{ row }">
					<UBadge :label="row.original.classification" color="neutral" variant="subtle" />
				</template>

				<template #cache_weight-cell="{ row }">
					<span class="tabular-nums">{{ row.original.cache_weight }}</span>
				</template>

				<template #is_active-cell="{ row }">
					<UBadge
						:label="t(row.original.is_active ? 'admin.bozja_data.status.active' : 'admin.bozja_data.status.inactive')"
						:color="row.original.is_active ? 'success' : 'neutral'"
						variant="subtle"
					/>
				</template>

				<template #actions-cell="{ row }">
					<div class="flex justify-end gap-1">
						<UButton icon="i-lucide-pencil" color="neutral" variant="ghost" :aria-label="t('general.edit')" @click="openEditModal(row.original)" />
						<UButton icon="i-lucide-trash-2" color="error" variant="ghost" :aria-label="t('general.delete')" @click="deleteItem(row.original)" />
					</div>
				</template>
			</UTable>

			<div class="mt-4 flex justify-end border-t border-default pt-4">
				<UPagination
					:page="pagination.pageIndex + 1"
					:items-per-page="pagination.pageSize"
					:total="filteredItems.length"
					@update:page="pagination.pageIndex = $event - 1"
				/>
			</div>
		</div>
	</UCard>

	<UModal
		v-model:open="isModalOpen"
		:title="t(editingItem ? 'admin.bozja_data.edit_modal.title' : 'admin.bozja_data.create_modal.title')"
		:description="t(editingItem ? 'admin.bozja_data.edit_modal.description' : 'admin.bozja_data.create_modal.description')"
		:ui="{ content: 'max-w-5xl rounded-sm', header: 'border-0' }"
	>
		<template #body>
			<form class="flex flex-col gap-5" @submit.prevent="submit">
				<div class="grid gap-4 md:grid-cols-2">
					<UFormField :label="t('admin.bozja_data.fields.key')" :error="form.errors.key" required>
						<UInput v-model="form.key" class="w-full" />
					</UFormField>

					<UFormField :label="t('admin.bozja_data.fields.category')" :error="form.errors.category" required>
						<USelect v-model="form.category" :items="categoryOptions" value-key="value" class="w-full" @update:model-value="form.classification = categoryClassification(String($event))" />
					</UFormField>
				</div>

				<LocalizedTextFields
					v-model="form.name"
					:locales="locales"
					:label="t('admin.bozja_data.fields.name')"
					:description="t('admin.bozja_data.fields.name_description')"
				/>

				<LocalizedTextFields
					v-model="form.description"
					:locales="locales"
					:label="t('admin.bozja_data.fields.description')"
					multiline
					:rows="5"
				/>

				<div class="grid gap-4 sm:grid-cols-3">
					<UFormField :label="t('admin.bozja_data.fields.classification')" :error="form.errors.classification" required>
						<UInput v-model="form.classification" class="w-full" />
					</UFormField>
					<UFormField :label="t('admin.bozja_data.fields.cache_weight')" :error="form.errors.cache_weight" required>
						<UInput v-model="form.cache_weight" type="number" :min="0" class="w-full" />
					</UFormField>
					<UFormField :label="t('admin.bozja_data.fields.sort_order')" :error="form.errors.sort_order" required>
						<UInput v-model="form.sort_order" type="number" :min="0" class="w-full" />
					</UFormField>
				</div>

				<div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_12rem]">
					<UFormField :label="t('admin.bozja_data.fields.icon')" :description="t('admin.bozja_data.fields.icon_description')" :error="form.errors.icon">
						<label class="file-upload-field">
							<UIcon name="i-lucide-upload" size="16" />
							<span class="truncate text-sm font-medium">{{ form.icon?.name || t('admin.bozja_data.fields.icon_placeholder') }}</span>
							<input class="sr-only" type="file" accept="image/*" @change="updateIcon">
						</label>
					</UFormField>

					<UFormField :label="t('admin.bozja_data.fields.is_active')" :error="form.errors.is_active">
						<div class="flex h-10 items-center">
							<USwitch v-model="form.is_active" />
						</div>
					</UFormField>
				</div>

				<UAlert
					v-if="editingItem?.has_source_payload"
					color="neutral"
					variant="subtle"
					icon="i-lucide-database"
					:title="t('admin.bozja_data.source_payload.title')"
					:description="t('admin.bozja_data.source_payload.description')"
				/>

				<div class="flex justify-end gap-2 border-t border-default pt-4">
					<UButton type="button" color="neutral" variant="ghost" :label="t('general.cancel')" @click="isModalOpen = false" />
					<UButton type="submit" :label="t(editingItem ? 'general.update' : 'general.create')" :loading="form.processing" />
				</div>
			</form>
		</template>
	</UModal>
</template>
