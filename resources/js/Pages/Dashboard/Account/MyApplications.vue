<script setup lang="ts">
import type { AccountApplication } from "@/Types/ActivityCore";
import axios from "axios";
import { computed, ref, watch } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { useToast } from "@nuxt/ui/composables";
import PageHeader from "@/components/PageHeader.vue";
import { localizedValue } from "@/utils/localizedValue";
import { getActivityStatusMeta } from "@/utils/activityStatusMeta";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";

type HistoryMeta = {
	current_page: number
	per_page: number
	total: number
	last_page: number
	from: number | null
	to: number | null
}

const props = defineProps<{
	featuredApplication: AccountApplication | null
	activeApplications: AccountApplication[]
	cancelledApplications: AccountApplication[]
	hasHistoricalApplications: boolean
	historyPerPageOptions: number[]
}>();

const { t, locale } = useI18n();
const page = usePage();
const toast = useToast();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? "en"));
const pendingWithdrawal = ref<AccountApplication | null>(null);
const isWithdrawing = ref(false);
const searchQuery = ref("");
const historyVisible = ref(false);
const historyLoading = ref(false);
const historyApplications = ref<AccountApplication[]>([]);
const historyPerPage = ref(props.historyPerPageOptions[0] ?? 10);
const historyMeta = ref<HistoryMeta>({
	current_page: 1,
	per_page: historyPerPage.value,
	total: 0,
	last_page: 1,
	from: null,
	to: null,
});
const { withDisplayTimeZone } = useTimeDisplayMode();

const historyPerPageItems = computed(() => props.historyPerPageOptions.map((value) => ({
	label: String(value),
	value,
})));

const hasAnyApplications = computed(() => Boolean(
	props.featuredApplication
	|| props.activeApplications.length > 0
	|| props.cancelledApplications.length > 0
	|| props.hasHistoricalApplications,
));

const filteredActiveApplications = computed(() => props.activeApplications.filter(matchesApplicationSearch));
const filteredCancelledApplications = computed(() => props.cancelledApplications.filter(matchesApplicationSearch));

const historyRangeLabel = computed(() => {
	if (historyMeta.value.total === 0 || historyMeta.value.from === null || historyMeta.value.to === null) {
		return t("applications.history.empty_range");
	}

	return t("applications.history.range", {
		from: historyMeta.value.from,
		to: historyMeta.value.to,
		total: historyMeta.value.total,
	});
});

const formatDateTime = (value: string | null, options?: Intl.DateTimeFormatOptions) => {
	if (!value) {
		return t("applications.not_available");
	}

	return createDateTimeFormatter(locale.value, {
		year: "numeric",
		month: "2-digit",
		day: "2-digit",
		hour: "2-digit",
		minute: "2-digit",
		...options,
	}).format(new Date(value));
};

const formatRunTime = (value: string | null) => {
	if (!value) {
		return t("groups.activities.cards.no_time");
	}

	return createDateTimeFormatter(locale.value, withDisplayTimeZone({
		year: "numeric",
		month: "2-digit",
		day: "2-digit",
		hour: "2-digit",
		minute: "2-digit",
		timeZoneName: "short",
	})).format(new Date(value));
};

const formatDuration = (hours: number | null) => {
	if (!hours) {
		return t("groups.activities.management.overview.no_duration");
	}

	return t("groups.activities.management.overview.duration", { count: hours });
};

const applicationStatusMeta = (status: string) => ({
	pending: { color: "warning", label: t("groups.activities.application.confirmation.statuses.pending") },
	approved: { color: "success", label: t("groups.activities.application.confirmation.statuses.approved") },
	on_bench: { color: "info", label: t("groups.activities.application.confirmation.statuses.on_bench") },
	declined: { color: "error", label: t("groups.activities.application.confirmation.statuses.declined") },
	cancelled: { color: "neutral", label: t("groups.activities.application.confirmation.statuses.cancelled") },
	withdrawn: { color: "neutral", label: t("applications.statuses.withdrawn") },
}[status] ?? { color: "neutral", label: status });

const applicationTypeName = (application: AccountApplication) => (
	localizedValue(application.activity.type_name, locale.value, fallbackLocale.value)
	|| t("groups.activities.cards.unknown_type")
);

const applicationTitle = (application: AccountApplication) => (
	application.activity.title || applicationTypeName(application)
);

const targetProgPointLabel = (application: AccountApplication) => (
	localizedValue(application.activity.target_prog_point_label, locale.value, fallbackLocale.value)
	|| application.activity.target_prog_point_key
	|| null
);

const textLabel = (value: string | Record<string, string | null> | null | undefined) => {
	if (!value) {
		return null;
	}

	if (typeof value === "string") {
		return value;
	}

	return localizedValue(value, locale.value, fallbackLocale.value);
};

const assignmentItems = (application: AccountApplication) => {
	const assignment = application.assignment;

	if (!assignment) {
		return [];
	}

	return [
		{
			key: "party",
			label: t("applications.assignment.party"),
			value: textLabel(assignment.group_label) || assignment.group_key,
		},
		{
			key: "slot",
			label: t("applications.assignment.slot"),
			value: textLabel(assignment.slot_label) || assignment.slot_key,
		},
		{
			key: "class",
			label: t("applications.assignment.class"),
			value: assignment.character_class?.name || assignment.character_class?.shorthand || null,
		},
		{
			key: "phantom_job",
			label: t("applications.assignment.phantom_job"),
			value: assignment.phantom_job?.name || null,
		},
		{
			key: "raid_position",
			label: t("applications.assignment.raid_position"),
			value: textLabel(assignment.raid_position?.label) || assignment.raid_position?.key || null,
		},
	].filter((item) => item.value);
};

const notesPreview = (notes: string | null) => {
	if (!notes) {
		return null;
	}

	return notes.length > 180 ? `${notes.slice(0, 180)}...` : notes;
};

function searchableText(application: AccountApplication) {
	return [
		applicationTitle(application),
		applicationTypeName(application),
		targetProgPointLabel(application),
		application.group.name,
		application.status,
		applicationStatusMeta(application.status).label,
		application.character.name,
		application.assignment?.character_class?.name,
		application.assignment?.phantom_job?.name,
		textLabel(application.assignment?.raid_position?.label),
	].filter(Boolean).join(" ").toLowerCase();
}

function matchesApplicationSearch(application: AccountApplication) {
	const query = searchQuery.value.trim().toLowerCase();

	if (!query) {
		return true;
	}

	return searchableText(application).includes(query);
}

const loadHistory = async (pageNumber = 1) => {
	if (historyLoading.value) {
		return;
	}

	historyLoading.value = true;
	historyVisible.value = true;

	try {
		const response = await axios.get(route("account.applications.history"), {
			params: {
				page: pageNumber,
				per_page: historyPerPage.value,
			},
		});

		historyApplications.value = response.data.data ?? [];
		historyMeta.value = {
			current_page: response.data.meta?.current_page ?? pageNumber,
			per_page: response.data.meta?.per_page ?? historyPerPage.value,
			total: response.data.meta?.total ?? 0,
			last_page: response.data.meta?.last_page ?? 1,
			from: response.data.meta?.from ?? null,
			to: response.data.meta?.to ?? null,
		};
	} catch {
		toast.add({
			title: t("applications.history.error_title"),
			description: t("applications.history.error_description"),
			color: "error",
		});
	} finally {
		historyLoading.value = false;
	}
};

watch(historyPerPage, () => {
	if (historyVisible.value) {
		void loadHistory(1);
	}
});

const editApplication = (application: AccountApplication) => {
	if (!application.activity.id || !application.group.slug) {
		return;
	}

	router.get(route("groups.activities.application", {
		group: application.group.slug,
		activity: application.activity.id,
	}));
};

const canOpenOverview = (application: AccountApplication) => (
	Boolean(application.activity.id && application.group.slug)
);

const openOverview = (application: AccountApplication) => {
	if (!application.activity.id || !application.group.slug) {
		return;
	}

	router.get(route("groups.activities.overview", {
		group: application.group.slug,
		activity: application.activity.id,
	}));
};

const confirmWithdrawal = (application: AccountApplication) => {
	pendingWithdrawal.value = application;
};

const withdrawalActionLabel = (application: AccountApplication) => (
	application.is_rostered
		? t("applications.withdraw.action_run")
		: t("applications.withdraw.action_application")
);

const withdrawalConfirmDescription = computed(() => {
	if (!pendingWithdrawal.value) {
		return t("applications.withdraw.confirm_description_application");
	}

	return pendingWithdrawal.value.is_rostered
		? t("applications.withdraw.confirm_description_run")
		: t("applications.withdraw.confirm_description_application");
});

const closeWithdrawalModal = () => {
	if (isWithdrawing.value) {
		return;
	}

	pendingWithdrawal.value = null;
};

const withdrawApplication = () => {
	if (!pendingWithdrawal.value || isWithdrawing.value) {
		return;
	}

	isWithdrawing.value = true;

	router.delete(route("account.applications.destroy", {
		application: pendingWithdrawal.value.id,
	}), {
		onSuccess: () => {
			toast.add({
				title: t("applications.withdraw.success_title"),
				description: t("applications.withdraw.success_description"),
				color: "success",
			});
			pendingWithdrawal.value = null;
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
		},
	});
};
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('applications.title')"
			:subtitle="t('applications.subtitle')"
		/>

		<div v-if="!hasAnyApplications" class="mt-2">
			<UCard class="dark:bg-elevated/25">
				<UAlert
					color="neutral"
					variant="soft"
					icon="i-lucide-file-text"
					:title="t('applications.empty_title')"
					:description="t('applications.empty_description')"
				/>
			</UCard>
		</div>

		<div v-else class="mt-2 flex flex-col gap-8">
			<section v-if="featuredApplication" class="space-y-4">
				<div class="space-y-1">
					<p class="text-xs font-semibold uppercase tracking-wide text-primary">{{ t('applications.sections.next_title') }}</p>
					<h2 class="text-2xl font-semibold text-toned">{{ applicationTitle(featuredApplication) }}</h2>
					<p class="text-sm text-muted">{{ t('applications.sections.next_description') }}</p>
				</div>

				<UCard class="overflow-hidden dark:bg-elevated/25">
					<div class="flex flex-col gap-6">
						<div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
							<div class="min-w-0 space-y-3">
								<div class="flex flex-wrap items-center gap-2">
									<UBadge
										:color="applicationStatusMeta(featuredApplication.status).color"
										variant="soft"
										:label="applicationStatusMeta(featuredApplication.status).label"
									/>
									<UBadge
										v-if="featuredApplication.activity.status"
										:color="getActivityStatusMeta(featuredApplication.activity.status).color"
										variant="subtle"
										:icon="getActivityStatusMeta(featuredApplication.activity.status).icon"
										:label="t(`groups.activities.statuses.${featuredApplication.activity.status}`)"
									/>
									<UBadge
										v-if="targetProgPointLabel(featuredApplication)"
										color="neutral"
										size="md"
										variant="outline"
										:label="targetProgPointLabel(featuredApplication)"
									/>
								</div>

								<p class="max-w-3xl break-words [overflow-wrap:anywhere] text-sm text-muted">
									{{ featuredApplication.activity.description || t('applications.summary_fallback') }}
								</p>
							</div>

							<div
								v-if="canOpenOverview(featuredApplication) || featuredApplication.can_edit || featuredApplication.can_withdraw"
								class="flex flex-wrap items-center gap-2"
							>
								<UButton
									v-if="canOpenOverview(featuredApplication)"
									type="button"
									color="primary"
									variant="soft"
									icon="i-lucide-arrow-up-right"
									:label="t('applications.view_run')"
									@click="openOverview(featuredApplication)"
								/>
								<UButton
									v-if="featuredApplication.can_edit"
									type="button"
									color="neutral"
									variant="outline"
									icon="i-lucide-pencil-line"
									:label="t('applications.edit')"
									@click="editApplication(featuredApplication)"
								/>
								<UButton
									v-if="featuredApplication.can_withdraw"
									type="button"
									color="error"
									variant="soft"
									icon="i-lucide-trash-2"
									:label="withdrawalActionLabel(featuredApplication)"
									@click="confirmWithdrawal(featuredApplication)"
								/>
							</div>
						</div>

						<div class="grid gap-3 lg:grid-cols-4">
							<div class="rounded-sm border border-default bg-default/70 px-4 py-3">
								<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.group') }}</p>
								<p class="mt-2 font-medium text-toned">{{ featuredApplication.group.name || t('applications.unknown_group') }}</p>
							</div>
							<div class="rounded-sm border border-default bg-default/70 px-4 py-3">
								<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.run_time') }}</p>
								<p class="mt-2 font-medium text-toned">{{ formatRunTime(featuredApplication.activity.starts_at) }}</p>
								<p class="text-sm text-muted">{{ formatDuration(featuredApplication.activity.duration_hours) }}</p>
							</div>
							<div class="rounded-sm border border-default bg-default/70 px-4 py-3">
								<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.activity_type') }}</p>
								<p class="mt-2 font-medium text-toned">{{ applicationTypeName(featuredApplication) }}</p>
							</div>
							<div class="rounded-sm border border-default bg-default/70 px-4 py-3">
								<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.character') }}</p>
								<div class="mt-2 flex items-center gap-3">
									<img
										v-if="featuredApplication.character.avatar_url"
										:src="featuredApplication.character.avatar_url"
										:alt="featuredApplication.character.name || t('applications.unknown_character')"
										class="h-10 w-10 rounded-sm border border-default object-cover object-center"
									>
									<div v-else class="flex h-10 w-10 items-center justify-center rounded-sm border border-default bg-muted/30">
										<UIcon name="i-lucide-user-round" class="size-5 text-muted" />
									</div>
									<div class="min-w-0">
										<p class="truncate font-medium text-toned">{{ featuredApplication.character.name || t('applications.unknown_character') }}</p>
										<p class="truncate text-sm text-muted">
											{{ featuredApplication.character.world || t('applications.not_available') }}<span v-if="featuredApplication.character.datacenter"> - {{ featuredApplication.character.datacenter }}</span>
										</p>
									</div>
								</div>
							</div>
						</div>

						<div
							v-if="featuredApplication.assignment"
							class="rounded-sm border border-primary/30 bg-primary/5 px-4 py-3"
						>
							<div class="flex items-center gap-2">
								<UIcon name="i-lucide-layout-panel-top" class="size-4 text-primary" />
								<p class="text-sm font-semibold text-toned">{{ t('applications.assignment.title') }}</p>
							</div>
							<div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
								<div
									v-for="item in assignmentItems(featuredApplication)"
									:key="item.key"
									class="min-w-0"
								>
									<p class="text-xs uppercase tracking-wide text-muted">{{ item.label }}</p>
									<p class="mt-1 truncate font-medium text-toned">{{ item.value }}</p>
								</div>
							</div>
						</div>

						<div v-if="notesPreview(featuredApplication.notes)" class="rounded-sm border border-default bg-default/70 px-4 py-3">
							<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.notes') }}</p>
							<p class="mt-3 break-words [overflow-wrap:anywhere] whitespace-pre-wrap text-sm text-toned">
								{{ notesPreview(featuredApplication.notes) }}
							</p>
						</div>
					</div>
				</UCard>
			</section>

			<section class="space-y-4">
				<div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
					<div class="space-y-1">
						<h2 class="text-xl font-semibold text-toned">{{ t('applications.sections.active_title') }}</h2>
						<p class="text-sm text-muted">{{ t('applications.sections.active_description') }}</p>
					</div>
					<UInput
						v-model="searchQuery"
						icon="i-lucide-search"
						class="lg:w-96"
						:placeholder="t('applications.search_placeholder')"
					/>
				</div>

				<UAlert
					v-if="activeApplications.length === 0 && !featuredApplication"
					color="neutral"
					variant="soft"
					icon="i-lucide-inbox"
					:title="t('applications.sections.active_empty_title')"
					:description="t('applications.sections.active_empty_description')"
				/>

				<UAlert
					v-else-if="activeApplications.length > 0 && filteredActiveApplications.length === 0"
					color="neutral"
					variant="soft"
					icon="i-lucide-search-x"
					:title="t('applications.search_empty_title')"
					:description="t('applications.search_empty_description')"
				/>

				<div v-else-if="filteredActiveApplications.length > 0" class="grid gap-4 xl:grid-cols-2">
					<UCard
						v-for="application in filteredActiveApplications"
						:key="application.id"
						class="dark:bg-elevated/25"
					>
						<div class="flex h-full flex-col gap-5">
							<div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
								<div class="min-w-0 space-y-2">
									<div class="flex flex-wrap items-center gap-2">
										<UBadge
											:color="applicationStatusMeta(application.status).color"
											variant="soft"
											:label="applicationStatusMeta(application.status).label"
										/>
										<UBadge
											v-if="targetProgPointLabel(application)"
											color="neutral"
											size="md"
											variant="outline"
											:label="targetProgPointLabel(application)"
										/>
									</div>
									<div>
										<h3 class="break-words [overflow-wrap:anywhere] text-lg font-semibold text-toned">
											{{ applicationTitle(application) }}
										</h3>
										<p class="text-sm text-muted">{{ application.group.name || t('applications.unknown_group') }}</p>
									</div>
								</div>
								<div class="flex flex-wrap items-center gap-2">
									<UButton
										v-if="canOpenOverview(application)"
										type="button"
										color="neutral"
										variant="ghost"
										icon="i-lucide-arrow-up-right"
										:label="t('applications.view_run')"
										@click="openOverview(application)"
									/>
									<UButton
										v-if="application.can_edit"
										type="button"
										color="neutral"
										variant="outline"
										icon="i-lucide-pencil-line"
										:label="t('applications.edit')"
										@click="editApplication(application)"
									/>
									<UButton
										v-if="application.can_withdraw"
										type="button"
										color="error"
										variant="soft"
										icon="i-lucide-trash-2"
										:label="withdrawalActionLabel(application)"
										@click="confirmWithdrawal(application)"
									/>
								</div>
							</div>

							<div class="grid gap-3 md:grid-cols-3">
								<div class="rounded-sm border border-default bg-default px-4 py-3">
									<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.run_time') }}</p>
									<p class="mt-2 font-medium text-toned">{{ formatRunTime(application.activity.starts_at) }}</p>
									<p class="text-sm text-muted">{{ formatDuration(application.activity.duration_hours) }}</p>
								</div>
								<div class="rounded-sm border border-default bg-default px-4 py-3">
									<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.activity_type') }}</p>
									<p class="mt-2 font-medium text-toned">{{ applicationTypeName(application) }}</p>
								</div>
								<div class="rounded-sm border border-default bg-default px-4 py-3">
									<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.character') }}</p>
									<p class="mt-2 truncate font-medium text-toned">{{ application.character.name || t('applications.unknown_character') }}</p>
									<p class="truncate text-sm text-muted">{{ application.character.world || t('applications.not_available') }}</p>
								</div>
							</div>

							<div
								v-if="application.assignment"
								class="rounded-sm border border-primary/30 bg-primary/5 px-4 py-3"
							>
								<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.assignment.title') }}</p>
								<div class="mt-3 grid gap-3 sm:grid-cols-2">
									<div
										v-for="item in assignmentItems(application)"
										:key="item.key"
										class="min-w-0"
									>
										<p class="text-xs uppercase tracking-wide text-muted">{{ item.label }}</p>
										<p class="mt-1 truncate font-medium text-toned">{{ item.value }}</p>
									</div>
								</div>
							</div>
						</div>
					</UCard>
				</div>
			</section>

			<section v-if="cancelledApplications.length > 0" class="space-y-4">
				<div class="space-y-1">
					<h2 class="text-xl font-semibold text-toned">{{ t('applications.sections.cancelled_title') }}</h2>
					<p class="text-sm text-muted">{{ t('applications.sections.cancelled_description') }}</p>
				</div>

				<UAlert
					v-if="filteredCancelledApplications.length === 0"
					color="neutral"
					variant="soft"
					icon="i-lucide-search-x"
					:title="t('applications.search_empty_title')"
					:description="t('applications.search_empty_description')"
				/>

				<div v-else class="grid gap-4 xl:grid-cols-2">
					<UCard
						v-for="application in filteredCancelledApplications"
						:key="application.id"
						class="dark:bg-elevated/25"
					>
						<div class="flex flex-col gap-4">
							<div class="flex flex-wrap items-center gap-2">
								<UBadge
									:color="applicationStatusMeta(application.status).color"
									variant="soft"
									:label="applicationStatusMeta(application.status).label"
								/>
								<UBadge
									color="neutral"
									variant="outline"
									:label="application.group.name || t('applications.unknown_group')"
								/>
							</div>
							<div class="min-w-0">
								<h3 class="break-words [overflow-wrap:anywhere] text-lg font-semibold text-toned">{{ applicationTitle(application) }}</h3>
								<p class="text-sm text-muted">{{ formatRunTime(application.activity.starts_at) }}</p>
							</div>
							<p
								v-if="application.review_reason"
								class="break-words [overflow-wrap:anywhere] whitespace-pre-wrap text-sm text-muted"
							>
								{{ application.review_reason }}
							</p>
						</div>
					</UCard>
				</div>
			</section>

			<section class="space-y-4">
				<div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
					<div class="space-y-1">
						<h2 class="text-xl font-semibold text-toned">{{ t('applications.sections.history_title') }}</h2>
						<p class="text-sm text-muted">{{ t('applications.sections.history_description') }}</p>
					</div>

					<div v-if="historyVisible" class="flex flex-wrap items-center gap-2">
						<span class="text-sm text-muted">{{ t('applications.history.per_page') }}</span>
						<USelect
							v-model="historyPerPage"
							:items="historyPerPageItems"
							value-key="value"
							class="w-24"
						/>
					</div>
				</div>

				<UCard v-if="!historyVisible" class="dark:bg-elevated/25">
					<div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
						<div class="space-y-1">
							<p class="font-medium text-toned">{{ t('applications.history.collapsed_title') }}</p>
							<p class="text-sm text-muted">{{ t('applications.history.collapsed_description') }}</p>
						</div>
						<UButton
							type="button"
							color="neutral"
							variant="outline"
							icon="i-lucide-history"
							:label="t('applications.history.view_button')"
							:loading="historyLoading"
							@click="loadHistory(1)"
						/>
					</div>
				</UCard>

				<div v-else class="space-y-4">
					<USkeleton v-if="historyLoading && historyApplications.length === 0" class="h-32 w-full" />

					<UAlert
						v-else-if="historyApplications.length === 0"
						color="neutral"
						variant="soft"
						icon="i-lucide-history"
						:title="t('applications.sections.history_empty_title')"
						:description="t('applications.sections.history_empty_description')"
					/>

					<div v-else class="space-y-3">
						<UCard
							v-for="application in historyApplications"
							:key="application.id"
							class="dark:bg-elevated/25"
						>
							<div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
								<div class="min-w-0 space-y-2">
									<div class="flex flex-wrap items-center gap-2">
										<UBadge
											:color="applicationStatusMeta(application.status).color"
											variant="soft"
											:label="applicationStatusMeta(application.status).label"
										/>
										<UBadge
											v-if="application.activity.status"
											:color="getActivityStatusMeta(application.activity.status).color"
											variant="subtle"
											:icon="getActivityStatusMeta(application.activity.status).icon"
											:label="t(`groups.activities.statuses.${application.activity.status}`)"
										/>
										<UBadge
											color="neutral"
											variant="outline"
											:label="application.group.name || t('applications.unknown_group')"
										/>
									</div>
									<h3 class="break-words [overflow-wrap:anywhere] font-semibold text-toned">{{ applicationTitle(application) }}</h3>
									<p class="text-sm text-muted">{{ formatRunTime(application.activity.starts_at) }}</p>
									<p
										v-if="application.review_reason"
										class="break-words [overflow-wrap:anywhere] whitespace-pre-wrap text-sm text-muted"
									>
										{{ application.review_reason }}
									</p>
								</div>
								<UButton
									v-if="canOpenOverview(application)"
									type="button"
									color="neutral"
									variant="ghost"
									icon="i-lucide-arrow-up-right"
									:label="t('applications.view_run')"
									@click="openOverview(application)"
								/>
							</div>
						</UCard>
					</div>

					<div class="flex flex-col gap-3 border-t border-default pt-4 md:flex-row md:items-center md:justify-between">
						<p class="text-sm text-muted">{{ historyRangeLabel }}</p>
						<UPagination
							:page="historyMeta.current_page"
							:items-per-page="historyMeta.per_page"
							:total="historyMeta.total"
							@update:page="(pageNumber) => loadHistory(pageNumber)"
						/>
					</div>
				</div>
			</section>
		</div>

		<UModal
			:open="pendingWithdrawal !== null"
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

					<div
						v-if="pendingWithdrawal"
						class="rounded-sm border border-default bg-default px-4 py-3"
					>
						<p class="font-medium text-toned">
							{{ applicationTitle(pendingWithdrawal) }}
						</p>
						<p class="mt-1 text-sm text-muted">
							{{ pendingWithdrawal.group.name || t('applications.unknown_group') }}
						</p>
					</div>
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
						:label="pendingWithdrawal ? withdrawalActionLabel(pendingWithdrawal) : t('applications.withdraw.action_application')"
						:loading="isWithdrawing"
						@click="withdrawApplication"
					/>
				</div>
			</template>
		</UModal>
	</div>
</template>
