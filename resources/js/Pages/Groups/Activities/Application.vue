<script setup lang="ts">
import type { ActivityApplicationCharacterOption, RememberedApplicationDefaults } from "@/Types/ActivityApplications";
import { computed, ref } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { useToast } from "@nuxt/ui/composables";
import SeoHead from "@/components/Shared/SeoHead.vue";
import { localizedValue } from "@/utils/localizedValue";
import { getActivityStatusMeta } from "@/utils/activityStatusMeta";
import { canAcceptActivityApplications } from "@/utils/activityLifecycle";
import ActivityApplicationForm from "@/components/Groups/Activities/ActivityApplicationForm.vue";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import { formatRelativeTime } from "@/utils/formatRelativeTime";
import { useMinuteTicker } from "@/composables/useMinuteTicker";

const props = defineProps<{
	group: {
		id: number
		name: string
		slug: string
		is_public: boolean
	}
	activity: {
		id: number
		activity_type: {
			id: number | null
			slug: string | null
			draft_name: Record<string, string | null | undefined> | null | undefined
		}
		activity_type_version_id: number
		title: string | null
		description: string | null
		notes: string | null
		status: string
		starts_at: string | null
		duration_hours: number | null
		target_prog_point_key: string | null
		needs_application: boolean
		allow_guest_applications: boolean
		slot_count: number
		assigned_slot_count: number
		pending_application_count: number
		organized_by: {
			id: number
			name: string
			avatar_url: string | null
		} | null
		organized_by_character: {
			id: number
			user_id: number
			name: string
			avatar_url: string | null
		} | null
	}
	applicationSchema: Array<{
		key: string
		label: Record<string, string | null | undefined>
		type: string
		source: string | null
		required?: boolean
		help_text?: Record<string, string | null | undefined> | null
		options: Array<{
			key: string
			label: Record<string, string | null | undefined>
			meta?: {
				icon_url?: string | null
				role?: string | null
				shorthand?: string | null
			} | null
		}>
	}>
	application: {
		id: number
		selected_character_id: number | null
		status: string
		is_rostered: boolean
		notes: string | null
		submitted_at: string | null
		applicant_character?: {
			lodestone_id: string
			name: string
			world: string
			datacenter: string
			avatar_url: string | null
		} | null
		answers: Record<string, unknown>
	} | null
	rememberedApplicationDefaults: RememberedApplicationDefaults | null
	secretKey?: string
	guestAccessToken?: string
	guestCharacterSearch: {
		worlds: Array<{
			label: string
			value: string
		}>
	}
	characters: ActivityApplicationCharacterOption[]
	permissions: {
		can_apply: boolean
		can_apply_as_guest: boolean
		can_edit_application: boolean
		can_withdraw_application: boolean
		can_manage: boolean
		has_existing_application: boolean
		requires_group_membership: boolean
		can_join_group: boolean
		can_request_group_membership: boolean
	}
}>();

const { t, locale } = useI18n();
const page = usePage();
const toast = useToast();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));
const withdrawalModalOpen = ref(false);
const isWithdrawing = ref(false);
const relativeTimeTick = useMinuteTicker();

const activityTypeName = computed(() => {
	return localizedValue(props.activity.activity_type?.draft_name, locale.value, fallbackLocale.value)
		|| props.activity.activity_type?.slug
		|| t('groups.activities.cards.unknown_type');
});

const activityTitle = computed(() => props.activity.title || activityTypeName.value);
const statusMeta = computed(() => getActivityStatusMeta(props.activity.status));
const seoDescription = computed(() => t("meta.seo.activities.application_description", {
	title: activityTitle.value,
	group: props.group.name,
}));
const acceptsApplications = computed(() => canAcceptActivityApplications(props.activity.status));
const dateLabel = computed(() => {
	if (!props.activity.starts_at) {
		return t('groups.activities.cards.no_time');
	}

	return createDateTimeFormatter(locale.value, {
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	}).format(new Date(props.activity.starts_at));
});

const startsAtLabel = computed(() => {
	if (!props.activity.starts_at) {
		return t('groups.activities.cards.no_time');
	}

	return createDateTimeFormatter(locale.value, {
		hour: '2-digit',
		minute: '2-digit',
		timeZone: 'UTC',
		timeZoneName: 'short',
	}).format(new Date(props.activity.starts_at));
});

const timeDurationLabel = computed(() => {
	if (!props.activity.starts_at) {
		return t('groups.activities.cards.no_time');
	}

	const relativeStartsAtLabel = formatRelativeTime(
		props.activity.starts_at,
		locale.value,
		t("notifications.just_now"),
		t("groups.activities.cards.no_relative_time"),
		relativeTimeTick.value,
	);
	const duration = props.activity.duration_hours
		? ` - ${t('groups.activities.management.overview.duration', { count: props.activity.duration_hours })}`
		: '';

	return `${startsAtLabel.value} (${relativeStartsAtLabel})${duration}`;
});

const organizerLabel = computed(() => {
	return props.activity.organized_by_character?.name
		|| props.activity.organized_by?.name
		|| t('groups.activities.cards.no_organizer');
});
const displayGroupName = computed(() => props.group.name || "—");

const applicationPageSubtitle = computed(() => {
	return props.permissions.has_existing_application
		? t('groups.activities.application.subtitle_existing', {
			group: props.group.name,
			type: activityTypeName.value,
		})
		: t('groups.activities.application.subtitle', {
			group: props.group.name,
			type: activityTypeName.value,
		});
});
const withdrawalActionLabel = computed(() => (
	props.application?.is_rostered
		? t("applications.withdraw.action_run")
		: t("applications.withdraw.action_application")
));
const withdrawalConfirmDescription = computed(() => (
	props.application?.is_rostered
		? t("applications.withdraw.confirm_description_run")
		: t("applications.withdraw.confirm_description_application")
));

const goBack = () => {
	router.get(route('groups.activities.overview', {
		group: props.group.slug,
		activity: props.activity.id,
		secretKey: props.secretKey || undefined,
	}));
};

const openWithdrawalModal = () => {
	withdrawalModalOpen.value = true;
};

const closeWithdrawalModal = () => {
	if (isWithdrawing.value) {
		return;
	}

	withdrawalModalOpen.value = false;
};

const withdrawApplication = () => {
	if (!props.application || isWithdrawing.value) {
		return;
	}

	isWithdrawing.value = true;

	if (props.guestAccessToken) {
		router.delete(route("groups.activities.application.destroy-guest", {
			group: props.group.slug,
			activity: props.activity.id,
			accessToken: props.guestAccessToken,
			secretKey: props.secretKey || undefined,
		}), {
			onFinish: () => {
				isWithdrawing.value = false;
				withdrawalModalOpen.value = false;
			},
		});

		return;
	}

	router.delete(route("account.applications.destroy", {
		application: props.application.id,
	}), {
		onSuccess: () => {
			toast.add({
				title: t("applications.withdraw.success_title"),
				description: t("applications.withdraw.success_description"),
				color: "success",
			});
		},
		onError: () => {
			toast.add({
				title: t("applications.withdraw.error_title"),
				description: t("applications.withdraw.error_description"),
				color: "error",
			});
		},
		onFinish: () => {
			isWithdrawing.value = false;
			withdrawalModalOpen.value = false;
		},
	});
};
</script>

<template>
	<div class="w-full">
		<SeoHead
			:title="activityTitle"
			:description="seoDescription"
			noindex
		/>

		<UButton
			:label="t('groups.activities.application.back')"
			icon="i-lucide-arrow-left"
			variant="ghost"
			color="neutral"
			@click="goBack"
		/>

		<div class="flex flex-col gap-6 mt-2">
			<UCard class="dark:bg-elevated/25">
				<template #header>
					<div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
						<div class="flex min-w-0 flex-1 flex-col gap-1">
							<p class="font-semibold text-muted text-md">{{ t('groups.activities.application.run_info_title') }}</p>
							<h1 class="break-words [overflow-wrap:anywhere] font-semibold text-2xl text-toned">{{ activityTitle }}</h1>
							<p class="break-words [overflow-wrap:anywhere] whitespace-pre-wrap text-sm text-muted">{{ activity.description ?? applicationPageSubtitle }}</p>
						</div>

						<div class="h-full flex flex-col items-start justify-center gap-4 xl:items-end">
							<div class="flex flrex-row gap-2">
								<UBadge
									size="md"
									variant="subtle"
									:color="statusMeta.color"
									:icon="statusMeta.icon"
									:label="t(`groups.activities.statuses.${activity.status}`)"
								/>
								<UBadge
									color="neutral"
									variant="soft"
									size="md"
									:label="activityTypeName"
								/>
							</div>
							<div class="flex flex-row gap-2 text-sm text-muted xl:items-end">
								<div class="inline-flex items-center gap-2">
									<UIcon name="i-lucide-calendar-days" class="size-4" />
									<span>{{ dateLabel }}</span>
								</div>

								<div class="inline-flex items-center gap-2">
									<UIcon name="i-lucide-clock-3" class="size-4" />
									<span>{{ timeDurationLabel }}</span>
								</div>
							</div>
						</div>
					</div>
				</template>

				<div class="flex flex-col gap-4">
					<div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
						<div class="inline-flex items-center gap-2">
							<span class="text-muted">{{ t('groups.activities.management.overview.group') }}:</span>
							<span class="block max-w-128 truncate font-medium text-toned">{{ displayGroupName }}</span>
						</div>

						<div class="hidden h-4 w-px bg-default md:block"></div>

						<div class="inline-flex items-center gap-2">
							<span class="text-muted">{{ t('groups.activities.management.organizer') }}:</span>
							<UUser
								v-if="activity.organized_by_character"
								:name="activity.organized_by_character.name"
								:avatar="activity.organized_by_character.avatar_url ? { src: activity.organized_by_character.avatar_url, alt: activity.organized_by_character.name } : undefined"
								size="sm"
							/>
							<span v-else class="font-medium text-toned">{{ organizerLabel }}</span>
						</div>
					</div>

					<div
						v-if="activity.description || activity.notes"
						class="border-t border-default pt-4"
					>
						<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.application.summary_notes') }}</p>
						<div class="mt-2 flex flex-col gap-3 text-sm text-toned">
							<p v-if="activity.description" class="break-words [overflow-wrap:anywhere] whitespace-pre-wrap">{{ activity.description }}</p>
							<p v-if="activity.notes" class="break-words [overflow-wrap:anywhere] whitespace-pre-wrap text-muted">{{ activity.notes }}</p>
						</div>
					</div>
				</div>
			</UCard>

			<ActivityApplicationForm
				:group-slug="group.slug"
				:activity-id="activity.id"
				:secret-key="secretKey"
				:guest-access-token="guestAccessToken"
				:characters="characters"
				:questions="applicationSchema"
				:application="application"
				:remembered-application-defaults="rememberedApplicationDefaults"
				:accepts-applications="acceptsApplications"
				:can-apply="permissions.can_apply"
				:can-apply-as-guest="permissions.can_apply_as_guest"
				:can-edit-application="permissions.can_edit_application"
				:can-withdraw-application="permissions.can_withdraw_application"
				:requires-group-membership="permissions.requires_group_membership"
				:can-join-group="permissions.can_join_group"
				:can-request-group-membership="permissions.can_request_group_membership"
				:guest-worlds="guestCharacterSearch.worlds"
				@cancel="goBack"
				@withdraw="openWithdrawalModal"
			/>
		</div>

		<UModal
			:open="withdrawalModalOpen"
			:title="t('applications.withdraw.confirm_title')"
			:description="withdrawalConfirmDescription"
			@update:open="(open) => { if (!open) closeWithdrawalModal(); }"
		>
			<template #body>
				<div class="space-y-4">
					<UAlert
						color="warning"
						variant="soft"
						icon="i-lucide-triangle-alert"
						:title="t('applications.withdraw.warning_title')"
						:description="t('applications.withdraw.warning_description')"
					/>
				</div>
			</template>

			<template #footer>
				<div class="flex w-full items-center justify-end gap-2">
					<UButton
						color="neutral"
						variant="outline"
						:label="t('general.cancel')"
						:disabled="isWithdrawing"
						@click="closeWithdrawalModal"
					/>
					<UButton
						color="error"
						variant="soft"
						icon="i-lucide-trash-2"
						:label="withdrawalActionLabel"
						:loading="isWithdrawing"
						@click="withdrawApplication"
					/>
				</div>
			</template>
		</UModal>
	</div>
</template>
