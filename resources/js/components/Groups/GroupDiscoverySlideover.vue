<script setup lang="ts">
import type { GroupDiscoveryDetailRecord } from "@/Types/Groups";
import { router } from "@inertiajs/vue3";
import { computed, defineAsyncComponent, ref } from "vue";
import { useI18n } from "vue-i18n";
import { route } from "ziggy-js";
import { useConfirmationModal } from "@/composables/useConfirmationModal";
import GroupNotificationPreferencesModal from "@/components/Groups/GroupNotificationPreferencesModal.vue";
import { groupNotificationIcon } from "@/utils/groupNotifications";
import ReportButton from '@/components/Shared/Reports/ReportButton.vue';

const GroupDiscoveryInfoTab = defineAsyncComponent(() => import("@/components/Groups/GroupDiscoveryInfoTab.vue"));
const GroupDiscoveryActivityTab = defineAsyncComponent(() => import("@/components/Groups/GroupDiscoveryActivityTab.vue"));
const GroupDiscoveryContentTab = defineAsyncComponent(() => import("@/components/Groups/GroupDiscoveryContentTab.vue"));
const GroupDiscoveryTeamTab = defineAsyncComponent(() => import("@/components/Groups/GroupDiscoveryTeamTab.vue"));

const props = defineProps<{
	open: boolean
	group: GroupDiscoveryDetailRecord | null
	loading?: boolean
}>();

const emit = defineEmits<{
	"update:open": [value: boolean]
	"refresh-group": [groupSlug: string]
}>();

const { t } = useI18n();
const confirmationModal = useConfirmationModal();

const openModel = computed({
	get: () => props.open,
	set: (value: boolean) => emit("update:open", value),
});

const selectedDetailTab = ref("about");

const groupTypeLabel = computed(() => {
	if (!props.group?.group_type) {
		return t("groups.common.states.not_shared");
	}

	return t(`groups.common.group_types.${props.group.group_type}`);
});
const joinModeLabel = computed(() => {
	if (!props.group?.join_mode) {
		return t("groups.common.states.not_shared");
	}

	return t(`groups.common.join_modes.${props.group.join_mode}.label`);
});
const joinModeIcon = computed(() => {
	if (props.group?.join_mode === "open") {
		return "i-lucide-door-open";
	}

	if (props.group?.join_mode === "application") {
		return "i-lucide-file-check-2";
	}

	return "i-lucide-ticket";
});
const accessStatusLabel = computed(() => {
	if (!props.group || props.group.current_user_role || props.group.permissions.can_join || props.group.permissions.can_apply) {
		return null;
	}

	if (props.group.membership_application.pending) {
		return t("groups.index.discovery.detail.actions.application_pending");
	}

	if (props.group.join_mode === "application") {
		return t("groups.index.discovery.detail.actions.application_required");
	}

	if (props.group.join_mode === "invite_only") {
		return t("groups.index.discovery.detail.actions.invite_required");
	}

	return null;
});

const bannerUrl = computed(() => props.group?.banner_image_url ?? "/prereqimages/forked.jpg");
const isActionPending = ref(false);
const notificationPreferencesOpen = ref(false);
const canShowActionButtons = computed(() => Boolean(props.group) && !props.loading);
const isMember = computed(() => Boolean(props.group?.current_user_role));
const membershipActionLabel = computed(() => (
	isMember.value
		? t("groups.index.discovery.detail.actions.leave")
		: props.group?.permissions.can_apply
			? t("groups.index.discovery.detail.actions.apply")
		: t("groups.index.discovery.detail.actions.join")
));
const notificationsActionLabel = computed(() => (
	t("groups.notifications.preferences.title")
));
const showMembershipAction = computed(() => canShowActionButtons.value
	&& Boolean(props.group?.permissions.can_join || props.group?.permissions.can_apply || props.group?.permissions.can_leave));
const showDashboardAction = computed(() => canShowActionButtons.value && Boolean(props.group?.links.dashboard));
const showNotificationsAction = computed(() => canShowActionButtons.value && Boolean(props.group?.permissions.can_toggle_notifications));
const detailTabs = computed(() => ([
	{
		label: t("groups.index.discovery.detail.tabs.about"),
		value: "about",
	},
	{
		label: t("groups.index.discovery.detail.tabs.activity"),
		value: "activity",
	},
	{
		label: t("groups.index.discovery.detail.tabs.content"),
		value: "content",
	},
	{
		label: t("groups.index.discovery.detail.tabs.team"),
		value: "team",
	},
]));

const finishAction = () => {
	isActionPending.value = false;
};

const refreshCurrentGroup = () => {
	if (props.group) {
		emit("refresh-group", props.group.slug);
	}
};

const leaveGroup = async () => {
	if (!props.group || isActionPending.value || !props.group.permissions.can_leave) {
		return;
	}

	const group = props.group;

	await confirmationModal.open({
		title: t("groups.dashboard.leave_group_modal.title", { name: group.name }),
		description: t("groups.dashboard.leave_group_modal.description", { name: group.name }),
		severity: "error",
		warningText: t("groups.dashboard.leave_group_modal.warning"),
		confirmLabel: t("groups.dashboard.leave_group_modal.confirm"),
		confirmIcon: "i-lucide-log-out",
		onConfirm: async ({ patch }) => {
			patch({ confirmLoading: true });
			isActionPending.value = true;

			return await new Promise<boolean>((resolve) => {
				router.post(route("groups.leave", group.slug), {
					redirect_to: "back",
				}, {
					preserveState: true,
					onSuccess: () => {
						emit("refresh-group", group.slug);
						resolve(true);
					},
					onError: () => {
						resolve(false);
					},
					onFinish: () => {
						isActionPending.value = false;
						patch({ confirmLoading: false });
					},
				});
			});
		},
	});
};

const toggleMembership = async () => {
	if (!props.group || isActionPending.value || !showMembershipAction.value) {
		return;
	}

	if (props.group.permissions.can_leave) {
		await leaveGroup();
		return;
	}

	isActionPending.value = true;

	if (props.group.permissions.can_apply) {
		router.get(route("groups.membership-applications.create", props.group.slug), {}, {
			onFinish: finishAction,
		});

		return;
	}

	router.post(route("groups.join", props.group.slug), {
		redirect_to: "back",
	}, {
		preserveState: true,
		onSuccess: refreshCurrentGroup,
		onFinish: finishAction,
	});
};

const openDashboard = () => {
	if (!props.group?.links.dashboard || isActionPending.value) {
		return;
	}

	router.get(props.group.links.dashboard);
};

const openNotificationPreferences = () => {
	if (!props.group || isActionPending.value || !props.group.permissions.can_toggle_notifications) {
		return;
	}

	notificationPreferencesOpen.value = true;
};

</script>

<template>
	<USlideover
		v-model:open="openModel"
		side="right"
		:title="t('groups.index.discovery.detail.title')"
		:description="loading && !group ? t('groups.index.discovery.detail.loading') : undefined"
		:ui="{ body: 'p-0 sm:p-0', content: 'max-w-2xl' }"
	>
		<template #body>
			<div class="flex h-full flex-col overflow-hidden">
				<div v-if="loading && !group" class="space-y-4 p-4">
					<USkeleton class="h-40 w-full" />
					<div class="grid gap-4">
						<USkeleton class="h-24 w-full" />
						<USkeleton class="h-32 w-full" />
						<USkeleton class="h-28 w-full" />
					</div>
				</div>

				<div v-else-if="group" class="flex h-full min-h-0 flex-col">
					<div class="sticky top-0 z-20 shrink-0 bg-default">
						<div class="relative h-44 overflow-hidden border-b border-default bg-neutral-950">
							<div v-if="!loading" class="absolute right-4 top-4 z-10"><ReportButton :target="{ type: 'group', id: group.id, label: group.name }" class="bg-black/45 text-white ring-white/30 hover:bg-black/65" /></div>
							<img
								:src="bannerUrl"
								:alt="group.name"
								class="h-full w-full object-cover"
							>
							<div class="absolute inset-0 bg-linear-to-t from-neutral-950 via-neutral-950/72 to-neutral-950/22" />
							<div class="absolute inset-x-0 bottom-0 p-4">
								<div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
									<div class="flex items-end gap-4">
										<div class="flex size-16 shrink-0 items-center justify-center overflow-hidden border border-white/10 bg-neutral-900">
											<img
												v-if="group.profile_picture_url"
												:src="group.profile_picture_url"
												:alt="group.name"
												class="h-full w-full object-cover"
											>
											<UIcon v-else name="i-lucide-users" class="size-7 text-white/65" />
										</div>

										<div class="min-w-0 flex-1">
											<p class="text-xs font-medium uppercase tracking-[0.18em] text-white/70">
												{{ groupTypeLabel }}
											</p>
											<h2 class="text-2xl font-semibold text-white break-words [overflow-wrap:anywhere]">
												{{ group.name }}
											</h2>
											<div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-white/78">
												<span class="inline-flex items-center gap-1">
													<UIcon name="i-lucide-server" class="size-4" />
													{{ group.datacenter || t('groups.common.states.not_shared') }}
												</span>
												<span v-if="group.region" class="inline-flex items-center gap-1">
													<UIcon name="i-lucide-globe" class="size-4" />
													{{ group.region }}
												</span>
												<span class="inline-flex items-center gap-1">
													<UIcon :name="joinModeIcon" class="size-4" />
													{{ joinModeLabel }}
												</span>
											</div>
										</div>
									</div>

									<div
										v-if="canShowActionButtons"
										class="flex flex-wrap items-center gap-2 lg:max-w-sm lg:justify-end"
									>
										<UButton
											v-if="showMembershipAction"
											:color="group.permissions.can_leave ? 'error' : 'primary'"
											:variant="group.permissions.can_leave ? 'outline' : 'solid'"
											:icon="group.permissions.can_leave ? 'i-lucide-log-out' : group.permissions.can_apply ? 'i-lucide-file-check-2' : 'i-lucide-user-plus'"
											:label="membershipActionLabel"
											:disabled="isActionPending"
											@click="toggleMembership"
										/>
										<UButton
											v-if="showDashboardAction"
											color="neutral"
											variant="outline"
											icon="i-lucide-layout-dashboard"
											:label="t('groups.index.discovery.detail.actions.dashboard')"
											:disabled="isActionPending"
											@click="openDashboard"
										/>
										<UButton
											v-if="accessStatusLabel"
											color="neutral"
											variant="outline"
											:icon="joinModeIcon"
											:label="accessStatusLabel"
											disabled
										/>
										<UButton
											v-if="showNotificationsAction"
											color="neutral"
											variant="ghost"
											:icon="groupNotificationIcon(group.notifications)"
											:aria-label="notificationsActionLabel"
											:title="notificationsActionLabel"
											:disabled="isActionPending"
											@click="openNotificationPreferences"
										/>
									</div>
								</div>
							</div>
						</div>

						<div class="border-b border-default bg-default/95 px-4 py-4 backdrop-blur">
							<UTabs
								v-model="selectedDetailTab"
								:items="detailTabs"
								variant="link"
								:content="false"
								size="lg"
								class="w-full"
							/>
						</div>
					</div>

					<div class="min-h-0 flex-1 overflow-y-auto p-4">
						<div class="min-h-72">
							<GroupDiscoveryInfoTab
								v-if="selectedDetailTab === 'about'"
								:group="group"
							/>

							<GroupDiscoveryActivityTab
								v-else-if="selectedDetailTab === 'activity'"
								:group="group"
							/>

							<GroupDiscoveryContentTab
								v-else-if="selectedDetailTab === 'content'"
								:group="group"
							/>

							<GroupDiscoveryTeamTab v-else :group="group" />
						</div>
					</div>
				</div>
			</div>
			<GroupNotificationPreferencesModal
				v-if="group"
				v-model:open="notificationPreferencesOpen"
				:group="group"
				@saved="refreshCurrentGroup"
			/>
		</template>
	</USlideover>
</template>
