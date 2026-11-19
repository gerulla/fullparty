<script setup lang="ts">
import axios from "axios";
import { computed, ref } from "vue";
import { usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { useToast } from "@nuxt/ui/composables";
import RunOverviewContent from "@/components/Groups/Activities/RunOverviewContent.vue";
import RunOverviewLayout from "@/Layouts/RunOverviewLayout.vue";
import ActivitySelfAssignRosterBoard from "@/components/Groups/Activities/ActivitySelfAssignRosterBoard.vue";
import SelfAssignCharacterToSlotModal from "@/components/Groups/Activities/SelfAssignCharacterToSlotModal.vue";
import { isArchivedActivityStatus } from "@/utils/activityLifecycle";
import type { ActivityOverviewPermissions, AttendeeActivity, RunOverviewGroup } from "@/Types/ActivityAttendee";
import type { ActivitySlot } from "@/Types/ActivityRoster";
import type { ManualAssignmentCharacter, QueueFilterField } from "@/Types/ActivityQueue";
import type { ActivitySlotFieldSelection } from "@/Types/ActivityHolsters";

defineOptions({ layout: RunOverviewLayout });

const props = defineProps<{
	group: RunOverviewGroup
	activity: AttendeeActivity
	permissions: ActivityOverviewPermissions
	secretKey?: string | null
	slotFieldDefinitions: QueueFilterField[]
	selfAssignmentCharacters: ManualAssignmentCharacter[]
}>()

const { t } = useI18n();
const page = usePage();
const toast = useToast();
const activityData = ref<AttendeeActivity>({
	...props.activity,
	slots: [...props.activity.slots],
});
const selfAssignSlot = ref<ActivitySlot | null>(null);
const isSelfAssignModalOpen = ref(false);
const isSelfAssignmentPending = ref(false);
const pendingSlotId = ref<number | null>(null);
const viewerUserId = computed<number | null>(() => {
	const userId = page.props.auth?.user?.id;

	return typeof userId === "number" ? userId : null;
});

const currentActivity = computed(() => activityData.value);

const canSelfAssign = computed(() => (
	props.permissions.can_self_assign
	&& !isArchivedActivityStatus(currentActivity.value.status)
));
const viewerAssignedSlot = computed(() => currentActivity.value.slots.find((slot) => (
	slot.assigned_character !== null
	&& slot.assigned_character.user_id !== null
	&& slot.assigned_character.user_id === viewerUserId.value
)) ?? null);
const hasSelfAssignmentCharacters = computed(() => props.selfAssignmentCharacters.length > 0);
const selfAssignmentRouteParameters = computed(() => ({
	group: props.group.slug,
	activity: currentActivity.value.id,
	secretKey: props.secretKey || undefined,
}));
const initialCharacterId = computed(() => props.selfAssignmentCharacters[0]?.id ?? null);

const firstValidationErrorMessage = (error: any): string | null => {
	const errors = error?.response?.data?.errors;

	if (!errors || typeof errors !== "object") {
		return null;
	}

	for (const value of Object.values(errors)) {
		if (Array.isArray(value) && typeof value[0] === "string" && value[0].length > 0) {
			return value[0];
		}
	}

	return null;
};

const patchSlot = (updatedSlot: ActivitySlot) => {
	activityData.value = {
		...activityData.value,
		slots: activityData.value.slots.map((slot) => slot.id === updatedSlot.id ? updatedSlot : slot),
	};
};

const openSelfAssignModal = (slot: ActivitySlot) => {
	if (!canSelfAssign.value || viewerAssignedSlot.value || !hasSelfAssignmentCharacters.value) {
		return;
	}

	selfAssignSlot.value = slot;
	isSelfAssignModalOpen.value = true;
};

const closeSelfAssignModal = () => {
	isSelfAssignModalOpen.value = false;
	selfAssignSlot.value = null;
};

const confirmSelfAssign = async (payload: { characterId: number, slotId: number, fieldValues: Record<string, ActivitySlotFieldSelection> }) => {
	if (!selfAssignSlot.value || isSelfAssignmentPending.value) {
		return;
	}

	isSelfAssignmentPending.value = true;
	pendingSlotId.value = payload.slotId;

	try {
		const response = await axios.post(route("groups.activities.self-assignments.store", {
			...selfAssignmentRouteParameters.value,
			slot: payload.slotId,
		}), {
			character_id: payload.characterId,
			field_values: payload.fieldValues,
			expected_slot_state_token: selfAssignSlot.value.state_token,
		});

		patchSlot(response.data.slot);
		closeSelfAssignModal();

		toast.add({
			title: t("general.success"),
			description: t("groups.activities.overview.self_signup.messages.assign_success"),
			color: "success",
			icon: "i-lucide-check",
		});
	} catch (error: any) {
		toast.add({
			title: t("general.error"),
			description: firstValidationErrorMessage(error)
				?? error?.response?.data?.message
				?? t("groups.activities.overview.self_signup.messages.assign_error"),
			color: "error",
			icon: "i-lucide-octagon-alert",
		});
	} finally {
		isSelfAssignmentPending.value = false;
		pendingSlotId.value = null;
	}
};

const removeSelfFromSlot = async (slot: ActivitySlot) => {
	if (isSelfAssignmentPending.value) {
		return;
	}

	isSelfAssignmentPending.value = true;
	pendingSlotId.value = slot.id;

	try {
		const response = await axios.delete(route("groups.activities.self-assignments.destroy", {
			...selfAssignmentRouteParameters.value,
			slot: slot.id,
		}), {
			data: {
				expected_slot_state_token: slot.state_token,
			},
		});

		patchSlot(response.data.slot);

		toast.add({
			title: t("general.success"),
			description: t("groups.activities.overview.self_signup.messages.remove_success"),
			color: "success",
			icon: "i-lucide-check",
		});
	} catch (error: any) {
		toast.add({
			title: t("general.error"),
			description: firstValidationErrorMessage(error)
				?? error?.response?.data?.message
				?? t("groups.activities.overview.self_signup.messages.remove_error"),
			color: "error",
			icon: "i-lucide-octagon-alert",
		});
	} finally {
		isSelfAssignmentPending.value = false;
		pendingSlotId.value = null;
	}
};
</script>

<template>
    <RunOverviewContent
        :key="currentActivity.id"
        :activity="currentActivity"
        :group="group"
        :permissions="permissions"
        :secret-key="secretKey"
        :self-assigned-slot="viewerAssignedSlot"
        :has-self-assignment-characters="hasSelfAssignmentCharacters"
    >
        <template #roster="{ activity: displayedActivity, previewingRun }">
            <ActivitySelfAssignRosterBoard
                :group-slug="group.slug"
                :activity="displayedActivity"
                :can-self-assign="canSelfAssign && !isArchivedActivityStatus(displayedActivity.status)"
                :preview-only="previewingRun"
                :has-verified-characters="hasSelfAssignmentCharacters"
                :viewer-assigned-slot-id="viewerAssignedSlot?.id ?? null"
                :pending-slot-id="pendingSlotId"
                @assign="openSelfAssignModal"
                @remove="removeSelfFromSlot"
            />
        </template>
    </RunOverviewContent>

    <SelfAssignCharacterToSlotModal
        v-model:open="isSelfAssignModalOpen"
        :slot="selfAssignSlot"
        :characters="selfAssignmentCharacters"
        :slot-field-definitions="slotFieldDefinitions"
        :is-submitting="isSelfAssignmentPending"
        :initial-character-id="initialCharacterId"
        @confirm="confirmSelfAssign"
        @update:open="value => { if (!value) closeSelfAssignModal() }"
    />
</template>
