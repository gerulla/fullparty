<script setup lang="ts">
import type { ActivityApplicationRecord, ApplicationQuestion } from "@/Types/ActivityApplications";
import { computed, ref } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { useToast } from "@nuxt/ui/composables";
import SeoHead from "@/components/Shared/SeoHead.vue";
import { localizedValue } from "@/utils/localizedValue";
import { getActivityStatusMeta } from "@/utils/activityStatusMeta";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import { formatRelativeTime } from "@/utils/formatRelativeTime";
import { useMinuteTicker } from "@/composables/useMinuteTicker";
import { translateCharacterClassName, translatePhantomJobName, translateRaidPositionName } from "@/utils/characterJobTranslations";

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
		title: string | null
		description: string | null
		notes: string | null
		status: string
		starts_at: string | null
		duration_hours: number | null
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
	applicationSchema: ApplicationQuestion[]
	application: ActivityApplicationRecord | null
	secretKey?: string
	guestAccessToken?: string
	confirmation: {
		view: "confirmation" | "status"
		mode: "submitted" | "updated"
		can_edit: boolean
		can_withdraw: boolean
	}
}>();

const { t, locale } = useI18n();
const page = usePage();
const toast = useToast();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? "en"));
const withdrawalModalOpen = ref(false);
const isWithdrawing = ref(false);
const relativeTimeTick = useMinuteTicker();

const activityTypeName = computed(() => {
	return localizedValue(props.activity.activity_type?.draft_name, locale.value, fallbackLocale.value)
		|| props.activity.activity_type?.slug
		|| t("groups.activities.cards.unknown_type");
});

const activityTitle = computed(() => props.activity.title || activityTypeName.value);
const statusMeta = computed(() => getActivityStatusMeta(props.activity.status));
const seoDescription = computed(() => t("meta.seo.activities.application_status_description", {
	title: activityTitle.value,
	group: props.group.name,
}));

const dateLabel = computed(() => {
	if (!props.activity.starts_at) {
		return t("groups.activities.cards.no_time");
	}

	return createDateTimeFormatter(locale.value, {
		year: "numeric",
		month: "2-digit",
		day: "2-digit",
	}).format(new Date(props.activity.starts_at));
});

const startsAtLabel = computed(() => {
	if (!props.activity.starts_at) {
		return t("groups.activities.cards.no_time");
	}

	return createDateTimeFormatter(locale.value, {
		hour: "2-digit",
		minute: "2-digit",
		timeZone: "UTC",
		timeZoneName: "short",
	}).format(new Date(props.activity.starts_at));
});

const timeDurationLabel = computed(() => {
	if (!props.activity.starts_at) {
		return t("groups.activities.cards.no_time");
	}

	const relativeStartsAtLabel = formatRelativeTime(
		props.activity.starts_at,
		locale.value,
		t("notifications.just_now"),
		t("groups.activities.cards.no_relative_time"),
		relativeTimeTick.value,
	);
	const duration = props.activity.duration_hours
		? ` - ${t("groups.activities.management.overview.duration", { count: props.activity.duration_hours })}`
		: "";

	return `${startsAtLabel.value} (${relativeStartsAtLabel})${duration}`;
});

const organizerLabel = computed(() => {
	return props.activity.organized_by_character?.name
		|| props.activity.organized_by?.name
		|| t("groups.activities.cards.no_organizer");
});

const displayGroupName = computed(() => props.group.name || "—");

const truncateInlineText = (value: string, maxLength = 96) => {
	if (value.length <= maxLength) {
		return value;
	}

	return `${value.slice(0, maxLength - 1).trimEnd()}…`;
};

const submittedAtLabel = computed(() => {
	if (!props.application?.submitted_at) {
		return null;
	}

	return createDateTimeFormatter(locale.value, {
		year: "numeric",
		month: "2-digit",
		day: "2-digit",
		hour: "2-digit",
		minute: "2-digit",
	}).format(new Date(props.application.submitted_at));
});

const applicationStatusMeta = computed(() => {
	return {
		pending: { color: "warning", label: t("groups.activities.application.confirmation.statuses.pending") },
		approved: { color: "success", label: t("groups.activities.application.confirmation.statuses.approved") },
		on_bench: { color: "info", label: t("groups.activities.application.confirmation.statuses.on_bench") },
		declined: { color: "error", label: t("groups.activities.application.confirmation.statuses.declined") },
		cancelled: { color: "neutral", label: t("groups.activities.application.confirmation.statuses.cancelled") },
		withdrawn: { color: "neutral", label: t("groups.activities.application.confirmation.statuses.withdrawn") },
	}[props.application?.status ?? "pending"] ?? { color: "neutral", label: props.application?.status ?? "" };
});

const confirmationTitle = computed(() => t(
	props.confirmation.view === "status"
		? "groups.activities.application.confirmation.status_title"
		: props.confirmation.mode === "updated"
			? "groups.activities.application.confirmation.updated_title"
			: "groups.activities.application.confirmation.submitted_title",
));

const confirmationDescription = computed(() => t(
	props.confirmation.view === "status"
		? "groups.activities.application.confirmation.status_description"
		: props.confirmation.mode === "updated"
			? "groups.activities.application.confirmation.updated_description"
			: "groups.activities.application.confirmation.submitted_description",
	{
		group: truncateInlineText(displayGroupName.value),
		type: activityTypeName.value,
	},
));

const isGuestApplication = computed(() => Boolean(props.guestAccessToken));
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

const answerSummaries = computed(() => props.applicationSchema
	.map((question) => {
		const value = props.application?.answers?.[question.key];
		const summary = formatAnswerValue(question, value);

		if (summary === null) {
			return null;
		}

		return {
			key: question.key,
			label: localizedValue(question.label, locale.value, fallbackLocale.value) || question.key,
			value: summary.value,
			isLongText: summary.isLongText,
		};
	})
	.filter((answer): answer is { key: string, label: string, value: string, isLongText: boolean } => answer !== null));

function formatAnswerValue(question: ApplicationQuestion, value: unknown): { value: string, isLongText: boolean } | null
{
	if (value === null || value === undefined || value === "") {
		return null;
	}

	if (question.type === "boolean") {
		return {
			value: value ? t("general.yes") : t("general.no"),
			isLongText: false,
		};
	}

	if (question.type === "multi_select" && Array.isArray(value)) {
		const labels = value
			.map((entry) => optionLabel(question, String(entry)))
			.filter((entry) => entry !== "");

		if (labels.length === 0) {
			return null;
		}

		return {
			value: labels.join(", "),
			isLongText: labels.length > 2,
		};
	}

	if (question.type === "single_select" && typeof value === "string") {
		const label = optionLabel(question, value);

		return label === ""
			? null
			: {
				value: label,
				isLongText: false,
			};
	}

	if (typeof value === "string") {
		return {
			value,
			isLongText: question.type === "textarea" || value.length > 80,
		};
	}

	if (typeof value === "number") {
		return {
			value: String(value),
			isLongText: false,
		};
	}

	return {
		value: String(value),
		isLongText: false,
	};
}

function optionLabel(question: ApplicationQuestion, optionKey: string): string
{
	const option = question.options.find((entry) => entry.key === optionKey);
	const fallback = option
		? localizedValue(option.label, locale.value, fallbackLocale.value) || option.key
		: optionKey;

	if (!option) {
		return fallback;
	}

	if (question.source === "character_classes") {
		return translateCharacterClassName(t, {
			shorthand: option.meta?.shorthand ?? null,
			name: fallback,
		}, fallback);
	}

	if (question.source === "phantom_jobs") {
		return translatePhantomJobName(t, { name: fallback }, fallback);
	}

	if (question.source === "raid_positions" || question.key.includes("raid_position")) {
		return translateRaidPositionName(t, { key: option.key, name: fallback }, fallback);
	}

	return fallback;
}

const goBack = () => {
	router.get(route("groups.activities.overview", {
		group: props.group.slug,
		activity: props.activity.id,
		secretKey: props.secretKey || undefined,
	}));
};

const editApplication = () => {
	if (props.guestAccessToken) {
		router.get(route("groups.activities.application.edit-guest", {
			group: props.group.slug,
			activity: props.activity.id,
			accessToken: props.guestAccessToken,
			secretKey: props.secretKey || undefined,
		}));

		return;
	}

	router.get(route("groups.activities.application", {
		group: props.group.slug,
		activity: props.activity.id,
		secretKey: props.secretKey || undefined,
	}));
};

const goToLogin = () => {
	router.get(route("login"));
};

const goToRegister = () => {
	router.get(route("register"));
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

const copyStatusLink = async () => {
	if (typeof window === "undefined") {
		return;
	}

	try {
		await navigator.clipboard.writeText(window.location.href);
		toast.add({
			title: t("groups.activities.application.confirmation.copy_link_success_title"),
			description: t("groups.activities.application.confirmation.copy_link_success_description"),
			color: "success",
		});
	} catch (error) {
		toast.add({
			title: t("groups.activities.application.confirmation.copy_link_error_title"),
			description: t("groups.activities.application.confirmation.copy_link_error_description"),
			color: "error",
		});
	}
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

		<div class="mt-2 flex flex-col gap-6">
			<UCard class="dark:bg-elevated/25">
				<template #header>
					<div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
						<div class="flex min-w-0 flex-1 flex-col gap-1">
							<p class="font-semibold text-muted text-md">{{ t('groups.activities.application.run_info_title') }}</p>
							<h1 class="break-words [overflow-wrap:anywhere] font-semibold text-2xl text-toned">{{ activityTitle }}</h1>
							<p class="break-words [overflow-wrap:anywhere] whitespace-pre-wrap text-sm text-muted">{{ activity.description || t('groups.activities.application.confirmation.run_info_subtitle') }}</p>
						</div>

						<div class="flex h-full flex-col items-start justify-center gap-4 xl:items-end">
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
			</UCard>

			<UCard class="dark:bg-elevated/25">
				<div class="flex flex-col gap-5">
					<UAlert
						color="success"
						variant="soft"
						icon="i-lucide-circle-check-big"
						:title="confirmationTitle"
						:description="confirmationDescription"
					/>

					<div
						v-if="isGuestApplication"
						class="rounded-sm border border-warning/30 bg-warning/10 px-4 py-4"
					>
						<div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
							<div class="flex min-w-0 gap-3">
								<UIcon name="i-lucide-link-2" class="mt-0.5 size-5 shrink-0 text-warning" />
								<div class="space-y-1">
									<p class="font-medium text-toned">
										{{ t('groups.activities.application.confirmation.guest_link_title') }}
									</p>
									<p class="text-sm text-muted">
										{{ t('groups.activities.application.confirmation.guest_link_description') }}
									</p>
								</div>
							</div>

							<div class="flex flex-wrap items-center gap-2">
								<UButton
									color="neutral"
									variant="soft"
									size="sm"
									icon="i-lucide-copy"
									:label="t('groups.activities.application.confirmation.copy_link')"
									@click="copyStatusLink"
								/>
							</div>
						</div>
					</div>

					<div
						v-if="isGuestApplication"
						class="rounded-sm border border-info/30 bg-info/10 px-4 py-4"
					>
						<div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
							<div class="flex min-w-0 gap-3">
								<UIcon name="i-lucide-info" class="mt-0.5 size-5 shrink-0 text-info" />
								<div class="space-y-1">
									<p class="font-medium text-toned">
										{{ t('groups.activities.application.confirmation.guest_cta_title') }}
									</p>
									<p class="text-sm text-muted">
										{{ t('groups.activities.application.confirmation.guest_cta_description') }}
									</p>
								</div>
							</div>

							<div class="flex flex-wrap items-center gap-2">
								<UButton
									color="neutral"
									variant="outline"
									size="sm"
									:label="t('auth.login')"
									@click="goToLogin"
								/>
								<UButton
									color="neutral"
									variant="outline"
									size="sm"
									:label="t('auth.register')"
									@click="goToRegister"
								/>
							</div>
						</div>
					</div>

					<div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
						<div class="rounded-sm border border-default bg-default px-4 py-3">
							<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.application.confirmation.character') }}</p>
							<div class="mt-3 flex items-center gap-3">
								<img
									v-if="application?.applicant_character?.avatar_url"
									:src="application.applicant_character.avatar_url"
									:alt="application.applicant_character.name"
									class="h-12 w-12 rounded-sm border border-default object-cover object-center"
								>
								<div v-else class="flex h-12 w-12 items-center justify-center rounded-sm border border-default bg-muted/30">
									<UIcon name="i-lucide-user-round" class="size-5 text-muted" />
								</div>
								<div class="min-w-0">
									<p class="truncate font-medium text-toned">{{ application?.applicant_character?.name }}</p>
									<p class="text-sm text-muted">
										{{ application?.applicant_character?.world }}<span v-if="application?.applicant_character?.datacenter"> - {{ application.applicant_character.datacenter }}</span>
									</p>
								</div>
							</div>
						</div>

						<div class="rounded-sm border border-default bg-default px-4 py-3">
							<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.application.confirmation.status') }}</p>
							<div class="mt-3 flex flex-col gap-3">
								<UBadge
									:color="applicationStatusMeta.color"
									variant="soft"
									class="w-fit"
									:label="applicationStatusMeta.label"
								/>
								<p v-if="submittedAtLabel" class="text-sm text-muted">
									{{ t('groups.activities.application.confirmation.submitted_at', { date: submittedAtLabel }) }}
								</p>
							</div>
						</div>
					</div>

					<div
						v-if="application?.review_reason"
						class="space-y-3 border-t border-default pt-5"
					>
						<div class="flex flex-col gap-1">
							<p class="font-semibold text-toned">{{ t('groups.activities.application.confirmation.review_reason_title') }}</p>
							<p class="text-sm text-muted">{{ t('groups.activities.application.confirmation.review_reason_description') }}</p>
						</div>

						<div class="rounded-sm border border-default bg-default px-4 py-3">
							<p class="break-words [overflow-wrap:anywhere] whitespace-pre-wrap font-medium text-toned">
								{{ application.review_reason }}
							</p>
						</div>
					</div>

					<div
						v-if="answerSummaries.length > 0"
						class="space-y-3 border-t border-default pt-5"
					>
						<div class="flex flex-col gap-1">
							<p class="font-semibold text-toned">{{ t('groups.activities.application.confirmation.answers_title') }}</p>
							<p class="text-sm text-muted">{{ t('groups.activities.application.confirmation.answers_description') }}</p>
						</div>

						<div class="grid grid-cols-1 gap-3 xl:grid-cols-2">
							<div
								v-for="answer in answerSummaries"
								:key="answer.key"
								class="rounded-sm border border-default bg-default px-4 py-3"
								:class="answer.isLongText ? 'xl:col-span-2' : ''"
							>
								<p class="text-sm text-muted">{{ answer.label }}</p>
								<p class="mt-2 break-words [overflow-wrap:anywhere] whitespace-pre-wrap font-medium text-toned">{{ answer.value }}</p>
							</div>
						</div>
					</div>

					<div class="space-y-3 border-t border-default pt-5">
						<div class="flex flex-col gap-1">
							<p class="font-semibold text-toned">{{ t('groups.activities.application.confirmation.notes_title') }}</p>
							<p class="text-sm text-muted">{{ t('groups.activities.application.confirmation.notes_description') }}</p>
						</div>

						<div class="rounded-sm border border-default bg-default px-4 py-3">
							<p
								v-if="application?.notes"
								class="break-words [overflow-wrap:anywhere] whitespace-pre-wrap font-medium text-toned"
							>
								{{ application.notes }}
							</p>
							<p v-else class="text-sm text-muted">{{ t('groups.activities.application.confirmation.no_notes') }}</p>
						</div>
					</div>

					<div class="flex items-center gap-3 border-t border-default pt-2">
						<UButton
							type="button"
							color="neutral"
							variant="outline"
							size="lg"
							:label="t('groups.activities.application.confirmation.back_to_overview')"
							@click="goBack"
						/>
						<UButton
							v-if="confirmation.can_edit"
							type="button"
							color="neutral"
							size="lg"
							icon="i-lucide-pencil-line"
							:label="t('groups.activities.application.confirmation.edit_application')"
							@click="editApplication"
						/>
						<UButton
							v-if="confirmation.can_withdraw"
							type="button"
							color="error"
							variant="soft"
							size="lg"
							icon="i-lucide-trash-2"
							:label="withdrawalActionLabel"
							@click="openWithdrawalModal"
						/>
					</div>
				</div>
			</UCard>
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
