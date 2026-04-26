<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import ActivityCharacterFflogsProgress from "@/components/Groups/Activities/ActivityCharacterFflogsProgress.vue";
import ApplicantUserStats from "@/components/Groups/Activities/ApplicantUserStats.vue";

type LocalizedText = Record<string, string | null | undefined> | null | undefined;

type ApplicationAnswerSummary = {
	question_key: string
	question_label: LocalizedText
	question_type: string
	source: string | null
	raw_value: unknown
	display_values: string[]
	role_values: string[]
	display_items: Array<{
		label: string
		role?: string | null
		icon_url?: string | null
		flat_icon_url?: string | null
		transparent_icon_url?: string | null
	}>
}

type ActivityApplication = {
	id: number
	user: {
		id: number
		name: string
		avatar_url: string | null
	} | null
	selected_character: {
		id: number
		name: string
		avatar_url: string | null
		world: string | null
		datacenter: string | null
		occult_level: number | null
		phantom_mastery: number | null
	} | null
	status: string
	notes: string | null
	submitted_at: string | null
	user_stats: {
		class: {
			group: Array<{
				label: string
				count: number
				role?: string | null
				icon_url?: string | null
				flat_icon_url?: string | null
			}>
			overall: Array<{
				label: string
				count: number
				role?: string | null
				icon_url?: string | null
				flat_icon_url?: string | null
			}>
		}
		phantom_job: {
			group: Array<{
				label: string
				count: number
				icon_url?: string | null
				transparent_icon_url?: string | null
			}>
			overall: Array<{
				label: string
				count: number
				icon_url?: string | null
				transparent_icon_url?: string | null
			}>
		}
	} | null
	answers: ApplicationAnswerSummary[]
}

const props = defineProps<{
	groupSlug: string
	activityId: number
	fflogsZoneId: number | null
	application: ActivityApplication
}>();

const { t, locale } = useI18n();
const page = usePage();
const isModalOpen = ref(false);
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

const displayName = computed(() => (
	props.application.selected_character?.name
	|| props.application.user?.name
	|| t('groups.activities.management.queue.unknown_applicant')
));

const avatarUrl = computed(() => (
	props.application.selected_character?.avatar_url
	|| props.application.user?.avatar_url
	|| undefined
));

const description = computed(() => {
	const parts = [
		props.application.user?.name || null,
		props.application.selected_character?.world || null,
	];

	return parts.filter(Boolean).join(' • ');
});

const submittedAtLabel = computed(() => {
	if (!props.application.submitted_at) {
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

const summaryAnswers = computed(() => props.application.answers
	.filter((answer) => {
		if (answer.display_values.length === 0) {
			return false;
		}

		if (answer.source === 'phantom_jobs') {
			return true;
		}

		if (answer.source === 'static_options') {
			return answer.question_key.toLowerCase().includes('position');
		}

		return false;
	})
	.slice(0, 3)
	.map((answer) => ({
		key: answer.question_key,
		label: localizedText(answer.question_label, answer.question_key),
		source: answer.source,
		displayValues: answer.display_values.slice(0, 4),
		remainingCount: Math.max(0, answer.display_values.length - 4),
	})));

const detailedAnswers = computed(() => props.application.answers
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

const classAnswer = computed(() => props.application.answers.find((answer) => answer.source === 'character_classes') ?? null);
const phantomAnswer = computed(() => props.application.answers.find((answer) => answer.source === 'phantom_jobs') ?? null);
const positionAnswer = computed(() => props.application.answers.find((answer) => answer.source === 'static_options' && answer.question_key.toLowerCase().includes('position')) ?? null);
const playableRoles = computed(() => classAnswer.value?.role_values ?? []);
const classRoleColor = computed(() => roleBadgeColor(playableRoles.value[0] ?? ''));
const classDisplayItems = computed(() => classAnswer.value?.display_items ?? []);
const phantomDisplayItems = computed(() => phantomAnswer.value?.display_items ?? []);
const shouldShowOccultLevel = computed(() => phantomAnswer.value !== null && props.application.selected_character?.occult_level !== null && props.application.selected_character?.occult_level !== undefined);
const shouldShowPhantomMastery = computed(() => phantomAnswer.value !== null && props.application.selected_character?.phantom_mastery !== null && props.application.selected_character?.phantom_mastery !== undefined);

const notePreview = computed(() => {
	if (!props.application.notes) {
		return null;
	}

	return props.application.notes.length > 120
		? `${props.application.notes.slice(0, 120)}...`
		: props.application.notes;
});
</script>

<template>
	<div class="border border-default bg-default px-4 py-4 hover:border-brand hover:scale-105 transition-all cursor-move">
		<!-- Queue card header: applicant identity and current application status -->
		<div class="flex items-start justify-between gap-3">
			<UUser
				:name="displayName"
				:description="description || undefined"
				:avatar="avatarUrl ? { src: avatarUrl, loading: 'lazy' } : undefined"
				size="lg"
			/>

			<UBadge
				color="neutral"
				variant="subtle"
				:label="t('groups.activities.management.queue.pending')"
			/>
		</div>

		<p class="mt-3 text-xs text-muted">
			{{ submittedAtLabel }}
		</p>

		<!-- Preview-only role summary for fast queue scanning -->
		<div v-if="playableRoles.length > 0" class="mt-4 space-y-2">
			<p class="text-[11px] font-medium uppercase tracking-[0.12em] text-muted">
				{{ t('groups.activities.management.queue.can_play') }}
			</p>

			<div class="flex flex-wrap gap-2">
				<UBadge
					v-for="role in playableRoles"
					:key="role"
					:color="roleBadgeColor(role)"
					variant="soft"
					:label="role"
				/>
			</div>
		</div>

		<!-- Preview-only roster-relevant answers such as phantom jobs and positions -->
		<div v-if="summaryAnswers.length > 0" class="mt-4 space-y-3">
			<div
				v-for="answer in summaryAnswers"
				:key="answer.key"
				class="space-y-2"
			>
				<p class="text-[11px] font-medium uppercase tracking-[0.12em] text-muted">
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
					<UBadge
						v-if="answer.remainingCount > 0"
						color="neutral"
						variant="soft"
						:label="`+${answer.remainingCount}`"
					/>
				</div>
			</div>
		</div>

		<p v-if="notePreview" class="mt-4 text-sm text-toned">
			{{ notePreview }}
		</p>

		<!-- Queue card action: opens the full application detail modal -->
		<div class="mt-4 flex justify-center">
			<UButton
				color="neutral"
				variant="outline"
				size="md"
				icon="i-lucide-expand"
				class="w-full items-center justify-center"
				:label="t('general.view')"
				@click="isModalOpen = true"
			/>
		</div>

		<UModal
			v-model:open="isModalOpen"
			:title="displayName"
			:description="description || undefined"
			:ui="{ content: 'sm:max-w-6xl' }"
		>
			<template #header>
				<!-- Modal header: repeat applicant identity for context -->
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

					<UButton
						class="ml-auto shrink-0"
						color="primary"
						variant="outline"
						size="md"
						icon="i-lucide-user-plus"
						:label="t('groups.activities.management.queue.assign_to_roster')"
						@click.prevent
					/>
				</div>
			</template>

			<template #body>
				<div class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(360px,0.95fr)] xl:items-start">
					<div class="space-y-6">
						<!-- Modal applicant block: account and selected-character metadata -->
						<div class="space-y-3 border border-default bg-default/60 p-4">
							<p class="text-[11px] font-medium uppercase tracking-[0.12em] text-muted">
								{{ t('groups.activities.management.queue.modal.applicant') }}
							</p>

							<div class="grid gap-3 text-sm md:grid-cols-2">
								<div class="flex items-start justify-between gap-4">
									<span class="text-muted">{{ t('groups.activities.management.queue.modal.account') }}</span>
									<span class="text-right font-medium text-toned">
										{{ props.application.user?.name || t('groups.activities.management.queue.unknown_applicant') }}
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
										{{ props.application.selected_character?.world || '—' }}
									</span>
								</div>

								<div class="flex items-start justify-between gap-4">
									<span class="text-muted">{{ t('groups.activities.management.queue.modal.datacenter') }}</span>
									<span class="text-right font-medium text-toned">
										{{ props.application.selected_character?.datacenter || '—' }}
									</span>
								</div>

								<div v-if="shouldShowOccultLevel" class="flex items-start justify-between gap-4">
									<span class="text-muted">{{ t('groups.activities.management.queue.modal.occult_level') }}</span>
									<span class="text-right font-medium text-toned">
										{{ props.application.selected_character?.occult_level }}
									</span>
								</div>

								<div v-if="shouldShowPhantomMastery" class="flex items-start justify-between gap-4">
									<span class="text-muted">{{ t('groups.activities.management.queue.modal.phantom_mastery') }}</span>
									<span class="text-right font-medium text-toned">
										{{ props.application.selected_character?.phantom_mastery }}
									</span>
								</div>
							</div>
						</div>

						<!-- Modal roster blocks: selected classes, phantom jobs, and positions -->
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

						<!-- Modal catch-all section for non-roster application answers -->
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

						<!-- Modal freeform notes from the applicant -->
						<div class="space-y-3 border border-default bg-default/60 p-4">
							<p class="text-[11px] font-medium uppercase tracking-[0.12em] text-muted">
								{{ t('groups.activities.management.queue.modal.notes') }}
							</p>

							<p class="text-sm whitespace-pre-line text-toned">
								{{ props.application.notes || t('groups.activities.management.queue.modal.no_notes') }}
							</p>
						</div>
					</div>

					<div class="space-y-6">
						<!-- Character-specific FF Logs progress for the activity's configured zone -->
						<ActivityCharacterFflogsProgress
							:open="isModalOpen"
							:group-slug="props.groupSlug"
							:activity-id="props.activityId"
							:character-id="props.application.selected_character?.id ?? null"
							:character-name="props.application.selected_character?.name ?? null"
							:world="props.application.selected_character?.world ?? null"
							:fflogs-zone-id="props.fflogsZoneId"
						/>

						<!-- Historical user stats: what this applicant tends to play with the group and overall -->
						<ApplicantUserStats :stats="props.application.user_stats" />
					</div>
				</div>
			</template>
		</UModal>
	</div>
</template>
