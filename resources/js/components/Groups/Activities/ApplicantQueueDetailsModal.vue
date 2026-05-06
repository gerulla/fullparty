<script setup lang="ts">
import axios from "axios";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import { route } from "ziggy-js";
import { localizedValue } from "@/utils/localizedValue";
import ActivityCharacterFflogsProgress from "@/components/Groups/Activities/ActivityCharacterFflogsProgress.vue";
import ApplicantUserStats from "@/components/Groups/Activities/ApplicantUserStats.vue";
import type { LocalizedText, QueueApplication } from "@/components/Groups/Activities/queueTypes";

const props = defineProps<{
	groupSlug: string
	activityId: number
	fflogsZoneId: number | null
	application: QueueApplication | null
}>();
const emit = defineEmits<{
	declined: [applicationId: number]
}>();

const isOpen = defineModel<boolean>('open', { required: true });
const canFetchPanelData = ref(false);
const isDeclineModalOpen = ref(false);
const declineReason = ref('');
const isDeclining = ref(false);

const { t, locale } = useI18n();
const page = usePage();
const toast = useToast();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));

const localizedText = (value: LocalizedText, fallback: string) => (
	localizedValue(value, locale.value, fallbackLocale.value) || fallback
);

const roleBadgeColor = (role: string) => {
	if (role === 'Tank') {
		return 'info';
	}

	if (role === 'Healer') {
		return 'success';
	}

	if (role === 'Melee') {
		return 'error';
	}

	if (role === 'Phys Ranged') {
		return 'warning';
	}

	if (role === 'Magic Ranged') {
		return 'secondary';
	}

	return 'neutral';
};

const answerBadgeColor = (source: string | null, value: string) => {
	const normalized = value.trim().toLowerCase();

	if (normalized === 'yes') {
		return 'success';
	}

	if (normalized === 'no') {
		return 'error';
	}

	if (source === 'phantom_jobs') {
		return 'secondary';
	}

	if (source === 'static_options') {
		return 'warning';
	}

	return 'neutral';
};

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

	return new Intl.DateTimeFormat(locale.value, {
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
	}).format(new Date(props.application.submitted_at));
});

const detailedAnswers = computed(() => (props.application?.answers ?? [])
	.filter((answer) => {
		if (answer.display_values.length === 0) {
			return false;
		}

		if (answer.source === 'character_classes' || answer.source === 'phantom_jobs') {
			return false;
		}

		if (answer.source === 'static_options') {
			return !answer.question_key.toLowerCase().includes('position');
		}

		return true;
	})
	.map((answer) => ({
		key: answer.question_key,
		label: localizedText(answer.question_label, answer.question_key),
		source: answer.source,
		displayValues: answer.display_values,
	})));

const classAnswer = computed(() => props.application?.answers.find((answer) => answer.source === 'character_classes') ?? null);
const phantomAnswer = computed(() => props.application?.answers.find((answer) => answer.source === 'phantom_jobs') ?? null);
const positionAnswer = computed(() => props.application?.answers.find((answer) => answer.source === 'static_options' && answer.question_key.toLowerCase().includes('position')) ?? null);
const playableRoles = computed(() => classAnswer.value?.role_values ?? []);
const classDisplayItems = computed(() => classAnswer.value?.display_items ?? []);
const phantomDisplayItems = computed(() => phantomAnswer.value?.display_items ?? []);
const shouldShowOccultLevel = computed(() => phantomAnswer.value !== null && props.application?.selected_character?.occult_level !== null && props.application?.selected_character?.occult_level !== undefined);
const shouldShowPhantomMastery = computed(() => phantomAnswer.value !== null && props.application?.selected_character?.phantom_mastery !== null && props.application?.selected_character?.phantom_mastery !== undefined);
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

const declineApplication = async () => {
	if (!props.application || !canDeclineApplication.value || isDeclining.value) {
		return;
	}

	isDeclining.value = true;

	try {
		await axios.post(route('groups.dashboard.activities.application-declines.store', {
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

		emit('declined', props.application.id);
		isDeclineModalOpen.value = false;
		isOpen.value = false;
	} catch (error) {
		console.error(error);
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
	declineReason.value = '';
	isDeclineModalOpen.value = false;
});

watch(isOpen, (open) => {
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
		:ui="{ content: 'sm:max-w-6xl' }"
		@after:enter="handleAfterEnter"
		@after:leave="handleAfterLeave"
	>
		<template #header>
			<div class="flex w-full items-center gap-4 ">
				<div class="flex min-w-0 items-center gap-3">
					<UAvatar
						v-if="avatarUrl"
						:src="avatarUrl"
						size="xl"
						alt=""
					/>

					<div class="min-w-0">
						<p class="truncate font-semibold text-highlighted">
							{{ displayName }}
						</p>
						<p v-if="description" class="truncate text-sm text-muted">
							{{ description }}
						</p>
					</div>
				</div>

				<UBadge
					v-if="application?.is_guest"
					color="warning"
					variant="soft"
					:label="t('groups.activities.management.queue.guest_badge')"
				/>
			</div>
		</template>

		<template #body>
			<div
				v-if="application"
				class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(360px,0.95fr)] xl:items-start"
			>
				<div class="space-y-6">
					<div class="space-y-3 border border-default bg-default/60 p-4">
						<p class="text-[11px] font-medium uppercase tracking-[0.12em] text-muted">
							{{ t('groups.activities.management.queue.modal.applicant') }}
						</p>

						<div class="grid gap-3 text-sm md:grid-cols-2">
							<div class="flex items-start justify-between gap-4">
								<span class="text-muted">{{ t('groups.activities.management.queue.modal.account') }}</span>
								<span class="text-right font-medium text-toned">
									{{ application.user?.name || t('groups.activities.management.queue.modal.guest_account') }}
								</span>
							</div>

							<div class="flex items-start justify-between gap-4">
								<span class="text-muted">{{ t('groups.activities.management.queue.modal.character') }}</span>
								<span class="text-right font-medium text-toned">
									{{ applicantCharacter?.name || t('groups.activities.management.queue.unknown_applicant') }}
								</span>
							</div>

							<div class="flex items-start justify-between gap-4">
								<span class="text-muted">{{ t('groups.activities.management.queue.modal.submitted') }}</span>
								<span class="text-right font-medium text-toned">
									{{ submittedAtLabel }}
								</span>
							</div>

							<div class="flex items-start justify-between gap-4">
								<span class="text-muted">{{ t('groups.activities.management.queue.modal.world') }}</span>
								<span class="text-right font-medium text-toned">
									{{ applicantCharacter?.world || '—' }}
								</span>
							</div>

							<div class="flex items-start justify-between gap-4">
								<span class="text-muted">{{ t('groups.activities.management.queue.modal.datacenter') }}</span>
								<span class="text-right font-medium text-toned">
									{{ applicantCharacter?.datacenter || '—' }}
								</span>
							</div>

							<div v-if="shouldShowOccultLevel" class="flex items-start justify-between gap-4">
								<span class="text-muted">{{ t('groups.activities.management.queue.modal.occult_level') }}</span>
								<span class="text-right font-medium text-toned">
									{{ application.selected_character?.occult_level }}
								</span>
							</div>

							<div v-if="shouldShowPhantomMastery" class="flex items-start justify-between gap-4">
								<span class="text-muted">{{ t('groups.activities.management.queue.modal.phantom_mastery') }}</span>
								<span class="text-right font-medium text-toned">
									{{ application.selected_character?.phantom_mastery }}
								</span>
							</div>
						</div>
					</div>

					<div class="w-full flex flex-col gap-4">
						<div
							v-if="classAnswer"
							class="w-full space-y-4 border border-default bg-default/60 p-4"
						>
							<p class="text-[11px] font-medium uppercase tracking-[0.12em] text-muted">
								{{ localizedText(classAnswer.question_label, classAnswer.question_key) }}
							</p>

							<div class="flex flex-wrap gap-2">
								<UBadge
									v-for="item in classDisplayItems"
									:key="item.label"
									:color="roleBadgeColor(item.role || playableRoles[0] || '')"
									variant="soft"
									size="lg"
								>
									<div class="flex items-center gap-2">
										<img
											v-if="item.flat_icon_url || item.icon_url"
											:src="item.flat_icon_url || item.icon_url || undefined"
											:alt="item.label"
											class="h-5 w-5 object-contain"
										>
										<span>{{ item.label }}</span>
									</div>
								</UBadge>
							</div>
						</div>

						<div
							v-if="phantomAnswer"
							class="w-full space-y-4 border border-default bg-default/60 p-4"
						>
							<p class="text-[11px] font-medium uppercase tracking-[0.12em] text-muted">
								{{ localizedText(phantomAnswer.question_label, phantomAnswer.question_key) }}
							</p>

							<div class="flex flex-wrap gap-2">
								<UBadge
									v-for="item in phantomDisplayItems"
									:key="item.label"
									color="secondary"
									variant="soft"
									size="lg"
								>
									<div class="flex items-center gap-2">
										<img
											v-if="item.transparent_icon_url || item.icon_url"
											:src="item.transparent_icon_url || item.icon_url || undefined"
											:alt="item.label"
											class="h-5 w-5 object-contain"
										>
										<span>{{ item.label }}</span>
									</div>
								</UBadge>
							</div>
						</div>

						<div
							v-if="positionAnswer"
							class="w-full space-y-4 border border-default bg-default/60 p-4"
						>
							<p class="text-[11px] font-medium uppercase tracking-[0.12em] text-muted">
								{{ localizedText(positionAnswer.question_label, positionAnswer.question_key) }}
							</p>

							<div class="flex flex-wrap gap-2">
								<UBadge
									v-for="value in positionAnswer.display_values"
									:key="value"
									color="warning"
									variant="outline"
									:label="value"
								/>
							</div>
						</div>
					</div>

					<div class="space-y-4 border border-default bg-default/60 p-4">
						<p class="text-[11px] font-medium uppercase tracking-[0.12em] text-muted">
							{{ t('groups.activities.management.queue.modal.answers') }}
						</p>

						<div v-if="detailedAnswers.length > 0" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
							<div
								v-for="answer in detailedAnswers"
								:key="answer.key"
								class="space-y-3 border border-default bg-muted/10 p-3"
							>
								<p class="text-sm font-medium text-toned">
									{{ answer.label }}
								</p>

								<div class="flex flex-wrap gap-2">
									<UBadge
										v-for="value in answer.displayValues"
										:key="`${answer.key}-${value}`"
										:color="answerBadgeColor(answer.source, value)"
										variant="soft"
										:label="value"
									/>
								</div>
							</div>
						</div>

						<p v-else class="text-sm text-muted">
							{{ t('groups.activities.management.queue.modal.no_answers') }}
						</p>
					</div>

					<div class="space-y-3 border border-default bg-default/60 p-4">
						<p class="text-[11px] font-medium uppercase tracking-[0.12em] text-muted">
							{{ t('general.notes') }}
						</p>

						<p class="text-sm whitespace-pre-line text-toned">
							{{ application.notes || t('groups.activities.management.queue.modal.no_notes') }}
						</p>
					</div>
				</div>

				<div class="space-y-6">
					<ActivityCharacterFflogsProgress
						v-if="applicantCharacter?.name && applicantCharacter?.world"
						:open="isOpen"
						:group-slug="groupSlug"
						:activity-id="activityId"
						:application-id="application.id"
						:character-id="application.selected_character?.id ?? null"
						:character-name="applicantCharacter?.name ?? null"
						:world="applicantCharacter?.world ?? null"
						:fflogs-zone-id="fflogsZoneId"
						:should-fetch="canFetchPanelData"
					/>
					<div
						v-else
						class="space-y-4 border border-default bg-default/60 p-4"
					>
						<p class="text-[11px] font-medium uppercase tracking-[0.12em] text-muted">
							{{ t('groups.activities.management.queue.modal.fflogs_title') }}
						</p>
						<p class="text-sm text-muted">
							{{ t('groups.activities.management.queue.modal.fflogs_unavailable_guest') }}
						</p>
					</div>

					<ApplicantUserStats
						:stats="application.user_stats"
						:empty-message="userStatsEmptyMessage"
					/>
				</div>
			</div>
		</template>

		<template #footer>
			<div class="flex w-full items-center justify-between gap-3">
				<p v-if="canDeclineApplication" class="text-sm text-muted">
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
					<p class="font-medium text-toned">
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
