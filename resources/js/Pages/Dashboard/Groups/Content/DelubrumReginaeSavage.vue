<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue'
import BozjaHolsterEditor from '@/components/Groups/Content/BozjaHolsterEditor.vue'
import BozjaHolsterList from '@/components/Groups/Content/BozjaHolsterList.vue'
import { useConfirmationModal } from '@/composables/useConfirmationModal'
import type { BozjaHolsterSummary, BozjaItemOption } from '@/Types/Bozja'
import axios from 'axios'
import { useToast } from '@nuxt/ui/composables'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const props = defineProps<{
	group: {
		id: number
		name: string
		slug: string
	}
	holsters: BozjaHolsterSummary[]
	bozja_items: BozjaItemOption[]
}>()

const { t } = useI18n()
const toast = useToast()
const confirmationModal = useConfirmationModal()
const holsterRecords = ref<BozjaHolsterSummary[]>([...props.holsters])
const selectedHolsterId = ref<number | null>(null)
const isCreating = ref(false)
const updatingHolsterIds = ref<number[]>([])
const editorOpen = computed(() => isCreating.value || selectedHolsterId.value !== null)
const selectedHolster = computed(() => (
	holsterRecords.value.find(holster => holster.id === selectedHolsterId.value) ?? null
))

const editHolster = (holsterId: number) => {
	selectedHolsterId.value = holsterId
	isCreating.value = false
}

const createHolster = () => {
	selectedHolsterId.value = null
	isCreating.value = true
}

const closeEditor = () => {
	selectedHolsterId.value = null
	isCreating.value = false
}

const holsterSaved = (holster: BozjaHolsterSummary) => {
	const index = holsterRecords.value.findIndex(record => record.id === holster.id)

	if (index === -1) {
		holsterRecords.value.unshift(holster)
	} else {
		holsterRecords.value[index] = holster
	}

	closeEditor()
}

const setUpdating = (holsterId: number, updating: boolean) => {
	updatingHolsterIds.value = updating
		? [...new Set([...updatingHolsterIds.value, holsterId])]
		: updatingHolsterIds.value.filter(id => id !== holsterId)
}

const toggleHolsterActive = async (payload: { holsterId: number, isActive: boolean }) => {
	if (updatingHolsterIds.value.includes(payload.holsterId)) {
		return
	}

	setUpdating(payload.holsterId, true)

	try {
		const response = await axios.patch(route(
			'groups.dashboard.content.delubrum-reginae-savage.holsters.status.update',
			{
				group: props.group.slug,
				bozjaHolster: payload.holsterId,
			},
		), {
			is_active: payload.isActive,
		})
		const index = holsterRecords.value.findIndex(holster => holster.id === payload.holsterId)

		if (index !== -1) {
			holsterRecords.value[index] = response.data.data
		}

		toast.add({
			title: t('groups.index.content.delubrum_reginae_savage.holsters.status_updated'),
			color: 'success',
			icon: 'i-lucide-check',
		})
	} catch {
		toast.add({
			title: t('general.error'),
			description: t('groups.index.content.delubrum_reginae_savage.holsters.status_update_failed'),
			color: 'error',
			icon: 'i-lucide-circle-alert',
		})
	} finally {
		setUpdating(payload.holsterId, false)
	}
}

const makeDefaultHolster = async (holsterId: number) => {
	if (updatingHolsterIds.value.includes(holsterId)) {
		return
	}

	setUpdating(holsterId, true)

	try {
		const response = await axios.patch(route(
			'groups.dashboard.content.delubrum-reginae-savage.holsters.default.update',
			{ group: props.group.slug, bozjaHolster: holsterId },
		))
		holsterRecords.value = holsterRecords.value.map(holster => ({
			...holster,
			is_default: holster.id === holsterId,
			...(holster.id === holsterId ? response.data.data : {}),
		}))
		toast.add({
			title: t('groups.index.content.delubrum_reginae_savage.holsters.default_updated'),
			color: 'success',
			icon: 'i-lucide-star',
		})
	} catch {
		toast.add({
			title: t('general.error'),
			description: t('groups.index.content.delubrum_reginae_savage.holsters.default_update_failed'),
			color: 'error',
			icon: 'i-lucide-circle-alert',
		})
	} finally {
		setUpdating(holsterId, false)
	}
}

const deleteHolster = async (holster: BozjaHolsterSummary) => {
	await confirmationModal.open({
		title: t('groups.index.content.delubrum_reginae_savage.holsters.delete_modal.title', {
			name: holster.display_name || t('groups.index.content.delubrum_reginae_savage.holsters.untitled'),
		}),
		description: t('groups.index.content.delubrum_reginae_savage.holsters.delete_modal.description'),
		warningText: t('groups.index.content.delubrum_reginae_savage.holsters.delete_modal.warning'),
		severity: 'error',
		confirmLabel: t('general.delete'),
		confirmIcon: 'i-lucide-trash-2',
		onConfirm: async ({ patch }) => {
			patch({ confirmLoading: true })

			try {
				await axios.delete(route(
					'groups.dashboard.content.delubrum-reginae-savage.holsters.destroy',
					{ group: props.group.slug, bozjaHolster: holster.id },
				))
				holsterRecords.value = holsterRecords.value.filter(record => record.id !== holster.id)
				toast.add({
					title: t('groups.index.content.delubrum_reginae_savage.holsters.deleted'),
					color: 'success',
					icon: 'i-lucide-check',
				})

				return true
			} catch {
				toast.add({
					title: t('general.error'),
					description: t('groups.index.content.delubrum_reginae_savage.holsters.delete_failed'),
					color: 'error',
					icon: 'i-lucide-circle-alert',
				})

				return false
			} finally {
				patch({ confirmLoading: false })
			}
		},
	})
}
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('groups.index.content.delubrum_reginae_savage.title')"
			:subtitle="t('groups.index.content.delubrum_reginae_savage.subtitle')"
		>
			<UButton
				v-if="!editorOpen"
				color="neutral"
				icon="i-lucide-plus"
				:label="t('groups.index.content.delubrum_reginae_savage.holsters.create')"
				@click="createHolster"
			/>
			<UButton
				v-else
				color="neutral"
				variant="ghost"
				icon="i-lucide-arrow-left"
				:label="t('groups.index.content.delubrum_reginae_savage.holsters.back_to_list')"
				@click="closeEditor"
			/>
		</PageHeader>

		<div class="mt-4">
			<BozjaHolsterList
				v-if="!editorOpen"
				:holsters="holsterRecords"
				:updating-holster-ids="updatingHolsterIds"
				@edit="editHolster"
				@toggle-active="toggleHolsterActive"
				@make-default="makeDefaultHolster"
				@delete="deleteHolster"
			/>

			<BozjaHolsterEditor
				v-else
				:key="isCreating ? 'new' : selectedHolsterId ?? 'empty'"
				:group-slug="group.slug"
				:holster="selectedHolster"
				:bozja-items="bozja_items"
				:is-creating="isCreating"
				@saved="holsterSaved"
			/>
		</div>
	</div>
</template>
