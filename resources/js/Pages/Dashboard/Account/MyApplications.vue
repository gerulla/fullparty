<script setup lang="ts">
import { computed, ref } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { useToast } from "@nuxt/ui/composables";
import PageHeader from "@/components/PageHeader.vue";
import { localizedValue } from "@/utils/localizedValue";
import { getActivityStatusMeta } from "@/utils/activityStatusMeta";

type LocalizedText = Record<string, string | null | undefined> | null | undefined;

type AccountApplication = {
	id: number
	status: string
	submitted_at: string | null
	reviewed_at: string | null
	review_reason: string | null
	notes: string | null
	can_edit: boolean
	can_cancel: boolean
	group: {
		name: string | null
		slug: string | null
	}
	activity: {
		id: number | null
		title: string | null
		description: string | null
		status: string | null
		starts_at: string | null
		duration_hours: number | null
		is_public: boolean
		secret_key: string | null
		type_name: LocalizedText
	}
	character: {
		name: string | null
		world: string | null
		datacenter: string | null
		avatar_url: string | null
	}
}

const props = defineProps<{
	activeApplications: AccountApplication[]
	historicalApplications: AccountApplication[]
}>();

const { t, locale } = useI18n();
const page = usePage();
const toast = useToast();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? "en"));
const pendingWithdrawal = ref<AccountApplication | null>(null);
const isWithdrawing = ref(false);
const hasAnyApplications = computed(() => props.activeApplications.length > 0 || props.historicalApplications.length > 0);

const formatDateTime = (value: string | null, options?: Intl.DateTimeFormatOptions) => {
	if (!value) {
		return t("applications.not_available");
	}

	return new Intl.DateTimeFormat(locale.value, {
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

	return new Intl.DateTimeFormat(locale.value, {
		year: "numeric",
		month: "2-digit",
		day: "2-digit",
		hour: "2-digit",
		minute: "2-digit",
		timeZone: "UTC",
		timeZoneName: "short",
	}).format(new Date(value));
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

const notesPreview = (notes: string | null) => {
	if (!notes) {
		return null;
	}

	return notes.length > 180 ? `${notes.slice(0, 180)}...` : notes;
};

const editApplication = (application: AccountApplication) => {
	if (!application.activity.id || !application.group.slug) {
		return;
	}

	router.get(route("groups.activities.application", {
		group: application.group.slug,
		activity: application.activity.id,
		secretKey: application.activity.secret_key || undefined,
	}));
};

const confirmWithdrawal = (application: AccountApplication) => {
	pendingWithdrawal.value = application;
};

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
		preserveScroll: true,
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
			<section class="space-y-4">
				<div class="space-y-1">
					<h2 class="text-xl font-semibold text-toned">{{ t('applications.sections.active_title') }}</h2>
					<p class="text-sm text-muted">{{ t('applications.sections.active_description') }}</p>
				</div>

				<UAlert
					v-if="activeApplications.length === 0"
					color="neutral"
					variant="soft"
					icon="i-lucide-inbox"
					:title="t('applications.sections.active_empty_title')"
					:description="t('applications.sections.active_empty_description')"
				/>

				<UCard
					v-for="application in activeApplications"
					:key="application.id"
					class="dark:bg-elevated/25"
				>
					<div class="flex flex-col gap-5">
						<div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
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

								<div class="space-y-1">
									<h3 class="truncate text-xl font-semibold text-toned">
										{{ applicationTitle(application) }}
									</h3>
									<p class="text-sm text-muted">
										{{ application.activity.description || t('applications.summary_fallback') }}
									</p>
								</div>
							</div>

							<div
								v-if="application.can_edit || application.can_cancel"
								class="flex flex-wrap items-center gap-2"
							>
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
									v-if="application.can_cancel"
									type="button"
									color="error"
									variant="soft"
									icon="i-lucide-trash-2"
									:label="t('applications.cancel')"
									@click="confirmWithdrawal(application)"
								/>
							</div>
						</div>

						<div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
							<div class="rounded-sm border border-default bg-default px-4 py-3">
								<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.character') }}</p>
								<div class="mt-3 flex items-center gap-3">
									<img
										v-if="application.character.avatar_url"
										:src="application.character.avatar_url"
										:alt="application.character.name || t('applications.unknown_character')"
										class="h-11 w-11 rounded-sm border border-default object-cover object-center"
									>
									<div v-else class="flex h-11 w-11 items-center justify-center rounded-sm border border-default bg-muted/30">
										<UIcon name="i-lucide-user-round" class="size-5 text-muted" />
									</div>
									<div class="min-w-0">
										<p class="truncate font-medium text-toned">{{ application.character.name || t('applications.unknown_character') }}</p>
										<p class="truncate text-sm text-muted">
											{{ application.character.world || t('applications.not_available') }}<span v-if="application.character.datacenter"> - {{ application.character.datacenter }}</span>
										</p>
									</div>
								</div>
							</div>

							<div class="rounded-sm border border-default bg-default px-4 py-3">
								<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.run_time') }}</p>
								<div class="mt-3 space-y-1">
									<p class="font-medium text-toned">{{ formatRunTime(application.activity.starts_at) }}</p>
									<p class="text-sm text-muted">{{ formatDuration(application.activity.duration_hours) }}</p>
								</div>
							</div>

							<div class="rounded-sm border border-default bg-default px-4 py-3">
								<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.submitted') }}</p>
								<div class="mt-3 space-y-1">
									<p class="font-medium text-toned">{{ formatDateTime(application.submitted_at) }}</p>
									<p class="text-sm text-muted">
										{{ application.can_edit ? t('applications.editable') : t('applications.locked') }}
									</p>
								</div>
							</div>

							<div class="rounded-sm border border-default bg-default px-4 py-3">
								<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.activity_type') }}</p>
								<div class="mt-3 space-y-1">
									<p class="font-medium text-toned">{{ applicationTypeName(application) }}</p>
									<p class="text-sm text-muted">{{ application.group.name || t('applications.unknown_group') }}</p>
								</div>
							</div>
						</div>

						<div class="rounded-sm border border-default bg-default px-4 py-3">
							<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.notes') }}</p>
							<p class="mt-3 whitespace-pre-wrap text-sm text-toned">
								{{ notesPreview(application.notes) || t('applications.no_notes') }}
							</p>
						</div>

						<div
							v-if="application.review_reason"
							class="rounded-sm border border-default bg-default px-4 py-3"
						>
							<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.review_reason') }}</p>
							<p class="mt-3 whitespace-pre-wrap text-sm text-toned">
								{{ application.review_reason }}
							</p>
						</div>
					</div>
				</UCard>
			</section>

			<section class="space-y-4">
				<div class="space-y-1">
					<h2 class="text-xl font-semibold text-toned">{{ t('applications.sections.history_title') }}</h2>
					<p class="text-sm text-muted">{{ t('applications.sections.history_description') }}</p>
				</div>

				<UAlert
					v-if="historicalApplications.length === 0"
					color="neutral"
					variant="soft"
					icon="i-lucide-history"
					:title="t('applications.sections.history_empty_title')"
					:description="t('applications.sections.history_empty_description')"
				/>

				<UCard
					v-for="application in historicalApplications"
					:key="application.id"
					class="dark:bg-elevated/25"
				>
					<div class="flex flex-col gap-5">
						<div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
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

								<div class="space-y-1">
									<h3 class="truncate text-xl font-semibold text-toned">
										{{ applicationTitle(application) }}
									</h3>
									<p class="text-sm text-muted">
										{{ application.activity.description || t('applications.summary_fallback') }}
									</p>
								</div>
							</div>

							<div
								v-if="application.can_edit || application.can_cancel"
								class="flex flex-wrap items-center gap-2"
							>
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
									v-if="application.can_cancel"
									type="button"
									color="error"
									variant="soft"
									icon="i-lucide-trash-2"
									:label="t('applications.cancel')"
									@click="confirmWithdrawal(application)"
								/>
							</div>
						</div>

						<div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
							<div class="rounded-sm border border-default bg-default px-4 py-3">
								<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.character') }}</p>
								<div class="mt-3 flex items-center gap-3">
									<img
										v-if="application.character.avatar_url"
										:src="application.character.avatar_url"
										:alt="application.character.name || t('applications.unknown_character')"
										class="h-11 w-11 rounded-sm border border-default object-cover object-center"
									>
									<div v-else class="flex h-11 w-11 items-center justify-center rounded-sm border border-default bg-muted/30">
										<UIcon name="i-lucide-user-round" class="size-5 text-muted" />
									</div>
									<div class="min-w-0">
										<p class="truncate font-medium text-toned">{{ application.character.name || t('applications.unknown_character') }}</p>
										<p class="truncate text-sm text-muted">
											{{ application.character.world || t('applications.not_available') }}<span v-if="application.character.datacenter"> - {{ application.character.datacenter }}</span>
										</p>
									</div>
								</div>
							</div>

							<div class="rounded-sm border border-default bg-default px-4 py-3">
								<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.run_time') }}</p>
								<div class="mt-3 space-y-1">
									<p class="font-medium text-toned">{{ formatRunTime(application.activity.starts_at) }}</p>
									<p class="text-sm text-muted">{{ formatDuration(application.activity.duration_hours) }}</p>
								</div>
							</div>

							<div class="rounded-sm border border-default bg-default px-4 py-3">
								<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.submitted') }}</p>
								<div class="mt-3 space-y-1">
									<p class="font-medium text-toned">{{ formatDateTime(application.submitted_at) }}</p>
									<p class="text-sm text-muted">{{ t('applications.locked') }}</p>
								</div>
							</div>

							<div class="rounded-sm border border-default bg-default px-4 py-3">
								<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.activity_type') }}</p>
								<div class="mt-3 space-y-1">
									<p class="font-medium text-toned">{{ applicationTypeName(application) }}</p>
									<p class="text-sm text-muted">{{ application.group.name || t('applications.unknown_group') }}</p>
								</div>
							</div>
						</div>

						<div class="rounded-sm border border-default bg-default px-4 py-3">
							<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.notes') }}</p>
							<p class="mt-3 whitespace-pre-wrap text-sm text-toned">
								{{ notesPreview(application.notes) || t('applications.no_notes') }}
							</p>
						</div>

						<div
							v-if="application.review_reason"
							class="rounded-sm border border-default bg-default px-4 py-3"
						>
							<p class="text-xs uppercase tracking-wide text-muted">{{ t('applications.review_reason') }}</p>
							<p class="mt-3 whitespace-pre-wrap text-sm text-toned">
								{{ application.review_reason }}
							</p>
						</div>

						<p class="text-sm text-muted">
							{{ t('applications.locked_help') }}
						</p>
					</div>
				</UCard>
			</section>
		</div>

		<UModal
			:open="pendingWithdrawal !== null"
			:title="t('applications.withdraw.title')"
			:description="t('applications.withdraw.description')"
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
						:label="t('applications.withdraw.confirm')"
						:loading="isWithdrawing"
						@click="withdrawApplication"
					/>
				</div>
			</template>
		</UModal>
	</div>
</template>
