<script setup lang="ts">
import type { ContextMenuItem } from '@nuxt/ui'
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfirmationModal } from '../../../js/composables/useConfirmationModal'
import type {
	RaidPlanMechanicDraft,
	RaidPlanMechanicType,
} from '../../types/RaidPlan'
import { emptyRaidPlanTimeline } from '../../utils/raidPlanTimeline'

const { t } = useI18n()
const confirmationModal = useConfirmationModal()
const props = withDefaults(defineProps<{
	canEdit?: boolean
}>(), {
	canEdit: true,
})
const mechanics = defineModel<RaidPlanMechanicDraft[]>('mechanics', {
	required: true,
})
const emit = defineEmits<{
	change: []
	inspectMechanic: [mechanicKey: string]
}>()

type TimelineMode = 'timeline' | 'mechanics'

const timelineMode = ref<TimelineMode>('timeline')
const mechanicViewport = ref<HTMLElement | null>(null)
const mechanicSurface = ref<HTMLElement | null>(null)
const isPanningMechanics = ref(false)
const selectedMechanicKey = ref(
	mechanics.value.find(mechanic => mechanic.type === 'fixed')?.key
		?? mechanics.value.flatMap(mechanic => mechanic.variants)[0]?.key
		?? '',
)
let nextMechanicKey = 1
let panPointerId: number | null = null
let panStartX = 0
let panStartY = 0
let panStartOffsetX = 0
let panStartOffsetY = 0
let mechanicPanX = 0
let mechanicPanY = 0
let pendingPanX = 0
let pendingPanY = 0
let panFrameId: number | null = null

const rulerMarks = Array.from({ length: 13 }, (_, index) => index * 5)

const selectedMechanic = computed(() => {
	for (const mechanic of mechanics.value) {
		if (mechanic.key === selectedMechanicKey.value) {
			return mechanic
		}

		const variant = mechanic.variants.find(item => item.key === selectedMechanicKey.value)

		if (variant) {
			return variant
		}
	}

	return mechanics.value.find(mechanic => mechanic.type === 'fixed')
		?? mechanics.value.flatMap(mechanic => mechanic.variants)[0]
		?? null
})

const addMechanicItems = computed(() => [[
	{
		label: t('planner.editor.timeline.add_fixed_mechanic'),
		icon: 'i-lucide-list-video',
		onSelect: () => addMechanic('fixed'),
	},
	{
		label: t('planner.editor.timeline.add_random_set'),
		icon: 'i-lucide-shuffle',
		onSelect: () => addMechanic('random_set'),
	},
]])

const tracks = computed(() => [
	{
		label: t('planner.editor.timeline.boss'),
		icon: 'i-lucide-skull',
		events: [
			{ label: t('planner.editor.timeline.cast'), left: '8%', width: '22%' },
			{ label: t('planner.editor.timeline.resolve'), left: '52%', width: '14%' },
		],
	},
	{
		label: t('planner.editor.timeline.players'),
		icon: 'i-lucide-users-round',
		events: [
			{ label: t('planner.editor.timeline.movement'), left: '21%', width: '27%' },
			{ label: t('planner.editor.timeline.spread'), left: '68%', width: '19%' },
		],
	},
])

const toggleMechanicMenu = (): void => {
	timelineMode.value = timelineMode.value === 'timeline' ? 'mechanics' : 'timeline'
}

const applyMechanicPan = (x: number, y: number): void => {
	mechanicPanX = x
	mechanicPanY = y

	if (mechanicSurface.value) {
		mechanicSurface.value.style.transform = `translate3d(${x}px, ${y}px, 0)`
	}
}

const focusSelectedMechanic = async (): Promise<void> => {
	await nextTick()

	const viewport = mechanicViewport.value
	const selectedKey = selectedMechanic.value?.key

	if (!viewport || !selectedKey) {
		return
	}

	const target = viewport.querySelector<HTMLElement>(
		`[data-mechanic-key="${selectedKey}"]`,
	)

	if (!target) {
		return
	}

	const viewportRect = viewport.getBoundingClientRect()
	const targetRect = target.getBoundingClientRect()

	const x = mechanicPanX + (
		viewportRect.left
		+ (viewportRect.width / 2)
		- targetRect.left
		- (targetRect.width / 2)
	)
	const y = mechanicPanY + (
		viewportRect.top
		+ (viewportRect.height / 2)
		- targetRect.top
		- (targetRect.height / 2)
	)

	applyMechanicPan(x, y)
}

const startMechanicPan = (event: PointerEvent): void => {
	const viewport = mechanicViewport.value
	const target = event.target as HTMLElement

	if (
		!viewport
		|| event.button !== 0
		|| target.closest('button, a, input, textarea, select, [role="menuitem"]')
	) {
		return
	}

	panPointerId = event.pointerId
	panStartX = event.clientX
	panStartY = event.clientY
	panStartOffsetX = mechanicPanX
	panStartOffsetY = mechanicPanY
	pendingPanX = mechanicPanX
	pendingPanY = mechanicPanY
	isPanningMechanics.value = true
	viewport.setPointerCapture(event.pointerId)
	event.preventDefault()
	event.stopPropagation()
}

const panMechanics = (event: PointerEvent): void => {
	const viewport = mechanicViewport.value

	if (!viewport || panPointerId !== event.pointerId) {
		return
	}

	pendingPanX = panStartOffsetX + (event.clientX - panStartX)
	pendingPanY = panStartOffsetY + (event.clientY - panStartY)

	if (panFrameId === null) {
		panFrameId = window.requestAnimationFrame(() => {
			panFrameId = null
			applyMechanicPan(pendingPanX, pendingPanY)
		})
	}

	event.stopPropagation()
}

const stopMechanicPan = (event: PointerEvent): void => {
	const viewport = mechanicViewport.value

	if (!viewport || panPointerId !== event.pointerId) {
		return
	}

	if (viewport.hasPointerCapture(event.pointerId)) {
		viewport.releasePointerCapture(event.pointerId)
	}

	if (panFrameId !== null) {
		window.cancelAnimationFrame(panFrameId)
		panFrameId = null
	}

	applyMechanicPan(pendingPanX, pendingPanY)
	panPointerId = null
	isPanningMechanics.value = false
	event.stopPropagation()
}

watch(timelineMode, (mode) => {
	if (mode === 'mechanics') {
		void focusSelectedMechanic()
	}
})

onBeforeUnmount(() => {
	if (panFrameId !== null) {
		window.cancelAnimationFrame(panFrameId)
	}
})

const selectMechanic = (mechanicKey: string): void => {
	selectedMechanicKey.value = mechanicKey
	timelineMode.value = 'timeline'
	emit('inspectMechanic', mechanicKey)
}

const createMechanicKey = (prefix: string): string => (
	`${prefix}-${Date.now()}-${nextMechanicKey++}`
)

const createFixedMechanic = (
	name: string,
	overrides: Partial<RaidPlanMechanicDraft> = {},
): RaidPlanMechanicDraft => ({
	key: createMechanicKey('mechanic'),
	id: null,
	name,
	type: 'fixed',
	duration_ms: 0,
	selection_weight: 1,
	is_enabled: true,
	timeline: emptyRaidPlanTimeline(),
	timeline_schema_version: 1,
	variants: [],
	...overrides,
})

const notifyChange = (): void => {
	emit('change')
}

const addMechanic = (type: RaidPlanMechanicType): void => {
	if (!props.canEdit) {
		return
	}

	const mechanicNumber = mechanics.value.length + 1
	const mechanic = createFixedMechanic(
		type === 'random_set'
			? t('planner.editor.timeline.random_mechanic_number', { number: mechanicNumber })
			: t('planner.editor.timeline.mechanic_number', { number: mechanicNumber }),
		{
			type,
		},
	)

	mechanics.value.push(mechanic)
	timelineMode.value = 'mechanics'
	notifyChange()

	if (type === 'fixed') {
		selectMechanic(mechanic.key)
	}
}

const addVariant = (mechanic: RaidPlanMechanicDraft): void => {
	if (!props.canEdit || mechanic.type !== 'random_set') {
		return
	}

	const variant = createFixedMechanic(
		t('planner.editor.timeline.random_variant_number', {
			number: mechanic.variants.length + 1,
		}),
		{
			key: createMechanicKey('variant'),
		},
	)

	mechanic.variants.push(variant)
	notifyChange()
	selectMechanic(variant.key)
}

const moveMechanic = (index: number, offset: -1 | 1): void => {
	const targetIndex = index + offset

	if (!props.canEdit || targetIndex < 0 || targetIndex >= mechanics.value.length) {
		return
	}

	const [mechanic] = mechanics.value.splice(index, 1)

	if (!mechanic) {
		return
	}

	mechanics.value.splice(targetIndex, 0, mechanic)
	notifyChange()
}

const moveVariant = (
	mechanic: RaidPlanMechanicDraft,
	index: number,
	offset: -1 | 1,
): void => {
	const targetIndex = index + offset

	if (!props.canEdit || targetIndex < 0 || targetIndex >= mechanic.variants.length) {
		return
	}

	const [variant] = mechanic.variants.splice(index, 1)

	if (!variant) {
		return
	}

	mechanic.variants.splice(targetIndex, 0, variant)
	notifyChange()
}

const makeRandom = (mechanic: RaidPlanMechanicDraft): void => {
	const firstVariant = createFixedMechanic(
		t('planner.editor.timeline.random_variant_number', { number: 1 }),
		{
			key: createMechanicKey('variant'),
			duration_ms: mechanic.duration_ms,
			is_enabled: mechanic.is_enabled,
			timeline: mechanic.timeline,
			timeline_schema_version: mechanic.timeline_schema_version,
		},
	)

	mechanic.type = 'random_set'
	mechanic.duration_ms = 0
	mechanic.timeline = emptyRaidPlanTimeline()
	mechanic.variants = [firstVariant]
	selectedMechanicKey.value = firstVariant.key
	notifyChange()
}

const makeFixed = async (mechanic: RaidPlanMechanicDraft): Promise<void> => {
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

	mechanic.type = 'fixed'
	mechanic.duration_ms = 0
	mechanic.timeline = emptyRaidPlanTimeline()
	mechanic.variants = []
	selectedMechanicKey.value = mechanic.key
	notifyChange()
}

const removeMechanic = async (
	mechanic: RaidPlanMechanicDraft,
	index: number,
): Promise<void> => {
	const confirmed = await confirmationModal.open({
		title: t('planner.editor.timeline.remove_confirmation.title'),
		description: t('planner.editor.timeline.remove_confirmation.description', {
			mechanic: mechanic.name,
		}),
		severity: 'error',
		warningText: t('planner.editor.timeline.remove_confirmation.warning'),
		confirmLabel: t('planner.editor.timeline.remove'),
		confirmIcon: 'i-lucide-trash-2',
	})

	if (!confirmed) {
		return
	}

	const removedKeys = new Set([
		mechanic.key,
		...mechanic.variants.map(variant => variant.key),
	])

	mechanics.value.splice(index, 1)

	if (removedKeys.has(selectedMechanicKey.value)) {
		selectedMechanicKey.value = mechanics.value.find(item => item.type === 'fixed')?.key
			?? mechanics.value.flatMap(item => item.variants)[0]?.key
			?? ''
	}

	notifyChange()
}

const mechanicContextMenuItems = (
	mechanic: RaidPlanMechanicDraft,
	index: number,
): ContextMenuItem[][] => {
	const movementItems: ContextMenuItem[] = []

	if (index > 0) {
		movementItems.push({
			label: t('planner.editor.timeline.move_left'),
			icon: 'i-lucide-arrow-left',
			onSelect: () => moveMechanic(index, -1),
		})
	}

	if (index < mechanics.value.length - 1) {
		movementItems.push({
			label: t('planner.editor.timeline.move_right'),
			icon: 'i-lucide-arrow-right',
			onSelect: () => moveMechanic(index, 1),
		})
	}

	const typeItem: ContextMenuItem = mechanic.type === 'random_set'
		? {
			label: t('planner.editor.timeline.make_fixed'),
			icon: 'i-lucide-list-video',
			onSelect: () => makeFixed(mechanic),
		}
		: {
			label: t('planner.editor.timeline.make_random'),
			icon: 'i-lucide-shuffle',
			onSelect: () => makeRandom(mechanic),
		}

	return [
		...(movementItems.length > 0 ? [movementItems] : []),
		[typeItem],
		[{
			label: t('planner.editor.timeline.remove'),
			icon: 'i-lucide-trash-2',
			color: 'error',
			onSelect: () => removeMechanic(mechanic, index),
		}],
	]
}
</script>

<template>
	<section class="flex min-h-0 min-w-0 flex-col overflow-hidden border-t border-default bg-muted">
		<header class="flex h-10 shrink-0 items-center justify-between border-b border-default px-3">
			<div class="flex items-center gap-2">
				<UIcon name="i-lucide-list-video" class="size-4 text-primary" />
				<p class="text-xs font-semibold uppercase text-muted">{{ t('planner.editor.timeline.title') }}</p>
				<UButton
					:label="selectedMechanic?.name ?? t('planner.editor.timeline.no_mechanic')"
					:trailing-icon="timelineMode === 'timeline' ? 'i-lucide-list-tree' : 'i-lucide-x'"
					color="neutral"
					variant="subtle"
					size="xs"
					:aria-label="t('planner.editor.timeline.choose_mechanic')"
					@click="toggleMechanicMenu"
				/>
			</div>

			<div class="flex items-center gap-1">
				<UButton
					v-if="timelineMode === 'mechanics' && selectedMechanic"
					:label="t('planner.editor.timeline.back_to_timeline')"
					icon="i-lucide-arrow-left"
					color="neutral"
					variant="ghost"
					size="xs"
					@click="timelineMode = 'timeline'"
				/>

				<UDropdownMenu :items="addMechanicItems">
					<UButton
						:label="t('planner.editor.timeline.add_mechanic')"
						icon="i-lucide-plus"
						color="neutral"
						variant="ghost"
						size="xs"
						:disabled="!props.canEdit"
					/>
				</UDropdownMenu>
			</div>
		</header>

		<div
			v-if="timelineMode === 'timeline'"
			class="grid min-h-0 flex-1 grid-cols-[10rem_minmax(0,1fr)] overflow-hidden"
		>
			<div class="border-r border-default">
				<div class="h-7 border-b border-default" />
				<div
					v-for="track in tracks"
					:key="track.label"
					class="flex h-12 items-center gap-2 border-b border-default px-3 text-xs text-muted"
				>
					<UIcon :name="track.icon" class="size-4" />
					<span>{{ track.label }}</span>
				</div>
			</div>

			<div class="relative overflow-hidden">
				<div class="grid h-7 grid-cols-[repeat(13,minmax(0,1fr))] border-b border-default">
					<span
						v-for="mark in rulerMarks"
						:key="mark"
						class="border-l border-default px-1 text-[10px] text-dimmed"
					>
						{{ mark }}s
					</span>
				</div>

				<div
					v-for="track in tracks"
					:key="track.label"
					class="relative h-12 border-b border-default bg-default/40"
				>
					<div
						v-for="event in track.events"
						:key="event.label"
						class="absolute top-2 h-8 overflow-hidden border border-primary/60 bg-primary/15 px-2 py-1 text-xs text-primary"
						:style="{ left: event.left, width: event.width }"
					>
						{{ event.label }}
					</div>
				</div>

				<div class="pointer-events-none absolute inset-y-0 left-[38%] w-px bg-error">
					<span class="absolute -left-1 top-0 size-2 rotate-45 bg-error" />
				</div>
			</div>
		</div>

		<div
			v-else
			ref="mechanicViewport"
			class="relative min-h-0 min-w-0 max-w-full flex-1 touch-none overflow-hidden"
			:class="isPanningMechanics ? 'cursor-grabbing select-none' : 'cursor-grab'"
			@pointerdown="startMechanicPan"
			@pointermove="panMechanics"
			@pointerup="stopMechanicPan"
			@pointercancel="stopMechanicPan"
		>
			<div
				ref="mechanicSurface"
				class="absolute left-0 top-0 flex w-max items-start gap-3 px-3 py-2 will-change-transform"
			>
				<template v-for="(mechanic, mechanicIndex) in mechanics" :key="mechanic.key">
					<UContextMenu
						:items="mechanicContextMenuItems(mechanic, mechanicIndex)"
						:disabled="!props.canEdit"
					>
						<div
							class="w-52 shrink-0"
							:data-mechanic-key="mechanic.key"
						>
							<div
								class="border bg-elevated transition-colors"
								:class="mechanic.key === selectedMechanicKey
									? 'border-primary'
									: 'border-default hover:border-accented'"
							>
								<div class="flex items-center justify-between border-b border-default px-2 py-1">
									<div class="flex min-w-0 items-center gap-2">
										<span class="text-[10px] font-semibold text-dimmed">
											{{ String(mechanicIndex + 1).padStart(2, '0') }}
										</span>
										<UBadge
											:label="mechanic.type === 'random_set'
												? t('planner.editor.timeline.random_set')
												: t('planner.editor.timeline.fixed')"
											:color="mechanic.type === 'random_set' ? 'primary' : 'neutral'"
											variant="subtle"
											size="sm"
										/>
									</div>

									<div v-if="props.canEdit" class="flex items-center">
										<UButton
											icon="i-lucide-chevron-left"
											color="neutral"
											variant="ghost"
											size="xs"
											:disabled="mechanicIndex === 0"
											:aria-label="t('planner.editor.timeline.move_left')"
											@click="moveMechanic(mechanicIndex, -1)"
										/>
										<UButton
											icon="i-lucide-chevron-right"
											color="neutral"
											variant="ghost"
											size="xs"
											:disabled="mechanicIndex === mechanics.length - 1"
											:aria-label="t('planner.editor.timeline.move_right')"
											@click="moveMechanic(mechanicIndex, 1)"
										/>
									</div>
								</div>

								<button
									type="button"
									class="flex w-full items-center gap-2 px-3 py-2 text-left"
									:class="mechanic.type === 'fixed'
										? 'hover:bg-accented'
										: 'cursor-default'"
									:disabled="mechanic.type === 'random_set'"
									@click="selectMechanic(mechanic.key)"
								>
									<UIcon
										:name="mechanic.type === 'random_set'
											? 'i-lucide-shuffle'
											: 'i-lucide-play'"
										class="size-4 shrink-0"
										:class="mechanic.type === 'random_set' ? 'text-primary' : 'text-muted'"
									/>
									<span class="min-w-0 flex-1 truncate text-sm font-medium">
										{{ mechanic.name }}
									</span>
								</button>
							</div>

							<div
								v-if="mechanic.type === 'random_set'"
								class="ml-5 border-l border-primary/50 py-1 pl-3"
							>
								<div
									v-for="(variant, variantIndex) in mechanic.variants"
									:key="variant.key"
									:data-mechanic-key="variant.key"
									class="relative mb-1 flex items-center border bg-elevated"
									:class="variant.key === selectedMechanicKey
										? 'border-primary'
										: 'border-default hover:border-accented'"
								>
									<span class="absolute -left-3 top-1/2 w-3 border-t border-primary/50" />
									<button
										type="button"
										class="flex min-w-0 flex-1 items-center gap-2 px-2 py-1.5 text-left hover:bg-accented"
										@click="selectMechanic(variant.key)"
									>
										<UIcon name="i-lucide-git-branch" class="size-3.5 shrink-0 text-primary" />
										<span class="truncate text-xs font-medium">{{ variant.name }}</span>
									</button>

									<div v-if="props.canEdit" class="flex shrink-0 pr-1">
										<UButton
											icon="i-lucide-chevron-up"
											color="neutral"
											variant="ghost"
											size="xs"
											:disabled="variantIndex === 0"
											:aria-label="t('planner.editor.timeline.move_up')"
											@click="moveVariant(mechanic, variantIndex, -1)"
										/>
										<UButton
											icon="i-lucide-chevron-down"
											color="neutral"
											variant="ghost"
											size="xs"
											:disabled="variantIndex === mechanic.variants.length - 1"
											:aria-label="t('planner.editor.timeline.move_down')"
											@click="moveVariant(mechanic, variantIndex, 1)"
										/>
									</div>
								</div>

								<UButton
									v-if="props.canEdit"
									:label="t('planner.editor.timeline.add_variant')"
									icon="i-lucide-plus"
									color="neutral"
									variant="ghost"
									size="xs"
									class="w-full justify-start"
									@click="addVariant(mechanic)"
								/>
							</div>
						</div>
					</UContextMenu>

					<UIcon
						v-if="mechanicIndex < mechanics.length - 1"
						name="i-lucide-arrow-right"
						class="mt-8 size-4 shrink-0 text-dimmed"
					/>
				</template>
			</div>
		</div>
	</section>
</template>
