<script setup lang="ts">
import axios from "axios";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "@nuxt/ui/composables";
import { route } from "ziggy-js";
import ApplicantInspectorLayout from "@/components/Groups/Activities/ApplicantInspectorLayout.vue";
import ApplicantApplicationPanel from "@/components/Groups/Activities/ApplicantApplicationPanel.vue";
import ApplicantNotesPanel from "@/components/Groups/Activities/ApplicantNotesPanel.vue";
import { useApplicantNotes } from "@/composables/useApplicantNotes";
import ActivityCharacterFflogsProgress from "@/components/Groups/Activities/ActivityCharacterFflogsProgress.vue";
import ApplicantUserStats from "@/components/Groups/Activities/ApplicantUserStats.vue";
import type { QueueApplication } from "@/Types/ActivityQueue";
import { activityTextLimits } from "@/utils/activityTextLimits";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import { formatRelativeTime } from "@/utils/formatRelativeTime";
import { useMinuteTicker } from "@/composables/useMinuteTicker";

const CHARACTER_REFRESH_COOLDOWN_MS = 5 * 60 * 1000;

const props = defineProps<{
	groupSlug: string
	activityId: number
	fflogsZoneId: number | null
	application: QueueApplication | null
}>();
const emit = defineEmits<{
	declined: [applicationId: number]
	refreshed: [application: QueueApplication]
}>();

const isOpen = defineModel<boolean>('open', { required: true });
const canFetchPanelData = ref(false);
const section = ref('application');
const isDeclineModalOpen = ref(false);
const declineReason = ref('');
const isDeclining = ref(false);
const isRefreshingCharacter = ref(false);

const { t, locale } = useI18n();
const toast = useToast();
const nowMs = useMinuteTicker();
const applicantCharacter = computed(() => {
	if (!props.application) {
		return null;
	}

	return props.application.is_guest
		? props.application.applicant_character
		: props.application.selected_character
		? {
			name: props.application.selected_character.name,
			avatar_url: props.application.selected_character.avatar_url,
			world: props.application.selected_character.world,
			datacenter: props.application.selected_character.datacenter,
		}
		: props.application.applicant_character;
});

const displayName = computed(() => (
	applicantCharacter.value?.name
	|| props.application?.user?.name
	|| t('groups.activities.management.queue.unknown_applicant')
));

const avatarUrl = computed(() => (
	applicantCharacter.value?.avatar_url
	|| props.application?.user?.avatar_url
	|| undefined
));

const description = computed(() => {
	if (!props.application) {
		return '';
	}

	const parts = [
		props.application.is_guest ? t('groups.activities.management.queue.guest_badge') : props.application.user?.name || null,
		applicantCharacter.value?.world || null,
	];

	return parts.filter(Boolean).join(' • ');
});

const submittedAtLabel = computed(() => {
	if (!props.application?.submitted_at) {
		return t('groups.activities.management.queue.no_submission_time');
	}

	return createDateTimeFormatter(locale.value, {
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
	}).format(new Date(props.application.submitted_at));
});

const editedAtLabel = computed(() => {
	if (!props.application?.edited_at) {
		return null;
	}

	return createDateTimeFormatter(locale.value, {
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
	}).format(new Date(props.application.edited_at));
});

const phantomAnswer = computed(() => props.application?.answers.find((answer) => answer.source === 'phantom_jobs') ?? null);
const shouldShowOccultLevel = computed(() => phantomAnswer.value !== null && props.application?.selected_character?.occult_level !== null && props.application?.selected_character?.occult_level !== undefined);
const shouldShowPhantomMastery = computed(() => phantomAnswer.value !== null && props.application?.selected_character?.phantom_mastery !== null && props.application?.selected_character?.phantom_mastery !== undefined);
const selectedCharacterLastCheckedAt = computed(() => props.application?.selected_character?.lodestone_last_checked_at ?? null);
const selectedCharacterRefreshAvailableAtMs = computed(() => {
	if (!selectedCharacterLastCheckedAt.value) {
		return null;
	}

	return new Date(selectedCharacterLastCheckedAt.value).getTime() + CHARACTER_REFRESH_COOLDOWN_MS;
});
const selectedCharacterRefreshAvailableAtLabel = computed(() => {
	if (!selectedCharacterRefreshAvailableAtMs.value) {
		return '';
	}

	return formatRelativeTime(
		new Date(selectedCharacterRefreshAvailableAtMs.value).toISOString(),
		locale.value,
		t('notifications.ui.just_now'),
		'',
		nowMs.value,
	);
});
const selectedCharacterLastCheckedLabel = computed(() => formatRelativeTime(
	selectedCharacterLastCheckedAt.value,
	locale.value,
	t('notifications.ui.just_now'),
	t('groups.activities.management.queue.modal.character_not_checked'),
	nowMs.value,
));
const canRefreshSelectedCharacter = computed(() => Boolean(props.application?.selected_character?.id)
	&& !isRefreshingCharacter.value
	&& (
		selectedCharacterRefreshAvailableAtMs.value === null
		|| selectedCharacterRefreshAvailableAtMs.value <= nowMs.value
	));
const selectedCharacterRefreshTitle = computed(() => (
	canRefreshSelectedCharacter.value
		? t('groups.activities.management.queue.modal.refresh_character')
		: t('groups.activities.management.queue.modal.character_refresh_available', {
			time: selectedCharacterRefreshAvailableAtLabel.value,
		})
));
const userStatsEmptyMessage = computed(() => (
	props.application?.is_guest
		? t('groups.activities.management.queue.modal.no_user_stats_guest')
		: t('groups.activities.management.queue.modal.no_user_stats')
));
const canDeclineApplication = computed(() => props.application?.status === 'pending');
const declineReasonValue = computed(() => {
	const value = declineReason.value.trim();

	return value === '' ? null : value;
});

const applicantNotes = useApplicantNotes(() => ({
    groupSlug: props.groupSlug,
    activityId: props.activityId,
    applicationId: props.application?.id ?? null,
    enabled: isOpen.value && section.value === 'notes' && Boolean(props.application?.user?.note_summary?.can_view),
}));
const notesSummary = computed(() => applicantNotes.notes.value ?? props.application?.user?.note_summary);
const notesCount = computed(() => {
    const summary = notesSummary.value;
    return summary?.can_view ? summary.current_group_count + summary.shared_count : 0;
});
const characterFacts = computed(() => [
    { label: t('groups.activities.management.queue.modal.character'), value: applicantCharacter.value?.name || '-' },
    { label: t('groups.activities.management.queue.modal.account'), value: props.application?.user?.name || t('groups.activities.management.queue.modal.guest_account') },
    { label: t('groups.activities.management.queue.modal.world'), value: applicantCharacter.value?.world || '-' },
    { label: t('groups.activities.management.queue.modal.datacenter'), value: applicantCharacter.value?.datacenter || '-' },
    { label: t('groups.activities.management.queue.modal.submitted'), value: submittedAtLabel.value },
    ...(editedAtLabel.value ? [{ label: t('groups.activities.management.queue.modal.edited'), value: editedAtLabel.value }] : []),
    ...(shouldShowOccultLevel.value ? [{ label: t('groups.activities.management.queue.modal.occult_level'), value: props.application?.selected_character?.occult_level }] : []),
    ...(shouldShowPhantomMastery.value ? [{ label: t('groups.activities.management.queue.modal.phantom_mastery'), value: props.application?.selected_character?.phantom_mastery }] : []),
]);

const handleAfterEnter = () => {
	canFetchPanelData.value = true;
};

const handleAfterLeave = () => {
	canFetchPanelData.value = false;
};

const openDeclineModal = () => {
	if (!canDeclineApplication.value || isDeclining.value) {
		return;
	}

	declineReason.value = '';
	isDeclineModalOpen.value = true;
};

const closeDeclineModal = () => {
	if (isDeclining.value) {
		return;
	}

	isDeclineModalOpen.value = false;
};

const refreshSelectedCharacter = async () => {
	if (!props.application?.selected_character?.id || isRefreshingCharacter.value) {
		return;
	}

	isRefreshingCharacter.value = true;

	try {
		const response = await axios.post(route('groups.dashboard.activities.applicant-queue.application-character-refresh', {
			group: props.groupSlug,
			activity: props.activityId,
			application: props.application.id,
		}));
		const refreshedApplication = response.data?.application as QueueApplication | undefined;

		if (refreshedApplication) {
			emit('refreshed', refreshedApplication);
		}

		toast.add({
			title: t('groups.activities.management.queue.modal.character_refresh_success_title'),
			description: t('groups.activities.management.queue.modal.character_refresh_success_description'),
			color: 'success',
		});
	} catch (error) {
		const response = error && typeof error === 'object' && 'response' in error
			? (error as { response?: { status?: number, data?: { message?: string } } }).response
			: null;

		if (response?.status === 429) {
			toast.add({
				title: t('groups.activities.management.queue.modal.character_refresh_cooldown_title'),
				description: response.data?.message || t('groups.activities.management.queue.modal.character_refresh_cooldown'),
				color: 'warning',
			});
			return;
		}

		toast.add({
			title: t('groups.activities.management.queue.modal.character_refresh_error_title'),
			description: response?.data?.message || t('groups.activities.management.queue.modal.character_refresh_failed'),
			color: 'error',
		});
	} finally {
		isRefreshingCharacter.value = false;
	}
};

const declineApplication = async () => {
	if (!props.application || !canDeclineApplication.value || isDeclining.value) {
		return;
	}

	isDeclining.value = true;

	try {
		const response = await axios.post(route('groups.dashboard.activities.application-declines.store', {
			group: props.groupSlug,
			activity: props.activityId,
			application: props.application.id,
		}), {
			reason: declineReasonValue.value,
		});

		toast.add({
			title: t('groups.activities.management.queue.decline_success_title'),
			description: t('groups.activities.management.queue.decline_success_description'),
			color: 'success',
		});

		window.dispatchEvent(new CustomEvent('fullparty:activity-application-declined', {
			detail: {
				applicationId: props.application.id,
				pendingApplicationCount: response.data?.pending_application_count,
			},
		}));

		emit('declined', props.application.id);
		isDeclineModalOpen.value = false;
		isOpen.value = false;
	} catch (error) {
		toast.add({
			title: t('groups.activities.management.queue.decline_error_title'),
			description: t('groups.activities.management.queue.decline_error_description'),
			color: 'error',
		});
	} finally {
		isDeclining.value = false;
	}
};

watch(() => props.application?.id, () => {
	section.value = 'application';
	declineReason.value = '';
	isDeclineModalOpen.value = false;
});

watch(isOpen, (open) => {
	if (open) section.value = 'application';
	if (!open) {
		declineReason.value = '';
		isDeclineModalOpen.value = false;
	}
});
</script>

<template>
    <UModal
        v-model:open="isOpen"
        :title="displayName"
        :description="description || undefined"
        :ui="{ content: 'sm:max-w-4xl h-[min(40rem,calc(100dvh-2rem))] p-0 overflow-hidden' }"
        @after:enter="handleAfterEnter"
        @after:leave="handleAfterLeave"
    >
        <template #content>
            <ApplicantInspectorLayout
                v-if="application"
                v-model="section"
                :key="application.id"
                :name="displayName"
                :avatar-url="avatarUrl"
                :description="[applicantCharacter?.world, applicantCharacter?.datacenter].filter(Boolean).join(' / ')"
                :notes-count="notesCount"
                :notes-severity="notesSummary?.highest_severity"
                class="h-full"
                @close="isOpen = false"
            >
                <template #identity>
                    <UBadge v-if="application.is_guest" color="warning" variant="soft" size="xs" class="mt-2" :label="t('groups.activities.management.queue.guest_badge')" />
                </template>
                <template #metadata>
                    <dl class="space-y-4">
                        <div>
                            <dt class="mb-1 text-muted">{{ t('groups.activities.management.queue.modal.account') }}</dt>
                            <dd class="[overflow-wrap:anywhere]">{{ application.user?.name || t('groups.activities.management.queue.modal.guest_account') }}</dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-muted">{{ t('groups.activities.management.queue.modal.submitted') }}</dt>
                            <dd>{{ submittedAtLabel }}</dd>
                        </div>
                        <div v-if="editedAtLabel">
                            <dt class="mb-1 text-muted">{{ t('groups.activities.management.queue.modal.edited') }}</dt>
                            <dd>{{ editedAtLabel }}</dd>
                        </div>
                    </dl>
                </template>
                <template #application>
                    <ApplicantApplicationPanel :application="application" />
                </template>
                <template #character>
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-6 text-sm">
                        <div v-for="fact in characterFacts" :key="fact.label" class="min-w-0">
                            <dt class="mb-1.5 text-xs text-muted">{{ fact.label }}</dt>
                            <dd class="[overflow-wrap:anywhere]">{{ fact.value }}</dd>
                        </div>
                    </dl>
                    <div v-if="application.selected_character" class="mt-6 flex items-center justify-between gap-3 border-t border-default pt-4 text-xs text-muted">
                        <div>
                            <p>{{ t('groups.activities.management.queue.modal.character_last_checked') }}</p>
                            <p class="mt-1 text-toned">{{ selectedCharacterLastCheckedLabel }}</p>
                        </div>
                        <UTooltip :text="selectedCharacterRefreshTitle">
                            <UButton size="sm" color="neutral" variant="ghost" icon="i-lucide-refresh-cw"
                                :loading="isRefreshingCharacter" :disabled="!canRefreshSelectedCharacter"
                                :aria-label="t('groups.activities.management.queue.modal.refresh_character')"
                                @click="refreshSelectedCharacter" />
                        </UTooltip>
                    </div>
                </template>
                <template #record>
                    <div class="space-y-6">
                        <ActivityCharacterFflogsProgress
                            v-if="applicantCharacter?.name && applicantCharacter?.world"
                            :open="isOpen" :group-slug="groupSlug" :activity-id="activityId"
                            :application-id="application.id" :character-id="application.selected_character?.id ?? null"
                            :character-name="applicantCharacter.name" :world="applicantCharacter.world"
                            :fflogs-zone-id="fflogsZoneId" :should-fetch="canFetchPanelData && section === 'record'"
                            embedded
                        />
                        <p v-else class="text-sm text-muted">{{ t('groups.activities.management.queue.modal.fflogs_unavailable_guest') }}</p>
                        <ApplicantUserStats :stats="application.user_stats" :empty-message="userStatsEmptyMessage" embedded />
                    </div>
                </template>
                <template #notes>
                    <ApplicantNotesPanel :notes="applicantNotes.notes.value" :loading="applicantNotes.isLoading.value"
                        :error="applicantNotes.hasError.value" :application-note="application.notes"
                        @retry="applicantNotes.reload" />
                </template>
		<template #footer>
			<div class="flex w-full flex-wrap items-center justify-between gap-3">
				<p v-if="canDeclineApplication" class="max-w-sm text-xs text-muted">
					{{ t('groups.activities.management.queue.decline_footer_hint') }}
				</p>
				<div class="ml-auto flex items-center gap-2">
					<UButton
						color="neutral"
						variant="outline"
						:label="t('general.close')"
						@click="isOpen = false"
					/>
					<UButton
						v-if="canDeclineApplication"
						color="error"
						variant="soft"
						icon="i-lucide-ban"
						:label="t('groups.activities.management.queue.decline')"
						@click="openDeclineModal"
					/>
				</div>
			</div>
		</template>
            </ApplicantInspectorLayout>
        </template>
    </UModal>

	<UModal
		:open="isDeclineModalOpen"
		:title="t('groups.activities.management.queue.decline_modal.title')"
		:description="t('groups.activities.management.queue.decline_modal.description')"
		@update:open="(open) => { if (!open) closeDeclineModal(); }"
	>
		<template #body>
			<div class="space-y-4">
				<UAlert
					color="warning"
					variant="soft"
					icon="i-lucide-triangle-alert"
					:title="t('groups.activities.management.queue.decline_modal.warning_title')"
					:description="t('groups.activities.management.queue.decline_modal.warning_description')"
				/>

				<div
					v-if="application"
					class="rounded-sm border border-default bg-default px-4 py-3"
				>
					<p class="break-words [overflow-wrap:anywhere] font-medium text-toned">
						{{ displayName }}
					</p>
					<p class="mt-1 text-sm text-muted">
						{{ applicantCharacter?.world || t('groups.activities.management.queue.modal.world') }}
						<span v-if="applicantCharacter?.datacenter"> - {{ applicantCharacter.datacenter }}</span>
					</p>
				</div>

				<UFormField
					:label="t('groups.activities.management.queue.decline_modal.reason_label')"
					:description="t('groups.activities.management.queue.decline_modal.reason_description')"
				>
					<UTextarea
						v-model="declineReason"
						:rows="4"
						class="w-full"
						:maxlength="activityTextLimits.applicationDeclineReason"
						:placeholder="t('groups.activities.management.queue.decline_modal.reason_placeholder')"
					/>
				</UFormField>
			</div>
		</template>

		<template #footer>
			<div class="flex w-full items-center justify-end gap-2">
				<UButton
					color="neutral"
					variant="outline"
					:disabled="isDeclining"
					:label="t('general.cancel')"
					@click="closeDeclineModal"
				/>
				<UButton
					color="error"
					variant="soft"
					icon="i-lucide-ban"
					:label="t('groups.activities.management.queue.decline_modal.confirm')"
					:loading="isDeclining"
					@click="declineApplication"
				/>
			</div>
		</template>
	</UModal>
</template>
