<script setup lang="ts">
import axios from 'axios'
import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@nuxt/ui/composables'
import { useConfirmationModal } from '../../../js/composables/useConfirmationModal'
import type {
	RaidPlanArenaMapDisplayMode,
	RaidPlanArenaMapComponent,
	RaidPlanComponentType,
	RaidPlanMarkerLayoutComponent,
	RaidPlanMarkerLayoutType,
	RaidPlanMechanicDraft,
	RaidPlanMechanicType,
} from '../../types/RaidPlan'
import {
	emptyRaidPlanTimeline,
	isArenaMapComponent,
	isMarkerLayoutComponent,
} from '../../utils/raidPlanTimeline'
import { parseWaymarkStudioPreset } from '../../utils/markerLayouts'
import PlannerScrubNumberInput from './PlannerScrubNumberInput.vue'

const { t } = useI18n()
const toast = useToast()
const confirmationModal = useConfirmationModal()
const mechanic = defineModel<RaidPlanMechanicDraft | null>('mechanic', {
	required: true,
})
const props = defineProps<{
	canChangeType: boolean
	assetUploadUrl?: string
	disabled: boolean
}>()
const emit = defineEmits<{
	inspectMechanic: [mechanicKey: string]
}>()

const componentPickerOpen = ref(false)
const componentSearch = ref('')
const imageInput = ref<HTMLInputElement | null>(null)
const imageUploadTarget = ref<string | null>(null)
const uploadingImage = ref(false)

const typeOptions = computed(() => [
	{
		label: t('planner.editor.mechanic.type_fixed'),
		value: 'fixed' satisfies RaidPlanMechanicType,
	},
	{
		label: t('planner.editor.mechanic.type_random'),
		value: 'random_set' satisfies RaidPlanMechanicType,
	},
])
const displayModeOptions = computed(() => [
	{
		label: t('planner.editor.mechanic.display_fit'),
		value: 'fit' satisfies RaidPlanArenaMapDisplayMode,
	},
	{
		label: t('planner.editor.mechanic.display_fill'),
		value: 'fill' satisfies RaidPlanArenaMapDisplayMode,
	},
	{
		label: t('planner.editor.mechanic.display_crop'),
		value: 'crop' satisfies RaidPlanArenaMapDisplayMode,
	},
])
const markerLayoutOptions = computed(() => [
	{
		label: t('planner.editor.mechanic.marker_layout_standard'),
		value: 'standard' satisfies RaidPlanMarkerLayoutType,
	},
	{
		label: t('planner.editor.mechanic.marker_layout_standard_flipped'),
		value: 'standard_flipped' satisfies RaidPlanMarkerLayoutType,
	},
	{
		label: t('planner.editor.mechanic.marker_layout_diamond'),
		value: 'diamond' satisfies RaidPlanMarkerLayoutType,
	},
	{
		label: t('planner.editor.mechanic.marker_layout_square'),
		value: 'square' satisfies RaidPlanMarkerLayoutType,
	},
	{
		label: t('planner.editor.mechanic.marker_layout_waymark_studio'),
		value: 'waymark_studio' satisfies RaidPlanMarkerLayoutType,
	},
])

const arenaMaps = computed(() => (
	mechanic.value?.timeline.components.filter(isArenaMapComponent) ?? []
))
const markerLayouts = computed(() => (
	mechanic.value?.timeline.components.filter(isMarkerLayoutComponent) ?? []
))
const availableComponentOptions = computed(() => {
	const options = [
		...(arenaMaps.value.length === 0 ? [{
			label: t('planner.editor.mechanic.arena_map'),
			value: 'arena_map' satisfies RaidPlanComponentType,
			icon: 'i-lucide-image',
		}] : []),
		...(markerLayouts.value.length === 0 ? [{
			label: t('planner.editor.mechanic.marker_layout'),
			value: 'marker_layout' satisfies RaidPlanComponentType,
			icon: 'i-lucide-map-pinned',
		}] : []),
	]
	const search = componentSearch.value.trim().toLocaleLowerCase()

	return search === ''
		? options
		: options.filter(option => option.label.toLocaleLowerCase().includes(search))
})

const createDraftKey = (prefix: string): string => {
	const suffix = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
		? crypto.randomUUID()
		: `${Date.now()}-${Math.random().toString(36).slice(2)}`

	return `${prefix}-${suffix}`
}

const updateMechanic = (changes: Partial<RaidPlanMechanicDraft>): void => {
	if (!mechanic.value) {
		return
	}

	mechanic.value = {
		...mechanic.value,
		...changes,
	}
}

const updateArenaMap = (
	componentId: string,
	changes: Partial<RaidPlanArenaMapComponent>,
): void => {
	if (!mechanic.value) {
		return
	}

	updateMechanic({
		timeline: {
			...mechanic.value.timeline,
			components: mechanic.value.timeline.components.map(component => (
				component.id === componentId && isArenaMapComponent(component)
					? { ...component, ...changes }
					: component
			)),
		},
	})
}

const updateNumber = (
	componentId: string,
	field: 'offset_x'
		| 'offset_y'
		| 'rotation'
		| 'crop_left'
		| 'crop_right'
		| 'crop_top'
		| 'crop_bottom',
	value: number,
): void => {
	if (Number.isFinite(value)) {
		updateArenaMap(componentId, { [field]: value })
	}
}

const updateMarkerLayout = (
	componentId: string,
	changes: Partial<RaidPlanMarkerLayoutComponent>,
): void => {
	if (!mechanic.value) {
		return
	}

	updateMechanic({
		timeline: {
			...mechanic.value.timeline,
			components: mechanic.value.timeline.components.map(component => (
				component.id === componentId && isMarkerLayoutComponent(component)
					? { ...component, ...changes }
					: component
			)),
		},
	})
}

const markerPresetError = (
	component: RaidPlanMarkerLayoutComponent,
): string | undefined => {
	const preset = component.waymark_preset?.trim()

	return component.layout === 'waymark_studio'
		&& preset
		&& !parseWaymarkStudioPreset(preset)
		? t('planner.editor.mechanic.marker_layout_preset_invalid')
		: undefined
}

const updateMechanicType = async (type: RaidPlanMechanicType): Promise<void> => {
	const current = mechanic.value

	if (!current || type === current.type || !props.canChangeType) {
		return
	}

	if (type === 'random_set') {
		const variant: RaidPlanMechanicDraft = {
			...current,
			key: createDraftKey('variant'),
			id: null,
			name: t('planner.editor.timeline.random_variant_number', { number: 1 }),
			type: 'fixed',
			variants: [],
		}

		updateMechanic({
			type: 'random_set',
			duration_ms: 0,
			timeline: emptyRaidPlanTimeline(),
			variants: [variant],
		})
		await nextTick()
		emit('inspectMechanic', variant.key)

		return
	}

	const confirmed = await confirmationModal.open({
		title: t('planner.editor.timeline.make_fixed_confirmation.title'),
		description: t('planner.editor.timeline.make_fixed_confirmation.description'),
		severity: 'warning',
		warningText: t('planner.editor.timeline.make_fixed_confirmation.warning'),
		confirmLabel: t('planner.editor.timeline.make_fixed'),
		confirmIcon: 'i-lucide-list-video',
	})

	if (!confirmed) {
		return
	}

	updateMechanic({
		type: 'fixed',
		duration_ms: 0,
		timeline: emptyRaidPlanTimeline(),
		variants: [],
	})
}

const addComponent = (type: RaidPlanComponentType): void => {
	if (!mechanic.value || props.disabled) {
		return
	}

	let component: RaidPlanArenaMapComponent | RaidPlanMarkerLayoutComponent

	if (type === 'arena_map' && arenaMaps.value.length === 0) {
		component = {
			id: createDraftKey('arena-map'),
			type: 'arena_map',
			image_url: null,
			display_mode: 'fit',
			offset_x: 0,
			offset_y: 0,
			rotation: 0,
			crop_left: 0,
			crop_right: 0,
			crop_top: 0,
			crop_bottom: 0,
		}
	} else if (type === 'marker_layout' && markerLayouts.value.length === 0) {
		component = {
			id: createDraftKey('marker-layout'),
			type: 'marker_layout',
			layout: 'standard',
			distance: 120,
			waymark_preset: null,
			offset_x: 0,
			offset_y: 0,
			rotation: 0,
		}
	} else {
		return
	}

	updateMechanic({
		timeline: {
			...mechanic.value.timeline,
			components: [...mechanic.value.timeline.components, component],
		},
	})
	componentPickerOpen.value = false
	componentSearch.value = ''
}

const removeComponent = async (
	component: RaidPlanArenaMapComponent | RaidPlanMarkerLayoutComponent,
): Promise<void> => {
	if (!mechanic.value || props.disabled) {
		return
	}

	const confirmed = await confirmationModal.open({
		title: t('planner.editor.mechanic.remove_component_title'),
		description: t('planner.editor.mechanic.remove_component_description'),
		severity: 'error',
		confirmLabel: t('planner.editor.mechanic.remove_component'),
		confirmIcon: 'i-lucide-trash-2',
	})

	if (!confirmed) {
		return
	}

	updateMechanic({
		timeline: {
			...mechanic.value.timeline,
			components: mechanic.value.timeline.components.filter(item => item.id !== component.id),
		},
	})
}

const chooseImage = (componentId: string): void => {
	if (!props.assetUploadUrl || props.disabled || uploadingImage.value) {
		return
	}

	imageUploadTarget.value = componentId
	imageInput.value?.click()
}

const uploadImage = async (event: Event): Promise<void> => {
	const input = event.target as HTMLInputElement
	const file = input.files?.[0]
	const componentId = imageUploadTarget.value

	input.value = ''

	if (!file || !componentId || !props.assetUploadUrl) {
		return
	}

	const data = new FormData()

	data.append('image', file)
	uploadingImage.value = true

	try {
		const response = await axios.post<{ data: { url: string } }>(
			props.assetUploadUrl,
			data,
			{
				headers: {
					Accept: 'application/json',
					'Content-Type': 'multipart/form-data',
				},
			},
		)

		updateArenaMap(componentId, {
			image_url: response.data.data.url,
		})
		toast.add({
			title: t('planner.editor.mechanic.image_uploaded'),
			icon: 'i-lucide-check',
			color: 'success',
		})
	} catch {
		toast.add({
			title: t('planner.editor.mechanic.image_upload_failed'),
			icon: 'i-lucide-triangle-alert',
			color: 'error',
		})
	} finally {
		uploadingImage.value = false
		imageUploadTarget.value = null
	}
}
</script>

<template>
	<div v-if="mechanic" class="min-h-0">
		<section class="space-y-4 border-b border-default p-4">
			<div>
				<p class="text-xs font-semibold uppercase text-dimmed">
					{{ t('planner.editor.mechanic.settings') }}
				</p>
			</div>

			<UFormField :label="t('planner.editor.mechanic.name')" required>
				<UInput
					:model-value="mechanic.name"
					:disabled="props.disabled"
					:maxlength="150"
					class="w-full"
					@update:model-value="updateMechanic({ name: String($event) })"
				/>
			</UFormField>

			<UFormField :label="t('planner.editor.mechanic.type')" required>
				<USelect
					:model-value="mechanic.type"
					:items="typeOptions"
					value-key="value"
					:disabled="props.disabled || !props.canChangeType"
					class="w-full"
					@update:model-value="updateMechanicType($event as RaidPlanMechanicType)"
				/>
			</UFormField>

			<UPopover v-model:open="componentPickerOpen">
				<UButton
					:label="t('planner.editor.mechanic.add_arena_components')"
					icon="i-lucide-plus"
					color="neutral"
					variant="outline"
					block
					:disabled="
						props.disabled
							|| mechanic.type !== 'fixed'
							|| availableComponentOptions.length === 0
					"
				/>

				<template #content>
					<div class="w-72 space-y-2 p-2">
						<UInput
							v-model="componentSearch"
							:placeholder="t('planner.editor.mechanic.search_components')"
							icon="i-lucide-search"
							autofocus
							class="w-full"
						/>

						<button
							v-for="option in availableComponentOptions"
							:key="option.value"
							type="button"
							class="flex w-full items-center gap-3 border border-transparent px-3 py-2 text-left text-sm hover:border-default hover:bg-accented"
							@click="addComponent(option.value)"
						>
							<UIcon :name="option.icon" class="size-4 text-primary" />
							<span>{{ option.label }}</span>
						</button>

						<p
							v-if="availableComponentOptions.length === 0"
							class="px-3 py-2 text-sm text-muted"
						>
							{{ t('planner.editor.mechanic.no_components') }}
						</p>
					</div>
				</template>
			</UPopover>
		</section>

		<section
			v-for="component in arenaMaps"
			:key="component.id"
			class="space-y-4 border-b border-default p-4"
		>
			<div class="flex items-center justify-between gap-3">
				<div class="flex min-w-0 items-center gap-2">
					<UIcon name="i-lucide-image" class="size-4 shrink-0 text-primary" />
					<p class="truncate text-sm font-semibold">
						{{ t('planner.editor.mechanic.arena_map') }}
					</p>
				</div>

				<UTooltip :text="t('planner.editor.mechanic.remove_component')">
					<UButton
						icon="i-lucide-trash-2"
						color="error"
						variant="ghost"
						size="xs"
						:disabled="props.disabled"
						:aria-label="t('planner.editor.mechanic.remove_component')"
						@click="removeComponent(component)"
					/>
				</UTooltip>
			</div>

			<div
				v-if="component.image_url"
				class="aspect-video overflow-hidden border border-default bg-default"
			>
				<img
					:src="component.image_url"
					:alt="t('planner.editor.mechanic.arena_map_preview')"
					class="size-full object-cover"
				>
			</div>

			<UFormField
				:label="t('planner.editor.mechanic.image')"
				:description="!props.assetUploadUrl
					? t('planner.editor.mechanic.save_before_upload')
					: t('planner.editor.mechanic.image_description')"
			>
				<UButton
					:label="component.image_url
						? t('planner.editor.mechanic.replace_image')
						: t('planner.editor.mechanic.upload_image')"
					icon="i-lucide-upload"
					color="neutral"
					variant="outline"
					block
					:loading="uploadingImage && imageUploadTarget === component.id"
					:disabled="props.disabled || !props.assetUploadUrl"
					@click="chooseImage(component.id)"
				/>
			</UFormField>

			<UFormField :label="t('planner.editor.mechanic.display_mode')">
				<USelect
					:model-value="component.display_mode"
					:items="displayModeOptions"
					value-key="value"
					:disabled="props.disabled"
					class="w-full"
					@update:model-value="updateArenaMap(component.id, {
						display_mode: $event as RaidPlanArenaMapDisplayMode,
					})"
				/>
			</UFormField>

			<div class="grid grid-cols-2 gap-3">
				<UFormField :label="t('planner.editor.mechanic.offset_x')">
					<PlannerScrubNumberInput
						:model-value="component.offset_x"
						:aria-label="t('planner.editor.mechanic.offset_x')"
						:min="-1280"
						:max="1280"
						:disabled="props.disabled"
						@update:model-value="updateNumber(component.id, 'offset_x', $event)"
					/>
				</UFormField>

				<UFormField :label="t('planner.editor.mechanic.offset_y')">
					<PlannerScrubNumberInput
						:model-value="component.offset_y"
						:aria-label="t('planner.editor.mechanic.offset_y')"
						:min="-720"
						:max="720"
						:disabled="props.disabled"
						@update:model-value="updateNumber(component.id, 'offset_y', $event)"
					/>
				</UFormField>
			</div>

			<UFormField :label="t('planner.editor.mechanic.rotation')">
				<PlannerScrubNumberInput
					:model-value="component.rotation"
					:aria-label="t('planner.editor.mechanic.rotation')"
					:min="-360"
					:max="360"
					:disabled="props.disabled"
					@update:model-value="updateNumber(component.id, 'rotation', $event)"
				/>
			</UFormField>

			<div
				v-if="component.display_mode === 'crop'"
				class="grid grid-cols-2 gap-3"
			>
				<UFormField :label="t('planner.editor.mechanic.crop_left')">
					<PlannerScrubNumberInput
						:model-value="component.crop_left"
						:aria-label="t('planner.editor.mechanic.crop_left')"
						:min="0"
						:max="99 - component.crop_right"
						:disabled="props.disabled"
						@update:model-value="updateNumber(component.id, 'crop_left', $event)"
					/>
				</UFormField>

				<UFormField :label="t('planner.editor.mechanic.crop_right')">
					<PlannerScrubNumberInput
						:model-value="component.crop_right"
						:aria-label="t('planner.editor.mechanic.crop_right')"
						:min="0"
						:max="99 - component.crop_left"
						:disabled="props.disabled"
						@update:model-value="updateNumber(component.id, 'crop_right', $event)"
					/>
				</UFormField>

				<UFormField :label="t('planner.editor.mechanic.crop_top')">
					<PlannerScrubNumberInput
						:model-value="component.crop_top"
						:aria-label="t('planner.editor.mechanic.crop_top')"
						:min="0"
						:max="99 - component.crop_bottom"
						:disabled="props.disabled"
						@update:model-value="updateNumber(component.id, 'crop_top', $event)"
					/>
				</UFormField>

				<UFormField :label="t('planner.editor.mechanic.crop_bottom')">
					<PlannerScrubNumberInput
						:model-value="component.crop_bottom"
						:aria-label="t('planner.editor.mechanic.crop_bottom')"
						:min="0"
						:max="99 - component.crop_top"
						:disabled="props.disabled"
						@update:model-value="updateNumber(component.id, 'crop_bottom', $event)"
					/>
				</UFormField>
			</div>
		</section>

		<section
			v-for="component in markerLayouts"
			:key="component.id"
			class="space-y-4 border-b border-default p-4"
		>
			<div class="flex items-center justify-between gap-3">
				<div class="flex min-w-0 items-center gap-2">
					<UIcon name="i-lucide-map-pinned" class="size-4 shrink-0 text-primary" />
					<p class="truncate text-sm font-semibold">
						{{ t('planner.editor.mechanic.marker_layout') }}
					</p>
				</div>

				<UTooltip :text="t('planner.editor.mechanic.remove_component')">
					<UButton
						icon="i-lucide-trash-2"
						color="error"
						variant="ghost"
						size="xs"
						:disabled="props.disabled"
						:aria-label="t('planner.editor.mechanic.remove_component')"
						@click="removeComponent(component)"
					/>
				</UTooltip>
			</div>

			<UFormField :label="t('planner.editor.mechanic.marker_layout_type')">
				<USelect
					:model-value="component.layout"
					:items="markerLayoutOptions"
					value-key="value"
					:disabled="props.disabled"
					class="w-full"
					@update:model-value="updateMarkerLayout(component.id, {
						layout: $event as RaidPlanMarkerLayoutType,
					})"
				/>
			</UFormField>

			<UFormField
				v-if="component.layout === 'waymark_studio'"
				:label="t('planner.editor.mechanic.marker_layout_preset')"
				:description="t('planner.editor.mechanic.marker_layout_preset_description')"
				:error="markerPresetError(component)"
			>
				<UTextarea
					:model-value="component.waymark_preset ?? ''"
					:placeholder="t('planner.editor.mechanic.marker_layout_preset_placeholder')"
					:rows="8"
					:maxlength="10000"
					:disabled="props.disabled"
					autoresize
					class="w-full font-mono text-xs"
					@update:model-value="updateMarkerLayout(component.id, {
						waymark_preset: String($event).trim() || null,
					})"
				/>
			</UFormField>

			<UFormField :label="t('planner.editor.mechanic.marker_layout_distance')">
				<PlannerScrubNumberInput
					:model-value="component.distance"
					:aria-label="t('planner.editor.mechanic.marker_layout_distance')"
					:min="20"
					:max="500"
					:step="1"
					:disabled="props.disabled"
					@update:model-value="Number.isFinite($event)
						&& updateMarkerLayout(component.id, { distance: $event })"
				/>
			</UFormField>
		</section>

		<input
			ref="imageInput"
			type="file"
			accept="image/jpeg,image/png,image/webp"
			class="hidden"
			@change="uploadImage"
		>
	</div>
</template>
