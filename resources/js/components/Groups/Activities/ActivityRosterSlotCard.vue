<script setup lang="ts">
import type { ContextMenuItem } from "@nuxt/ui";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import { getQueueApplicationDragData, isQueueApplicationDrag, setRosterSlotDragData } from "@/components/Groups/Activities/rosterDragData";
import type { QueueApplication } from "@/components/Groups/Activities/queueTypes";
import type { ActivitySlot, LocalizedText } from "@/components/Groups/Activities/rosterTypes";

const props = defineProps<{
	slot: ActivitySlot
	draggedSlotId?: number | null
	dropTargetSlotId?: number | null
	isSwapPending?: boolean
	isPendingSwap?: boolean
	canReturnToQueue?: boolean
	canMoveToBench?: boolean
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
	returnSlotToQueue: [slotId: number]
	moveSlotToBench: [slotId: number]
	markSlotMissing: [slotId: number]
	checkInSlot: [slotId: number]
	markSlotLate: [slotId: number]
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
const assignedCharacter = computed(() => props.slot.assigned_character);
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
const roleToneClass = computed(() => {
	if (props.slot.is_bench) {
		return assignedCharacter.value
			? 'border-primary/70 bg-primary/10 hover:border-primary'
			: 'border-dashed border-default bg-elevated hover:border-primary';
	}

	if (!assignedCharacter.value) {
		return 'border-dashed border-default bg-elevated hover:border-primary';
	}

	if (props.slot.attendance_status === 'checked_in') {
		return 'border-sky-400/70 bg-sky-400/10 hover:border-sky-300';
	}

	if (props.slot.attendance_status === 'late') {
		return 'border-amber-400/70 bg-amber-400/10 hover:border-amber-300';
	}

	if (roleField.value === 'tank') {
		return 'border-blue-500/70 bg-blue-500/10 hover:border-blue-400';
	}

	if (roleField.value === 'healer') {
		return 'border-emerald-500/70 bg-emerald-500/10 hover:border-emerald-400';
	}

	return 'border-red-500/70 bg-red-500/10 hover:border-red-400';
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
const statusBadge = computed(() => {
	if (!props.slot.assigned_character_id) {
		return {
			color: 'neutral' as const,
			label: t('groups.activities.management.roster.open'),
		};
	}

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
const contextMenuItems = computed<ContextMenuItem[][]>(() => [
	[
		{
			label: ['checked_in', 'late'].includes(props.slot.attendance_status ?? '')
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
			label: 'Mark as missing / absent',
			icon: 'i-lucide-user-x',
			disabled: !props.canMarkMissing || props.isSwapPending,
			onSelect: () => emit('markSlotMissing', props.slot.id),
		},
	],
	[
		{
			label: 'Move to bench',
			icon: 'i-lucide-arrow-down-to-line',
			disabled: props.slot.is_bench || !props.canMoveToBench || props.isSwapPending,
			onSelect: () => emit('moveSlotToBench', props.slot.id),
		},
		{
			label: 'Change assignments',
			icon: 'i-lucide-pencil',
			disabled: props.slot.is_bench || props.isSwapPending,
			onSelect: () => emit('clickSlot', props.slot.id),
		},
		{
			label: 'Return to queue',
			icon: 'i-lucide-undo-2',
			disabled: !props.canReturnToQueue || !props.slot.can_return_to_queue || props.isSwapPending,
			onSelect: () => emit('returnSlotToQueue', props.slot.id),
		},
	],
]);

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
			class="relative min-h-28 border px-4 py-4 transition duration-200 ease-out hover:shadow-lg"
			:class="[
				roleToneClass,
				canDrag ? 'cursor-grab hover:scale-[1.02]' : 'cursor-pointer',
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
							{{ slotLabel }}
						</p>
						<p v-if="!assignedCharacter" class="font-medium text-toned">
							{{ t('groups.activities.management.roster.empty_slot') }}
						</p>
					</div>

					<UBadge
						:color="statusBadge.color"
						variant="subtle"
						:label="statusBadge.label"
					/>
				</div>

				<div v-if="assignedCharacter" class="space-y-3">
					<div class="flex items-start justify-between gap-3">
						<UUser
							:name="assignedCharacter.name"
							:description="assignedCharacter.world || undefined"
							:avatar="assignedCharacter.avatar_url ? { src: assignedCharacter.avatar_url, loading: 'lazy' } : undefined"
							size="lg"
						/>

						<div class="flex items-center">
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
							<span class="text-muted">
								{{ field.label }}
							</span>
							<span class="text-right font-medium text-toned">
								{{ field.value }}
							</span>
						</div>
					</div>
				</div>

				<div v-else class="mt-auto text-sm text-muted">
					{{ t('groups.activities.management.roster.open') }}
				</div>
			</div>
		</div>
	</UContextMenu>

	<div
		v-else
		ref="slotCardElement"
		class="relative min-h-28 border px-4 py-4 transition duration-200 ease-out hover:shadow-lg"
		:class="[
			roleToneClass,
			canDrag ? 'cursor-grab hover:scale-[1.02]' : 'cursor-pointer',
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
						{{ slotLabel }}
					</p>
					<p v-if="!assignedCharacter" class="font-medium text-toned">
						{{ t('groups.activities.management.roster.empty_slot') }}
					</p>
				</div>

				<UBadge
					:color="statusBadge.color"
					variant="subtle"
					:label="statusBadge.label"
				/>
			</div>

			<div v-if="assignedCharacter" class="space-y-3">
				<div class="flex items-start justify-between gap-3">
					<UUser
						:name="assignedCharacter.name"
						:description="assignedCharacter.world || undefined"
						:avatar="assignedCharacter.avatar_url ? { src: assignedCharacter.avatar_url, loading: 'lazy' } : undefined"
						size="lg"
					/>

					<div class="flex items-center">
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
						<span class="text-muted">
							{{ field.label }}
						</span>
						<span class="text-right font-medium text-toned">
							{{ field.value }}
						</span>
					</div>
				</div>
			</div>

			<div v-else class="mt-auto text-sm text-muted">
				{{ t('groups.activities.management.roster.open') }}
			</div>
		</div>
	</div>
</template>
