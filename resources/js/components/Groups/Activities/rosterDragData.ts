import type { ActivitySlot } from "@/components/Groups/Activities/rosterTypes";
import type { QueueApplication } from "@/components/Groups/Activities/queueTypes";

export const QUEUE_APPLICATION_DRAG_MIME = 'application/x-fullparty-queue-application';
export const ROSTER_SLOT_DRAG_MIME = 'application/x-fullparty-roster-slot';

export const isQueueApplicationDrag = (event: DragEvent) => (
	event.dataTransfer?.types?.includes(QUEUE_APPLICATION_DRAG_MIME) ?? false
);

export const setQueueApplicationDragData = (event: DragEvent, application: QueueApplication) => {
	event.dataTransfer?.setData(QUEUE_APPLICATION_DRAG_MIME, JSON.stringify(application));
};

export const isRosterSlotDrag = (event: DragEvent) => (
	event.dataTransfer?.types?.includes(ROSTER_SLOT_DRAG_MIME) ?? false
);

export const setRosterSlotDragData = (event: DragEvent, slot: ActivitySlot) => {
	event.dataTransfer?.setData(ROSTER_SLOT_DRAG_MIME, JSON.stringify(slot));
};

export const getQueueApplicationDragData = (event: DragEvent): QueueApplication | null => {
	const payload = event.dataTransfer?.getData(QUEUE_APPLICATION_DRAG_MIME);

	if (!payload) {
		return null;
	}

	try {
		return JSON.parse(payload) as QueueApplication;
	} catch (error) {
		console.error(error);
		return null;
	}
};

export const getRosterSlotDragData = (event: DragEvent): ActivitySlot | null => {
	const payload = event.dataTransfer?.getData(ROSTER_SLOT_DRAG_MIME);

	if (!payload) {
		return null;
	}

	try {
		return JSON.parse(payload) as ActivitySlot;
	} catch (error) {
		console.error(error);
		return null;
	}
};
