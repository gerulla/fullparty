<script setup lang="ts">
import type { ContextMenuItem } from "@nuxt/ui";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import { getQueueApplicationDragData, isQueueApplicationDrag, setRosterSlotDragData } from "@/components/Groups/Activities/rosterDragData";
import ActivitySlotCompositionHintContextMenu from "@/components/Groups/Activities/ActivitySlotCompositionHintContextMenu.vue";
import ActivitySlotCompositionHintBadge from "@/components/Groups/Activities/ActivitySlotCompositionHintBadge.vue";
import ActivitySlotApplicationMatches from "@/components/Groups/Activities/ActivitySlotApplicationMatches.vue";
import type { LocalizedText } from "@/Types/Common";
import type { QueueApplication } from "@/Types/ActivityQueue";
import type { ActivitySlot, ActivitySlotCompositionHintInput } from "@/Types/ActivityRoster";
import { emptyCompositionSlotToneClass } from "@/utils/activityCompositionHints";

type SlotMarker = {
	key: string
	label: string
	icon: string
	wrapperClass: string
	iconClass: string
	rotationClass: string
}

const props = defineProps<{
	slot: ActivitySlot
	draggedSlotId?: number | null
	dropTargetSlotId?: number | null
	isSwapPending?: boolean
	isPendingSwap?: boolean
	cutSlotId?: number | null
	cutSlotIsBench?: boolean | null
	canReturnToQueue?: boolean
	canMoveToBench?: boolean
	canMoveToFillIn?: boolean
	canMarkMissing?: boolean
	canCheckIn?: boolean
}>();

const emit = defineEmits<{
	dragStart: [slotId: number]
	dragEnd: []
	dragEnter: [slotId: number]
	dragLeave: [slotId: number]
	dropSlot: [slotId: number]
	dropApplication: [payload: { slotId: number, application: QueueApplication }]
	clickSlot: [slotId: number]
	viewApplication: [slotId: number]
	returnSlotToQueue: [slotId: number]
	moveSlotToBench: [slotId: number]
	moveSlotToFillIn: [slotId: number]
	markSlotMissing: [slotId: number]
	checkInSlot: [slotId: number]
	markSlotLate: [slotId: number]
	markSlotHost: [slotId: number]
	markSlotRaidLeader: [slotId: number]
	cutSlot: [slotId: number]
	pasteCutSlot: [slotId: number]
	clearCutSlot: []
	replaceCompositionHints: [payload: { slotId: number, compositionHints: ActivitySlotCompositionHintInput[] }]
	customizeCompositionHints: [slot: ActivitySlot]
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));
const slotCardElement = ref<HTMLElement | null>(null);
let dragPreviewElement: HTMLElement | null = null;

const localizedText = (value: LocalizedText, fallback: string) => (
	localizedValue(value, locale.value, fallbackLocale.value) || fallback
);

const slotLabel = computed(() => localizedText(props.slot.slot_label, props.slot.slot_key));
const compactSlotLabel = computed(() => {
	const parts = slotLabel.value.trim().split(/\s+/);

	return parts.length >= 3 && /^\d+$/.test(parts[parts.length - 1] ?? '')
		? parts.slice(1).join(' ')
		: slotLabel.value;
});
const assignedCharacter = computed(() => props.slot.assigned_character);
const viewerUserId = computed<number | null>(() => {
	const userId = page.props.auth?.user?.id;

	return typeof userId === 'number' ? userId : null;
});
const isViewerAssignedCharacter = computed(() => (
	assignedCharacter.value !== null
	&& viewerUserId.value !== null
	&& assignedCharacter.value.user_id === viewerUserId.value
));
const isLate = computed(() => props.slot.attendance_status === 'late');
const isCheckedIn = computed(() => (
	props.slot.attendance_status === 'checked_in'
	|| props.slot.checked_in_at !== null
));
const classField = computed(() => props.slot.field_values.find((field) => field.source === 'character_classes') ?? null);
const phantomField = computed(() => props.slot.field_values.find((field) => field.source === 'phantom_jobs') ?? null);
const roleField = computed(() => classField.value?.display_meta?.role ?? null);
const fieldEntries = computed(() => props.slot.field_values.map((field) => ({
	id: field.id,
	label: localizedText(field.field_label, field.field_key),
	value: typeof field.display_value === 'string'
		? field.display_value
		: localizedText(field.display_value, ''),
	source: field.source,
})));
const visibleFieldEntries = computed(() => (
	props.slot.assigned_character_id
		? fieldEntries.value.filter((field) => field.value && field.source !== 'character_classes' && field.source !== 'phantom_jobs')
		: []
));
const designationMarkers = computed(() => {
	const markers: SlotMarker[] = [];

	if (props.slot.is_raid_leader) {
		markers.push({
			key: 'raid-leader',
			label: t('groups.activities.management.roster.raid_leader_badge'),
			icon: 'i-lucide-crown',
			wrapperClass: '-left-2 -top-2 bg-amber-400 text-amber-950 ring-amber-200/80',
			iconClass: 'text-amber-400 drop-shadow-[0_4px_10px_rgba(251,191,36,0.85)]',
			rotationClass: '-rotate-35',
		});
	} else if (props.slot.is_host) {
		markers.push({
			key: 'host',
			label: t('groups.activities.management.roster.host_badge'),
			icon: 'i-lucide-swords',
			wrapperClass: '-left-2 -top-2 bg-sky-500 text-sky-50 ring-sky-300/70',
			iconClass: 'text-sky-500 drop-shadow-[0_4px_10px_rgba(14,165,233,0.85)]',
			rotationClass: '-rotate-35',
		});
	}

	if (isViewerAssignedCharacter.value) {
		markers.push({
			key: 'self',
			label: t('groups.activities.management.roster.self_badge'),
			icon: 'i-mingcute-badge-line',
			wrapperClass: '-right-2 -top-2 bg-primary text-inverted ring-primary/60',
			iconClass: 'text-primary drop-shadow-[0_4px_10px_rgba(168,85,247,0.85)]',
			rotationClass: 'rotate-35',
		});
	}

	return markers;
});
const emptyHintToneClass = computed(() => (
	!props.slot.is_bench && !props.slot.is_fill_in && !assignedCharacter.value ? emptyCompositionSlotToneClass(props.slot) : null
));
const roleToneClass = computed(() => {
	if (props.slot.is_bench) {
		return assignedCharacter.value
			? 'border-primary/70 bg-primary/10 hover:border-primary'
			: 'border-dashed border-default bg-elevated hover:border-primary';
	}

	if (!assignedCharacter.value) {
		return emptyHintToneClass.value ?? 'border-dashed border-default bg-elevated hover:border-primary';
	}

	if (roleField.value === 'tank') {
		return 'border-blue-500/70 bg-blue-500/10 hover:border-blue-400';
	}

	if (roleField.value === 'healer') {
		return 'border-emerald-500/70 bg-emerald-500/10 hover:border-emerald-400';
	}

	return 'border-red-500/70 bg-red-500/10 hover:border-red-400';
});
const assignedCharacterNameClass = computed(() => {
	if (isViewerAssignedCharacter.value) {
		return 'text-primary';
	}

	if (props.slot.is_raid_leader) {
		return 'text-amber-300';
	}

	if (props.slot.is_host) {
		return 'text-sky-300';
	}

	if (props.slot.attendance_status === 'checked_in') {
		return '';
	}

	if (props.slot.attendance_status === 'late') {
		return 'text-orange-300';
	}

	return '';
});
const classIconUrl = computed(() => classField.value?.display_meta?.flaticon_url || classField.value?.display_meta?.icon_url || null);
const phantomIconUrl = computed(() => phantomField.value?.display_meta?.transparent_icon_url || phantomField.value?.display_meta?.icon_url || phantomField.value?.display_meta?.sprite_url || null);
const classDisplayValue = computed(() => classField.value
	? (typeof classField.value.display_value === 'string' ? classField.value.display_value : localizedText(classField.value.display_value, ''))
	: null);
const phantomDisplayValue = computed(() => phantomField.value
	? (typeof phantomField.value.display_value === 'string' ? phantomField.value.display_value : localizedText(phantomField.value.display_value, ''))
	: null);
const canDrag = computed(() => Boolean(props.slot.assigned_character_id) && !props.isSwapPending);
const canShowContextMenu = computed(() => Boolean(props.slot.assigned_character_id));
const isDraggedSource = computed(() => props.draggedSlotId === props.slot.id);
const isDropTarget = computed(() => props.dropTargetSlotId === props.slot.id && props.draggedSlotId !== props.slot.id);
const isCutSource = computed(() => props.cutSlotId === props.slot.id && Boolean(props.slot.assigned_character_id));
const canPasteCutSlot = computed(() => (
	props.cutSlotId !== null
	&& props.cutSlotId !== undefined
	&& props.cutSlotId !== props.slot.id
	&& props.cutSlotIsBench !== null
	&& props.cutSlotIsBench !== undefined
	&& (!props.slot.assigned_character_id || props.cutSlotIsBench === props.slot.is_bench)
	&& !props.isSwapPending
));
const statusBadge = computed(() => {
	if (props.slot.attendance_status === 'checked_in') {
		return {
			color: 'info' as const,
			label: t('groups.activities.management.roster.checked_in'),
		};
	}

	if (props.slot.attendance_status === 'late') {
		return {
			color: 'warning' as const,
			label: t('groups.activities.management.roster.late'),
		};
	}

	return {
		color: 'success' as const,
		label: t('groups.activities.management.roster.assigned'),
	};
});
const cutContextMenuItems = computed<ContextMenuItem[]>(() => {
	if (!props.slot.assigned_character_id) {
		return [];
	}

	if (isCutSource.value) {
		return [
			{
				label: t('groups.activities.management.roster.cancel_cut_assignment_action'),
				icon: 'i-lucide-x',
				disabled: props.isSwapPending,
				onSelect: () => emit('clearCutSlot'),
			},
		];
	}

	return [
		{
			label: t('groups.activities.management.roster.cut_assignment_action'),
			icon: 'i-lucide-scissors',
			disabled: props.isSwapPending,
			onSelect: () => emit('cutSlot', props.slot.id),
		},
	];
});
const emptySlotContextMenuItems = computed<ContextMenuItem[][]>(() => (
	canPasteCutSlot.value
		? [
			[
				{
					label: t('groups.activities.management.roster.paste_assignment_action'),
					icon: 'i-lucide-clipboard-paste',
					onSelect: () => emit('pasteCutSlot', props.slot.id),
				},
			],
		]
		: []
));
const contextMenuItems = computed<ContextMenuItem[][]>(() => [
	[
		...(props.slot.assignment_application_id !== null
			? [{
				label: t('groups.activities.management.roster.view_application_action'),
				icon: 'i-lucide-file-user',
				disabled: props.isSwapPending,
				onSelect: () => emit('viewApplication', props.slot.id),
			}]
			: []),
	],
	[
		{
			label: props.slot.attendance_status === 'late'
				? t('groups.activities.management.roster.unmark_late_action')
				: props.slot.attendance_status === 'checked_in'
					? t('groups.activities.management.roster.undo_check_in')
					: t('groups.activities.management.roster.check_in_action'),
			icon: 'i-lucide-user-check',
			disabled: props.slot.is_bench || !props.canCheckIn || props.isSwapPending,
			onSelect: () => emit('checkInSlot', props.slot.id),
		},
		{
			label: t('groups.activities.management.roster.mark_late_action'),
			icon: 'i-lucide-clock-alert',
			disabled: props.slot.is_bench || !props.canCheckIn || props.isSwapPending || props.slot.attendance_status === 'late',
			onSelect: () => emit('markSlotLate', props.slot.id),
		},
		{
			label: t('groups.activities.management.roster.mark_missing_action'),
			icon: 'i-lucide-user-x',
			disabled: !props.canMarkMissing || props.isSwapPending,
			onSelect: () => emit('markSlotMissing', props.slot.id),
		},
	],
	[
		{
			label: props.slot.is_host
				? t('groups.activities.management.roster.unmark_host_action')
				: t('groups.activities.management.roster.mark_host_action'),
			icon: 'i-lucide-badge-check',
			color: 'info',
			disabled: props.slot.is_bench || props.slot.is_fill_in || props.isSwapPending,
			onSelect: () => emit('markSlotHost', props.slot.id),
		},
		{
			label: props.slot.is_raid_leader
				? t('groups.activities.management.roster.unmark_raid_leader_action')
				: t('groups.activities.management.roster.mark_raid_leader_action'),
			icon: 'i-lucide-crown',
			color: 'warning',
			disabled: props.slot.is_bench || props.slot.is_fill_in || props.isSwapPending,
			onSelect: () => emit('markSlotRaidLeader', props.slot.id),
		},
	],
	[
		...(props.slot.is_bench
			? [
				{
					label: t('groups.activities.management.roster.move_to_fill_in_action'),
					icon: 'i-lucide-user-plus',
					disabled: !props.canMoveToFillIn || props.isSwapPending,
					onSelect: () => emit('moveSlotToFillIn', props.slot.id),
				},
			]
			: []),
		{
			label: t('groups.activities.management.roster.move_to_bench_action'),
			icon: 'i-lucide-arrow-down-to-line',
			disabled: props.slot.is_bench || !props.canMoveToBench || props.isSwapPending,
			onSelect: () => emit('moveSlotToBench', props.slot.id),
		},
		{
			label: t('groups.activities.management.roster.change_assignment_action'),
			icon: 'i-lucide-pencil',
			disabled: props.slot.is_bench || props.isSwapPending,
			onSelect: () => emit('clickSlot', props.slot.id),
		},
		{
			label: props.slot.assignment_source === 'manual'
				? t('groups.activities.management.roster.remove_from_slot_action')
				: t('groups.activities.management.roster.return_to_queue_action'),
			icon: props.slot.assignment_source === 'manual' ? 'i-lucide-user-minus' : 'i-lucide-undo-2',
			color: 'error',
			disabled: props.slot.assignment_source === 'manual'
				? props.isSwapPending
				: !props.canReturnToQueue || !props.slot.can_return_to_queue || props.isSwapPending,
			onSelect: () => emit('returnSlotToQueue', props.slot.id),
		},
	],
	cutContextMenuItems.value,
	...(canPasteCutSlot.value
		? [
			[
				{
					label: t('groups.activities.management.roster.paste_assignment_action'),
					icon: 'i-lucide-clipboard-paste',
					onSelect: () => emit('pasteCutSlot', props.slot.id),
				},
			],
		]
		: []),
].filter((group) => group.length > 0));

const removeDragPreview = () => {
	if (!dragPreviewElement) {
		return;
	}

	dragPreviewElement.remove();
	dragPreviewElement = null;
};

const createDragPreview = () => {
	if (!slotCardElement.value) {
		return null;
	}

	removeDragPreview();

	const preview = slotCardElement.value.cloneNode(true) as HTMLElement;
	const rect = slotCardElement.value.getBoundingClientRect();

	preview.style.position = 'fixed';
	preview.style.top = '-10000px';
	preview.style.left = '-10000px';
	preview.style.width = `${rect.width}px`;
	preview.style.pointerEvents = 'none';
	preview.style.opacity = '1';
	preview.style.transform = 'rotate(1.5deg)';
	preview.style.boxShadow = '0 20px 45px rgba(15, 23, 42, 0.28)';
	preview.style.zIndex = '9999';

	document.body.appendChild(preview);
	dragPreviewElement = preview;

	return {
		element: preview,
		offsetX: Math.min(rect.width / 2, 120),
		offsetY: Math.min(rect.height / 2, 60),
	};
};

const handleDragStart = (event: DragEvent) => {
	if (!canDrag.value) {
		event.preventDefault();
		return;
	}

	event.dataTransfer?.setData('text/plain', String(props.slot.id));
	setRosterSlotDragData(event, props.slot);
	const preview = createDragPreview();

	if (preview) {
		event.dataTransfer?.setDragImage?.(preview.element, preview.offsetX, preview.offsetY);
	}

	if (event.dataTransfer) {
		event.dataTransfer.effectAllowed = 'move';
	}

	emit('dragStart', props.slot.id);
};

const handleDragOver = (event: DragEvent) => {
	if (props.isSwapPending) {
		return;
	}

	if (isQueueApplicationDrag(event)) {
		event.preventDefault();

		if (event.dataTransfer) {
			event.dataTransfer.dropEffect = 'copy';
		}

		emit('dragEnter', props.slot.id);
		return;
	}

	if (props.draggedSlotId === null || props.draggedSlotId === undefined) {
		return;
	}

	event.preventDefault();

	if (event.dataTransfer) {
		event.dataTransfer.dropEffect = 'move';
	}

	emit('dragEnter', props.slot.id);
};

const handleDrop = (event: DragEvent) => {
	if (props.isSwapPending) {
		return;
	}

	const droppedApplication = getQueueApplicationDragData(event);

	if (droppedApplication) {
		event.preventDefault();
		emit('dropApplication', {
			slotId: props.slot.id,
			application: droppedApplication,
		});
		return;
	}

	if (props.draggedSlotId === null || props.draggedSlotId === undefined) {
		return;
	}

	event.preventDefault();
	emit('dropSlot', props.slot.id);
};

const handleDragEnd = () => {
	removeDragPreview();
	emit('dragEnd');
};

const handleClick = () => {
	if (props.isSwapPending) {
		return;
	}

	emit('clickSlot', props.slot.id);
};
</script>

<template>
	<UContextMenu
		v-if="canShowContextMenu"
		:items="contextMenuItems"
	>
		<div
			ref="slotCardElement"
			class="roster-slot-card relative min-h-24 border px-4 py-4 transition duration-200 ease-out hover:shadow-lg"
			:class="[
				roleToneClass,
				canDrag ? 'cursor-grab hover:scale-[1.02]' : 'cursor-pointer',
				isCutSource ? 'ring-2 ring-primary/70 ring-offset-2 ring-offset-background' : '',
				isDraggedSource ? 'scale-[0.98] opacity-35 saturate-75' : '',
				isDropTarget ? 'border-white shadow-[0_0_0_2px_rgba(255,255,255,0.95),0_0_0_6px_rgba(255,255,255,0.22)]' : '',
				props.isPendingSwap ? 'overflow-hidden' : '',
			]"
			:draggable="canDrag"
			@dragstart="handleDragStart"
			@dragend="handleDragEnd"
			@dragenter.prevent="emit('dragEnter', slot.id)"
			@dragleave.prevent="emit('dragLeave', slot.id)"
			@dragover="handleDragOver"
			@drop="handleDrop"
		@click="handleClick"
	>
			<div
				v-for="marker in designationMarkers"
				:key="marker.key"
				class="pointer-events-none absolute z-20 flex h-8 w-8 items-center justify-center shadow-lg bg-transparent"
				:class="marker.wrapperClass"
				:aria-label="marker.label"
				:title="marker.label"
			>
				<UIcon
					:name="marker.icon"
					class="h-8 w-8"
					:class="[marker.iconClass, marker.rotationClass]"
				/>
			</div>

			<div
				v-if="isPendingSwap"
				class="absolute inset-0 z-10 flex flex-col gap-3 border border-white/10 bg-elevated/95 px-4 py-4 shadow-[inset_0_1px_0_rgba(255,255,255,0.08)] backdrop-blur-[1px]"
			>
				<div class="flex items-start justify-between gap-3">
					<div class="flex flex-col gap-2">
						<USkeleton class="h-4 w-20 bg-muted/70" />
						<USkeleton class="h-5 w-28 bg-muted/70" />
					</div>
					<USkeleton class="h-5 w-16 bg-muted/70" />
				</div>

				<div class="flex items-start justify-between gap-3">
					<div class="flex items-center gap-3">
						<USkeleton class="h-10 w-10 rounded-full bg-muted/70" />
						<div class="flex flex-col gap-2">
							<USkeleton class="h-4 w-28 bg-muted/70" />
							<USkeleton class="h-4 w-16 bg-muted/70" />
						</div>
					</div>

					<div class="flex items-center gap-2">
						<USkeleton class="h-10 w-10 rounded-sm bg-muted/70" />
						<USkeleton class="h-10 w-10 rounded-sm bg-muted/70" />
					</div>
				</div>

				<div class="mt-auto flex flex-col gap-2">
					<USkeleton class="h-4 w-full bg-muted/70" />
					<USkeleton class="h-4 w-3/4 self-end bg-muted/70" />
				</div>
			</div>

			<div class="flex h-full flex-col gap-3">
				<div class="flex items-start justify-between gap-3">
					<div class="flex flex-col gap-1">
						<p class="text-xs uppercase tracking-wide text-primary">
							<span class="roster-slot-card-label-full">{{ slotLabel }}</span>
							<span class="roster-slot-card-label-compact hidden">{{ compactSlotLabel }}</span>
							<ActivitySlotApplicationMatches :matches="slot.application_matches" />
						</p>
						<p v-if="!assignedCharacter" class="font-medium text-toned">
							{{ t('groups.activities.management.roster.empty_slot') }}
						</p>
					</div>

					<UBadge
						v-if="assignedCharacter"
						:color="statusBadge.color"
						variant="subtle"
						:label="statusBadge.label"
					/>
					<ActivitySlotCompositionHintBadge
						v-else-if="!slot.is_bench && !slot.is_fill_in"
						:slot="slot"
					/>
				</div>

				<div v-if="assignedCharacter" class="space-y-3">
					<div class="flex items-start justify-between gap-3">
						<UUser
							:name="assignedCharacter.name"
							:description="assignedCharacter.world || undefined"
							:avatar="assignedCharacter.avatar_url ? { src: assignedCharacter.avatar_url, loading: 'lazy' } : undefined"
							size="lg"
							:ui="{ avatar: 'roster-slot-card-avatar', name: assignedCharacterNameClass }"
						>
							<template #name>
								<span class="inline-flex min-w-0 items-center gap-1.5">
									<span class="truncate">{{ assignedCharacter.name }}</span>
									<UIcon
										v-if="isLate"
										name="i-mdi-clock-outline"
										class="size-3.5 shrink-0 text-current opacity-80"
									/>
									<UIcon
										v-if="isCheckedIn"
										name="i-mdi-check-bold"
										class="size-3.5 shrink-0 text-current opacity-80"
									/>
								</span>
							</template>
						</UUser>

						<div class="roster-slot-card-primary-icons flex items-center">
							<img
								v-if="classIconUrl"
								:src="classIconUrl"
								:alt="classDisplayValue || ''"
								class="h-10 w-10 rounded-sm p-1 object-contain"
							>
							<img
								v-if="phantomIconUrl"
								:src="phantomIconUrl"
								:alt="phantomDisplayValue || ''"
								class="h-10 w-10 rounded-sm  p-1 object-contain"
							>
						</div>
					</div>

					<div v-if="visibleFieldEntries.length > 0" class="space-y-2">
						<div
							v-for="field in visibleFieldEntries"
							:key="field.id"
							class="flex items-start justify-between gap-3 text-sm"
						>
							<span class="roster-slot-card-field-label text-muted">
								{{ field.label }}
							</span>
							<span class="roster-slot-card-field-value text-right font-medium text-toned">
								{{ field.value }}
							</span>
						</div>
					</div>

					<div
						v-if="classIconUrl || phantomIconUrl"
						class="roster-slot-card-compact-icons hidden items-center justify-center gap-1"
					>
						<img
							v-if="classIconUrl"
							:src="classIconUrl"
							:alt="classDisplayValue || ''"
							class="h-10 w-10 rounded-sm p-1 object-contain"
						>
						<img
							v-if="phantomIconUrl"
							:src="phantomIconUrl"
							:alt="phantomDisplayValue || ''"
							class="h-10 w-10 rounded-sm p-1 object-contain"
						>
					</div>
				</div>

			</div>
		</div>
	</UContextMenu>

	<ActivitySlotCompositionHintContextMenu
		v-else
		:slot="slot"
		:disabled="isSwapPending"
		:extra-items="emptySlotContextMenuItems"
		@replace-hints="emit('replaceCompositionHints', $event)"
		@customize="emit('customizeCompositionHints', $event)"
	>
		<div
			ref="slotCardElement"
			class="roster-slot-card relative min-h-24 border px-4 py-4 transition duration-200 ease-out hover:shadow-lg"
			:class="[
				roleToneClass,
				canDrag ? 'cursor-grab hover:scale-[1.02]' : 'cursor-pointer',
				isCutSource ? 'ring-2 ring-primary/70 ring-offset-2 ring-offset-background' : '',
				isDraggedSource ? 'scale-[0.98] opacity-35 saturate-75' : '',
				isDropTarget ? 'border-white shadow-[0_0_0_2px_rgba(255,255,255,0.95),0_0_0_6px_rgba(255,255,255,0.22)]' : '',
				props.isPendingSwap ? 'overflow-hidden' : '',
			]"
			:draggable="canDrag"
			@dragstart="handleDragStart"
			@dragend="handleDragEnd"
			@dragenter.prevent="emit('dragEnter', slot.id)"
			@dragleave.prevent="emit('dragLeave', slot.id)"
			@dragover="handleDragOver"
			@drop="handleDrop"
			@click="handleClick"
		>
			<div
				v-for="marker in designationMarkers"
				:key="marker.key"
				class="pointer-events-none absolute z-20 flex h-8 w-8 items-center justify-center shadow-lg bg-transparent"
				:class="marker.wrapperClass"
				:aria-label="marker.label"
				:title="marker.label"
			>
				<UIcon
					:name="marker.icon"
					class="h-8 w-8"
					:class="[marker.iconClass, marker.rotationClass]"
				/>
			</div>

			<div
				v-if="isPendingSwap"
				class="absolute inset-0 z-10 flex flex-col gap-3 border border-white/10 bg-elevated/95 px-4 py-4 shadow-[inset_0_1px_0_rgba(255,255,255,0.08)] backdrop-blur-[1px]"
			>
				<div class="flex items-start justify-between gap-3">
					<div class="flex flex-col gap-2">
						<USkeleton class="h-4 w-20 bg-muted/70" />
						<USkeleton class="h-5 w-28 bg-muted/70" />
					</div>
					<USkeleton class="h-5 w-16 bg-muted/70" />
				</div>

				<div class="flex items-start justify-between gap-3">
					<div class="flex items-center gap-3">
						<USkeleton class="h-10 w-10 rounded-full bg-muted/70" />
						<div class="flex flex-col gap-2">
							<USkeleton class="h-4 w-28 bg-muted/70" />
							<USkeleton class="h-4 w-16 bg-muted/70" />
						</div>
					</div>

					<div class="flex items-center gap-2">
						<USkeleton class="h-10 w-10 rounded-sm bg-muted/70" />
						<USkeleton class="h-10 w-10 rounded-sm bg-muted/70" />
					</div>
				</div>

				<div class="mt-auto flex flex-col gap-2">
					<USkeleton class="h-4 w-full bg-muted/70" />
					<USkeleton class="h-4 w-3/4 self-end bg-muted/70" />
				</div>
			</div>

			<div class="flex h-full flex-col gap-3">
				<div class="flex items-start justify-between gap-3">
					<div class="flex flex-col gap-1">
						<p class="text-xs uppercase tracking-wide text-primary">
							<span class="roster-slot-card-label-full">{{ slotLabel }}</span>
							<span class="roster-slot-card-label-compact hidden">{{ compactSlotLabel }}</span>
							<ActivitySlotApplicationMatches :matches="slot.application_matches" />
						</p>
						<p v-if="!assignedCharacter" class="font-medium text-toned">
							{{ t('groups.activities.management.roster.empty_slot') }}
						</p>
					</div>

					<UBadge
						v-if="assignedCharacter"
						:color="statusBadge.color"
						variant="subtle"
						:label="statusBadge.label"
					/>
					<ActivitySlotCompositionHintBadge
						v-else-if="!slot.is_bench && !slot.is_fill_in"
						:slot="slot"
					/>
				</div>

				<div v-if="assignedCharacter" class="space-y-3">
					<div class="flex items-start justify-between gap-3">
						<UUser
							:name="assignedCharacter.name"
							:description="assignedCharacter.world || undefined"
							:avatar="assignedCharacter.avatar_url ? { src: assignedCharacter.avatar_url, loading: 'lazy' } : undefined"
							size="lg"
							:ui="{ avatar: 'roster-slot-card-avatar', name: assignedCharacterNameClass }"
						>
							<template #name>
								<span class="inline-flex min-w-0 items-center gap-1.5">
									<span class="truncate">{{ assignedCharacter.name }}</span>
									<UIcon
										v-if="isLate"
										name="i-mdi-clock-outline"
										class="size-3.5 shrink-0 text-current opacity-80"
									/>
									<UIcon
										v-if="isCheckedIn"
										name="i-mdi-check-bold"
										class="size-3.5 shrink-0 text-current opacity-80"
									/>
								</span>
							</template>
						</UUser>

						<div class="roster-slot-card-primary-icons flex items-center">
							<img
								v-if="classIconUrl"
								:src="classIconUrl"
								:alt="classDisplayValue || ''"
								class="h-10 w-10 rounded-sm p-1 object-contain"
							>
							<img
								v-if="phantomIconUrl"
								:src="phantomIconUrl"
								:alt="phantomDisplayValue || ''"
								class="h-10 w-10 rounded-sm  p-1 object-contain"
							>
						</div>
					</div>

					<div v-if="visibleFieldEntries.length > 0" class="space-y-2">
						<div
							v-for="field in visibleFieldEntries"
							:key="field.id"
							class="flex items-start justify-between gap-3 text-sm"
						>
							<span class="roster-slot-card-field-label text-muted">
								{{ field.label }}
							</span>
							<span class="roster-slot-card-field-value text-right font-medium text-toned">
								{{ field.value }}
							</span>
						</div>
					</div>

					<div
						v-if="classIconUrl || phantomIconUrl"
						class="roster-slot-card-compact-icons hidden items-center justify-center gap-1"
					>
						<img
							v-if="classIconUrl"
							:src="classIconUrl"
							:alt="classDisplayValue || ''"
							class="h-10 w-10 rounded-sm p-1 object-contain"
						>
						<img
							v-if="phantomIconUrl"
							:src="phantomIconUrl"
							:alt="phantomDisplayValue || ''"
							class="h-10 w-10 rounded-sm p-1 object-contain"
						>
					</div>
				</div>
			</div>
		</div>
	</ActivitySlotCompositionHintContextMenu>
</template>

<style scoped>
.roster-slot-card {
	container-type: inline-size;
}

@container (max-width: 18rem) {
	:deep(.roster-slot-card-avatar),
	.roster-slot-card-field-label {
		display: none;
	}

	.roster-slot-card-field-value {
		text-align: left;
	}
}

@container (max-width: 12rem) {
	.roster-slot-card-label-full {
		display: none;
	}

	.roster-slot-card-label-compact {
		display: inline;
	}

	.roster-slot-card-primary-icons {
		display: none;
	}

	.roster-slot-card-compact-icons {
		display: flex;
	}
}
</style>
