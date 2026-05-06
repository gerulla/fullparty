<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import { setQueueApplicationDragData } from "@/components/Groups/Activities/rosterDragData";
import type { LocalizedText, QueueApplication } from "@/components/Groups/Activities/queueTypes";

const props = defineProps<{
	application: QueueApplication
}>();
const emit = defineEmits<{
	openDetails: [application: QueueApplication]
	openNotes: [application: QueueApplication]
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));

const localizedText = (value: LocalizedText, fallback: string) => (
	localizedValue(value, locale.value, fallbackLocale.value) || fallback
);

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

const applicantCharacter = computed(() => (
	props.application.is_guest
		? props.application.applicant_character
		: props.application.selected_character
		? {
			name: props.application.selected_character.name,
			avatar_url: props.application.selected_character.avatar_url,
			world: props.application.selected_character.world,
			datacenter: props.application.selected_character.datacenter,
		}
		: props.application.applicant_character
));

const displayName = computed(() => (
	applicantCharacter.value?.name
	|| props.application.user?.name
	|| t('groups.activities.management.queue.unknown_applicant')
));

const avatarUrl = computed(() => (
	applicantCharacter.value?.avatar_url
	|| props.application.user?.avatar_url
	|| undefined
));

const description = computed(() => {
	const parts = [
		props.application.is_guest ? t('groups.activities.management.queue.guest_badge') : props.application.user?.name || null,
		applicantCharacter.value?.world || null,
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

const classAnswer = computed(() => props.application.answers.find((answer) => answer.source === 'character_classes') ?? null);
const playableRoles = computed(() => classAnswer.value?.role_values ?? []);

const notePreview = computed(() => {
	if (!props.application.notes) {
		return null;
	}

	return props.application.notes.length > 120
		? `${props.application.notes.slice(0, 120)}...`
		: props.application.notes;
});

const canDragToRoster = computed(() => Boolean(props.application.selected_character));
const canViewMemberNotes = computed(() => Boolean(props.application.user?.notes.can_view));
const memberNotesButtonLabel = computed(() => {
	const notes = props.application.user?.notes;

	if (!notes?.can_view) {
		return t('general.notes');
	}

	const totalCount = notes.current_group_count + notes.shared_count;

	return totalCount > 0
		? `${t('general.notes')} (${totalCount})`
		: t('general.notes');
});

const handleDragStart = (event: DragEvent) => {
	if (!canDragToRoster.value) {
		event.preventDefault();
		return;
	}

	setQueueApplicationDragData(event, props.application);

	if (event.dataTransfer) {
		event.dataTransfer.effectAllowed = 'copyMove';
	}
};
</script>

<template>
	<div
		class="border border-default bg-default px-4 py-4 transition-all hover:border-brand hover:scale-105"
		:class="canDragToRoster ? 'cursor-grab' : 'cursor-default'"
		:draggable="canDragToRoster"
		@dragstart="handleDragStart"
	>
		<!-- Queue card header: applicant identity and current application status -->
		<div class="flex items-start justify-between gap-3">
			<UUser
				:name="displayName"
				:description="description || undefined"
				:avatar="avatarUrl ? { src: avatarUrl, loading: 'lazy' } : undefined"
				size="lg"
			/>

			<div class="flex items-center gap-2">
				<UBadge
					v-if="props.application.is_guest"
					color="warning"
					variant="soft"
					:label="t('groups.activities.management.queue.guest_badge')"
				/>
				<UBadge
					color="neutral"
					variant="subtle"
					:label="t('groups.activities.management.queue.pending')"
				/>
			</div>
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
					:color="role === 'Tank'
						? 'info'
						: role === 'Healer'
							? 'success'
							: role === 'Melee'
								? 'error'
								: role === 'Phys Ranged'
									? 'warning'
									: role === 'Magic Ranged'
										? 'secondary'
										: 'neutral'"
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
		<div class="mt-4 flex items-center gap-2">
			<UButton
				color="neutral"
				variant="outline"
				size="md"
				icon="i-lucide-expand"
				class="flex-1 items-center justify-center"
				:label="t('general.view')"
				@click="emit('openDetails', props.application)"
			/>
			<UButton
				v-if="canViewMemberNotes"
				color="secondary"
				variant="soft"
				size="md"
				icon="i-lucide-notebook-pen"
				class="flex-1 items-center justify-center"
				:label="memberNotesButtonLabel"
				@click="emit('openNotes', props.application)"
			/>
		</div>
	</div>
</template>
