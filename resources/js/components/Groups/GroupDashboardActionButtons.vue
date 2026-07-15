<script setup lang="ts">
import type { GroupDashboardGroup } from "@/Types/Groups";
import { router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
// @ts-ignore
import { useConfirmationModal } from "@/composables/useConfirmationModal";
import GroupNotificationPreferencesModal from "@/components/Groups/GroupNotificationPreferencesModal.vue";
import { groupNotificationIcon } from "@/utils/groupNotifications";

const props = defineProps<{
	group: GroupDashboardGroup
}>();

const { t } = useI18n();
const page = usePage();
const confirmationModal = useConfirmationModal();
const notificationPreferencesOpen = ref(false);
const membershipActionPending = ref(false);

const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const showMembershipAction = computed(() => Boolean(
	props.group.permissions.can_join
	|| props.group.permissions.can_apply
	|| props.group.membership_application.pending,
));
const membershipActionLabel = computed(() => {
	if (props.group.membership_application.pending) {
		return t("groups.index.discovery.detail.actions.application_pending");
	}

	return props.group.permissions.can_apply
		? t("groups.index.discovery.detail.actions.apply")
		: t("groups.index.discovery.detail.actions.join");
});

const notificationActionLabel = () => t("groups.notifications.preferences.title");

const openRuns = () => {
	router.get(route("groups.dashboard.activities.index", props.group.slug));
};

const openMembers = () => {
	router.get(route("groups.dashboard.members", props.group.slug));
};

const openSettings = () => {
	router.get(route("groups.dashboard.settings", props.group.slug));
};

const openNotificationPreferences = () => {
	if (!props.group.permissions.can_toggle_notifications) {
		return;
	}

	notificationPreferencesOpen.value = true;
};

const joinOrApply = () => {
	if (membershipActionPending.value || props.group.membership_application.pending) {
		return;
	}

	membershipActionPending.value = true;

	if (props.group.permissions.can_apply) {
		router.get(route("groups.membership-applications.create", props.group.slug), {}, {
			onFinish: () => membershipActionPending.value = false,
		});

		return;
	}

	if (!isAuthenticated.value) {
		router.get(route("login", { invite: props.group.slug }), {}, {
			onFinish: () => membershipActionPending.value = false,
		});

		return;
	}

	router.post(route("groups.join", props.group.slug), {
		redirect_to: "dashboard",
	}, {
		onFinish: () => membershipActionPending.value = false,
	});
};

const leaveGroup = async () => {
	if (!props.group.permissions.can_leave) {
		return;
	}

	await confirmationModal.open({
		title: t("groups.dashboard.leave_group_modal.title", { name: props.group.name }),
		description: t("groups.dashboard.leave_group_modal.description", { name: props.group.name }),
		severity: "error",
		warningText: t("groups.dashboard.leave_group_modal.warning"),
		confirmLabel: t("groups.dashboard.leave_group_modal.confirm"),
		confirmIcon: "i-lucide-log-out",
		onConfirm: async ({ patch }) => {
			patch({ confirmLoading: true });

			return await new Promise<boolean>((resolve) => {
				router.post(route("groups.leave", props.group.slug), {
					redirect_to: props.group.is_visible ? "profile" : "groups",
				}, {
					onSuccess: () => {
						resolve(true);
					},
					onError: () => {
						resolve(false);
					},
					onFinish: () => {
						patch({ confirmLoading: false });
					},
				});
			});
		},
	});
};

const openDiscordInvite = () => {
	if (!props.group.discord_invite_url || typeof window === "undefined") {
		return;
	}

	window.open(props.group.discord_invite_url, "_blank", "noopener,noreferrer");
};
</script>

<template>
	<div class="w-full max-w-md ">
		<div class="flex flex-row flex-wrap justify-end items-center gap-4">
			<UButton
				v-if="showMembershipAction"
				:color="group.membership_application.pending ? 'neutral' : 'primary'"
				:variant="group.membership_application.pending ? 'outline' : 'solid'"
				:icon="group.permissions.can_apply || group.membership_application.pending ? 'i-lucide-file-check-2' : 'i-lucide-user-plus'"
				:label="membershipActionLabel"
				:loading="membershipActionPending"
				:disabled="group.membership_application.pending"
				class="justify-center"
				@click="joinOrApply"
			/>
			<UButton
				v-if="group.permissions.can_view_members"
				color="neutral"
				icon="i-lucide-users"
				:label="t('groups.dashboard.actions.view_members')"
				class="justify-center"
				@click="openMembers"
			/>
			<UButton
				color="neutral"
				icon="i-lucide-calendar-range"
				:label="t('groups.dashboard.actions.view_runs')"
				class="justify-center"
				@click="openRuns"
			/>
			<UButton
				v-if="group.discord_invite_url"
				color="neutral"
				variant="ghost"
				icon="ic:baseline-discord"
				size="xl"
				class="justify-center"
				@click="openDiscordInvite"
			/>
			<UButton
				v-if="group.permissions.can_toggle_notifications"
				color="neutral"
				variant="ghost"
				:icon="groupNotificationIcon(group.notifications)"
				:aria-label="notificationActionLabel()"
				:title="notificationActionLabel()"
				class="justify-center"
				@click="openNotificationPreferences"
			/>
			<UButton
				v-if="group.permissions.can_leave"
				color="error"
				variant="ghost"
				icon="i-lucide-log-out"
				class="justify-center sm:col-span-2"
				@click="leaveGroup"
			/>
		</div>
		<GroupNotificationPreferencesModal
			v-model:open="notificationPreferencesOpen"
			:group="group"
		/>
	</div>
</template>
