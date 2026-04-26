<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import { getQueueApplicationDragData, isQueueApplicationDrag } from "@/components/Groups/Activities/rosterDragData";
import type { QueueApplication } from "@/components/Groups/Activities/queueTypes";
import type { ActivitySlot, LocalizedText } from "@/components/Groups/Activities/rosterTypes";

const props = defineProps<{
	slots: ActivitySlot[]
	draggedSlotId?: number | null
	dropTargetSlotId?: number | null
	isSwapPending?: boolean
	pendingSwapSlotIds?: number[]
}>();

const emit = defineEmits<{
	dragStart: [slotId: number]
	dragEnd: []
	dragEnter: [slotId: number]
	dragLeave: [slotId: number]
	dropSlot: [slotId: number]
	dropApplication: [payload: { slotId: number, application: QueueApplication }]
	clickSlot: [slotId: number]
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));

const localizedText = (value: LocalizedText, fallback: string) => (
	localizedValue(value, locale.value, fallbackLocale.value) || fallback
);

const handleDragStart = (event: DragEvent, slot: ActivitySlot) => {
	if (!slot.assigned_character_id || props.isSwapPending) {
		event.preventDefault();
		return;
	}

	event.dataTransfer?.setData('text/plain', String(slot.id));
	if (event.dataTransfer) {
		event.dataTransfer.effectAllowed = 'move';
	}

	emit('dragStart', slot.id);
};

const handleDragOver = (event: DragEvent, slotId: number) => {
	if (props.isSwapPending) {
		return;
	}

	if (isQueueApplicationDrag(event)) {
		event.preventDefault();

		if (event.dataTransfer) {
			event.dataTransfer.dropEffect = 'copy';
		}

		emit('dragEnter', slotId);
		return;
	}

	if (props.draggedSlotId === null || props.draggedSlotId === undefined) {
		return;
	}

	event.preventDefault();

	if (event.dataTransfer) {
		event.dataTransfer.dropEffect = 'move';
	}

	emit('dragEnter', slotId);
};

const handleDrop = (event: DragEvent, slotId: number) => {
	if (props.isSwapPending) {
		return;
	}

	const droppedApplication = getQueueApplicationDragData(event);

	if (droppedApplication) {
		event.preventDefault();
		emit('dropApplication', {
			slotId,
			application: droppedApplication,
		});
		return;
	}

	event.preventDefault();
	emit('dropSlot', slotId);
};

const handleRowClick = (slot: ActivitySlot) => {
	if (!slot.assigned_character_id || props.isSwapPending) {
		return;
	}

	emit('clickSlot', slot.id);
};

const rows = computed(() => [...props.slots]
	.sort((left, right) => left.sort_order - right.sort_order)
	.map((slot) => ({
		id: slot.id,
		slot,
		groupLabel: localizedText(slot.group_label, slot.group_key),
		slotLabel: localizedText(slot.slot_label, slot.slot_key),
		statusLabel: slot.assigned_character_id
			? t('groups.activities.management.roster.assigned')
			: t('groups.activities.management.roster.open'),
		statusColor: slot.assigned_character_id ? 'success' : 'neutral',
		fields: slot.assigned_character_id
			? slot.field_values.map((field) => localizedText(field.field_label, field.field_key))
			: [],
	})));
</script>

<template>
	<section class="border border-default bg-muted shadow-sm dark:bg-elevated/50">
		<div class="overflow-x-auto">
			<table class="min-w-full divide-y divide-default">
				<thead>
					<tr class="text-left text-xs uppercase tracking-wide text-muted">
						<th class="px-5 py-4 font-medium">{{ t('groups.activities.management.roster.list_headers.party') }}</th>
						<th class="px-5 py-4 font-medium">{{ t('groups.activities.management.roster.list_headers.slot') }}</th>
						<th class="px-5 py-4 font-medium">{{ t('groups.activities.management.roster.list_headers.details') }}</th>
						<th class="px-5 py-4 font-medium">{{ t('groups.activities.management.roster.list_headers.status') }}</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-default">
					<tr
						v-for="row in rows"
						:key="row.id"
						class="transition-colors duration-200 hover:bg-background/70"
						:class="[
							row.slot.assigned_character_id ? 'cursor-grab' : '',
							draggedSlotId === row.id ? 'bg-primary/10 opacity-70' : '',
							dropTargetSlotId === row.id && draggedSlotId !== row.id ? 'bg-white/8 shadow-[inset_0_0_0_2px_rgba(255,255,255,0.95)]' : '',
							pendingSwapSlotIds?.includes(row.id) ? 'bg-elevated/80 shadow-[inset_0_1px_0_rgba(255,255,255,0.08)]' : '',
						]"
						:draggable="Boolean(row.slot.assigned_character_id) && !isSwapPending"
						@dragstart="handleDragStart($event, row.slot)"
						@dragend="emit('dragEnd')"
						@dragenter.prevent="emit('dragEnter', row.id)"
						@dragleave.prevent="emit('dragLeave', row.id)"
						@dragover="handleDragOver($event, row.id)"
						@drop="handleDrop($event, row.id)"
						@click="handleRowClick(row.slot)"
					>
						<td class="px-5 py-4 text-sm font-medium text-toned">
							<USkeleton v-if="pendingSwapSlotIds?.includes(row.id)" class="h-5 w-24 bg-muted/70" />
							<template v-else>{{ row.groupLabel }}</template>
						</td>
						<td class="px-5 py-4 text-sm text-toned">
							<USkeleton v-if="pendingSwapSlotIds?.includes(row.id)" class="h-5 w-28 bg-muted/70" />
							<template v-else>{{ row.slotLabel }}</template>
						</td>
						<td class="px-5 py-4">
							<div v-if="pendingSwapSlotIds?.includes(row.id)" class="flex flex-wrap gap-2">
								<USkeleton class="h-5 w-20 bg-muted/70" />
								<USkeleton class="h-5 w-24 bg-muted/70" />
								<USkeleton class="h-5 w-16 bg-muted/70" />
							</div>
							<div v-else class="flex flex-wrap gap-2">
								<UBadge
									v-for="field in row.fields"
									:key="field"
									color="neutral"
									variant="outline"
									:label="field"
								/>
							</div>
						</td>
						<td class="px-5 py-4">
							<USkeleton v-if="pendingSwapSlotIds?.includes(row.id)" class="h-5 w-20 bg-muted/70" />
							<UBadge
								v-else
								:color="row.statusColor"
								variant="subtle"
								:label="row.statusLabel"
							/>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</section>
</template>
