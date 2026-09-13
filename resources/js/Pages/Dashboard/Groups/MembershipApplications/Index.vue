<script setup lang="ts">
import type { MembershipApplicationRecord } from "@/Types/Groups";
import { computed, ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import { route } from "ziggy-js";
import AccessBadge from "@/components/Groups/AccessBadge.vue";
import MembershipApplicationAnswerList from "@/components/Groups/MembershipApplicationAnswerList.vue";
import PageHeader from "@/components/PageHeader.vue";
import ReportButton from '@/components/Shared/Reports/ReportButton.vue';
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";

const props = defineProps<{
	group: {
		id: number
		name: string
		slug: string
		current_user_role: string | null
		permissions: {
			can_review_membership_applications: boolean
		}
	}
	applications: MembershipApplicationRecord[]
}>();

const { locale, t } = useI18n();

const pendingApplications = computed(() => props.applications.filter((application) => application.status === "pending"));
const reviewedApplications = computed(() => props.applications.filter((application) => application.status !== "pending"));
const pendingActionId = ref<number | null>(null);
const declineApplication = ref<MembershipApplicationRecord | null>(null);
const declineForm = useForm({
	review_reason: "",
});

const statusColor = (status: MembershipApplicationRecord["status"]) => {
	if (status === "approved") {
		return "success";
	}

	if (status === "declined") {
		return "error";
	}

	return "warning";
};

const formatDate = (value: string | null) => {
	if (!value) {
		return t("groups.membership_applications.review.not_reviewed");
	}

	return createDateTimeFormatter(locale.value, {
		year: "numeric",
		month: "2-digit",
		day: "2-digit",
		hour: "2-digit",
		minute: "2-digit",
	}).format(new Date(value));
};

const applicantName = (application: MembershipApplicationRecord) => application.user?.name ?? t("groups.membership_applications.review.unknown_user");

const approve = (application: MembershipApplicationRecord) => {
	pendingActionId.value = application.id;

	router.post(route("groups.dashboard.membership-applications.approve", {
		group: props.group.slug,
		application: application.id,
	}), {}, {
		onFinish: () => {
			pendingActionId.value = null;
		},
	});
};

const openDecline = (application: MembershipApplicationRecord) => {
	declineApplication.value = application;
	declineForm.review_reason = "";
	declineForm.clearErrors();
};

const submitDecline = () => {
	if (!declineApplication.value) {
		return;
	}

	declineForm.post(route("groups.dashboard.membership-applications.decline", {
		group: props.group.slug,
		application: declineApplication.value.id,
	}), {
		onSuccess: () => {
			declineApplication.value = null;
		},
	});
};
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('groups.membership_applications.review.title')"
			:subtitle="t('groups.membership_applications.review.subtitle')"
		>
			<AccessBadge :role="group.current_user_role" fallback-role="moderator" />
		</PageHeader>

		<div class="mt-4 space-y-6">
			<section class="space-y-3">
				<div class="flex flex-wrap items-center justify-between gap-3">
					<div>
						<h2 class="text-base font-semibold text-highlighted">
							{{ t("groups.membership_applications.review.pending_title") }}
						</h2>
						<p class="text-sm text-muted">
							{{ t("groups.membership_applications.review.pending_subtitle", { count: pendingApplications.length }) }}
						</p>
					</div>
					<UBadge color="warning" variant="subtle">
						{{ pendingApplications.length }}
					</UBadge>
				</div>

				<UAlert
					v-if="pendingApplications.length === 0"
					color="neutral"
					variant="soft"
					icon="i-lucide-inbox"
					:title="t('groups.membership_applications.review.empty_pending_title')"
					:description="t('groups.membership_applications.review.empty_pending_description')"
				/>

				<div v-else class="space-y-4">
					<UCard
						v-for="application in pendingApplications"
						:key="application.id"
						:ui="{ root: 'rounded-sm', body: 'p-4 sm:p-4' }"
					>
						<div class="space-y-4">
							<div class="flex flex-wrap items-start justify-between gap-3">
								<div class="flex min-w-0 items-center gap-3">
									<UAvatar
										:src="application.user?.avatar_url ?? undefined"
										:alt="applicantName(application)"
										size="lg"
									/>
									<div class="min-w-0">
										<p class="font-semibold text-highlighted break-words [overflow-wrap:anywhere]">
											{{ applicantName(application) }}
										</p>
										<p class="text-sm text-muted">
											{{ t("groups.membership_applications.review.submitted_at", { date: formatDate(application.submitted_at) }) }}
										</p>
										<p v-if="application.user?.primary_character" class="text-sm text-muted">
											{{ application.user.primary_character.name }} - {{ application.user.primary_character.world }}
										</p>
									</div>
								</div>

								<ReportButton :target="{ type: 'membership_application', id: application.id, label: applicantName(application) }" />
								<div class="hidden flex-wrap items-center gap-2 sm:flex">
									<UButton
										color="success"
										variant="solid"
										icon="i-lucide-check"
										:label="t('groups.membership_applications.review.actions.approve')"
										:loading="pendingActionId === application.id"
										@click="approve(application)"
									/>
									<UButton
										color="error"
										variant="outline"
										icon="i-lucide-x"
										:label="t('groups.membership_applications.review.actions.decline')"
										:disabled="pendingActionId === application.id"
										@click="openDecline(application)"
									/>
								</div>
							</div>

							<MembershipApplicationAnswerList
								:fields="application.form_snapshot"
								:answers="application.answers"
							/>

							<div class="flex flex-wrap items-center justify-center gap-2 border-t border-default pt-4 sm:hidden">
								<UButton
									color="success"
									variant="solid"
									icon="i-lucide-check"
									:label="t('groups.membership_applications.review.actions.approve')"
									:loading="pendingActionId === application.id"
									@click="approve(application)"
								/>
								<UButton
									color="error"
									variant="outline"
									icon="i-lucide-x"
									:label="t('groups.membership_applications.review.actions.decline')"
									:disabled="pendingActionId === application.id"
									@click="openDecline(application)"
								/>
							</div>
						</div>
					</UCard>
				</div>
			</section>

			<section class="space-y-3">
				<div>
					<h2 class="text-base font-semibold text-highlighted">
						{{ t("groups.membership_applications.review.reviewed_title") }}
					</h2>
					<p class="text-sm text-muted">
						{{ t("groups.membership_applications.review.reviewed_subtitle") }}
					</p>
				</div>

				<UAlert
					v-if="reviewedApplications.length === 0"
					color="neutral"
					variant="soft"
					icon="i-lucide-history"
					:title="t('groups.membership_applications.review.empty_reviewed_title')"
					:description="t('groups.membership_applications.review.empty_reviewed_description')"
				/>

				<div v-else class="space-y-3">
					<UCard
						v-for="application in reviewedApplications"
						:key="application.id"
						:ui="{ root: 'rounded-sm', body: 'p-4 sm:p-4' }"
					>
						<div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
							<div class="space-y-2">
								<ReportButton :target="{ type: 'membership_application', id: application.id, label: applicantName(application) }" />
								<div class="flex items-center gap-3">
									<UAvatar
										:src="application.user?.avatar_url ?? undefined"
										:alt="applicantName(application)"
									/>
									<div class="min-w-0">
										<p class="font-semibold text-highlighted break-words [overflow-wrap:anywhere]">
											{{ applicantName(application) }}
										</p>
										<UBadge :color="statusColor(application.status)" variant="subtle">
											{{ t(`groups.membership_applications.statuses.${application.status}`) }}
										</UBadge>
									</div>
								</div>
								<p class="text-sm text-muted">
									{{ t("groups.membership_applications.review.reviewed_at", { date: formatDate(application.reviewed_at) }) }}
								</p>
								<p v-if="application.reviewed_by" class="text-sm text-muted">
									{{ t("groups.membership_applications.review.reviewed_by", { user: application.reviewed_by.name }) }}
								</p>
								<p v-if="application.review_reason" class="text-sm text-toned whitespace-pre-wrap break-words [overflow-wrap:anywhere]">
									{{ application.review_reason }}
								</p>
							</div>

							<MembershipApplicationAnswerList
								:fields="application.form_snapshot"
								:answers="application.answers"
							/>
						</div>
					</UCard>
				</div>
			</section>
		</div>

		<UModal
			:open="Boolean(declineApplication)"
			:title="t('groups.membership_applications.review.decline_modal.title')"
			:description="declineApplication ? applicantName(declineApplication) : undefined"
			:ui="{ content: 'rounded-sm' }"
			@update:open="(value) => { if (!value) declineApplication = null }"
		>
			<template #body>
				<form class="space-y-4" @submit.prevent="submitDecline">
					<UFormField
						:label="t('groups.membership_applications.review.decline_modal.reason_label')"
						:help="t('groups.membership_applications.review.decline_modal.reason_help')"
						:error="declineForm.errors.review_reason"
					>
						<UTextarea
							v-model="declineForm.review_reason"
							class="w-full"
							:rows="4"
							:ui="{ base: 'rounded-none' }"
						/>
					</UFormField>

					<div class="flex justify-end gap-2">
						<UButton
							type="button"
							color="neutral"
							variant="outline"
							:label="t('groups.membership_applications.review.actions.cancel')"
							@click="declineApplication = null"
						/>
						<UButton
							type="submit"
							color="error"
							icon="i-lucide-x"
							:label="t('groups.membership_applications.review.actions.decline')"
							:loading="declineForm.processing"
						/>
					</div>
				</form>
			</template>
		</UModal>
	</div>
</template>
