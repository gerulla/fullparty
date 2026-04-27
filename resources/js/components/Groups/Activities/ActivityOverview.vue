<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { getActivityStatusMeta } from "@/utils/activityStatusMeta";

const props = defineProps<{
	title: string
	status: string
	canEdit: boolean
	canComplete: boolean
	canPublishRoster: boolean
	canCancel: boolean
	rosterView: 'party' | 'role' | 'list'
	showApplicantQueue: boolean
	groupName: string
	activityTypeName: string
	startsAt: string | null
	durationHours: number | null
	organizerName: string | null
	organizerAvatarUrl: string | null
	slotCount: number
	assignedCount: number
	pendingApplicationCount: number
	needsApplication: boolean
	description: string | null
	notes: string | null
	completedProgression: {
		completedAt: string | null
		sourceLabel: string
		furthestPointLabel: string | null
		bestProgressPercent: number | null
		progressLinkUrl: string | null
		notes: string | null
		milestones: Array<{
			key: string
			label: string
			kills: number
			bestProgressPercent: number | null
		}>
	} | null
}>();

const emit = defineEmits<{
	edit: []
	viewOverview: []
	goToApplication: []
	copyApplicationLink: []
	exportRoster: []
	complete: []
	publishRoster: []
	cancel: []
	updateRosterView: [value: 'party' | 'role' | 'list']
	toggleApplicantQueue: []
}>();

const { t, locale } = useI18n();
const statusMeta = computed(() => getActivityStatusMeta(props.status));

const dateLabel = computed(() => {
	if (!props.startsAt) {
		return t('groups.activities.cards.no_time');
	}

	return new Intl.DateTimeFormat(locale.value, {
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	}).format(new Date(props.startsAt));
});

const timeLabel = computed(() => {
	if (!props.startsAt) {
		return t('groups.activities.cards.no_time');
	}

	return new Intl.DateTimeFormat(locale.value, {
		hour: '2-digit',
		minute: '2-digit',
		timeZone: 'UTC',
		timeZoneName: 'short',
	}).format(new Date(props.startsAt));
});

const durationLabel = computed(() => {
	if (!props.durationHours) {
		return t('groups.activities.management.overview.no_duration');
	}

	return t('groups.activities.management.overview.duration', { count: props.durationHours });
});

const assignedLabel = computed(() => t('groups.activities.management.overview.assigned', {
	assigned: props.assignedCount,
	total: props.slotCount,
}));

const pendingApplicantsLabel = computed(() => t('groups.activities.management.overview.pending_applicants', {
	count: props.pendingApplicationCount,
}));
const applicationsToggleLabel = computed(() => t('groups.activities.management.controls.applications_toggle', {
	count: props.pendingApplicationCount,
}));

const rosterViewOptions = computed(() => ([
	{ key: 'party' as const, label: t('groups.activities.management.controls.party'), icon: 'i-lucide-users' },
	{ key: 'role' as const, label: t('groups.activities.management.controls.role'), icon: 'i-lucide-shield' },
	{ key: 'list' as const, label: t('groups.activities.management.controls.list'), icon: 'i-lucide-list' },
]));

const completedAtLabel = computed(() => {
	if (!props.completedProgression?.completedAt) {
		return t('groups.activities.management.overview.progression.not_recorded');
	}

	return new Intl.DateTimeFormat(locale.value, {
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
	}).format(new Date(props.completedProgression.completedAt));
});

const milestoneProgressWidth = (progress: number | null) => {
	if (progress === null || Number.isNaN(progress)) {
		return '0%';
	}

	return `${Math.min(100, Math.max(0, progress))}%`;
};
</script>

<template>
	<section class="border border-default bg-muted dark:bg-elevated/50 px-5 py-5 shadow-sm">
		<div class="flex flex-col gap-4">
			<div class="flex flex-col gap-4 border-b border-default pb-4 xl:flex-row xl:items-start xl:justify-between">
				<div class="flex flex-col gap-2">
					<div class="flex flex-wrap items-center gap-3">
						<h1 class="font-semibold text-2xl text-toned">
							{{ title }}
						</h1>
						<UBadge
							size="md"
							variant="subtle"
							:color="statusMeta.color"
							:icon="statusMeta.icon"
							:label="t(`groups.activities.statuses.${status}`)"
						/>
						<UBadge
							color="neutral"
							variant="soft"
							size="md"
							:label="activityTypeName"
						/>
					</div>
				</div>

				<div class="flex flex-wrap items-center gap-2 xl:justify-end">
					<UButton
						v-if="canEdit"
						color="neutral"
						variant="outline"
						class="bg-background shadow-sm"
						icon="i-lucide-pencil"
						:label="t('groups.activities.management.edit')"
						@click="emit('edit')"
					/>
					<UButton
						color="neutral"
						variant="outline"
						class="bg-background shadow-sm"
						icon="i-lucide-eye"
						:label="t('groups.activities.management.view_overview')"
						@click="emit('viewOverview')"
					/>
				</div>
			</div>

			<div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
				<div class="flex flex-col gap-4">
					<div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-muted">
						<div class="inline-flex items-center gap-2">
							<UIcon name="i-lucide-calendar-days" class="size-4" />
							<span>{{ dateLabel }}</span>
						</div>

						<div class="inline-flex items-center gap-2">
							<UIcon name="i-lucide-clock-3" class="size-4" />
							<span>{{ timeLabel }} ({{ durationLabel }})</span>
						</div>

						<div class="inline-flex items-center gap-2">
							<UIcon name="i-lucide-users" class="size-4" />
							<span>{{ assignedLabel }}</span>
						</div>
					</div>
				</div>

				<div class="flex flex-wrap items-center gap-2 xl:justify-end">
					<UTooltip :text="t('groups.activities.management.messages.check_in_planned_tooltip')">
						<span class="inline-flex">
							<UButton
								color="neutral"
								variant="outline"
								class="bg-background shadow-sm"
								icon="i-lucide-user-round-check"
								:label="t('groups.activities.management.overview.check_in')"
								disabled
							/>
						</span>
					</UTooltip>
					<div
						v-if="needsApplication"
						class="inline-flex items-stretch"
					>
						<UButton
							color="neutral"
							variant="outline"
							class="rounded-r-none bg-background shadow-sm"
							icon="i-lucide-file-pen-line"
							:label="t('groups.activities.management.overview.go_to_application')"
							@click="emit('goToApplication')"
						/>
						<UButton
							color="neutral"
							variant="outline"
							class="-ml-px rounded-l-none bg-background px-3 shadow-sm"
							icon="i-lucide-copy"
							@click="emit('copyApplicationLink')"
						/>
					</div>
					<UButton
						color="neutral"
						variant="outline"
						class="bg-background shadow-sm"
						icon="i-lucide-download"
						:label="t('groups.activities.management.overview.export_csv')"
						@click="emit('exportRoster')"
					/>
				</div>
			</div>

			<div class="flex flex-col gap-3 border-t border-default pt-4 text-sm xl:flex-row xl:items-center xl:justify-between">
				<div class="flex flex-wrap items-center gap-x-6 gap-y-2">
				<div class="inline-flex items-center gap-2">
					<span class="text-muted">{{ t('groups.activities.management.overview.group') }}:</span>
					<span class="font-medium text-toned">{{ groupName }}</span>
				</div>

				<div class="hidden h-4 w-px bg-default md:block"></div>

				<div class="inline-flex items-center gap-2">
					<span class="text-muted">{{ t('groups.activities.management.organizer') }}:</span>
					<UUser
						v-if="organizerName"
						:name="organizerName"
						:avatar="organizerAvatarUrl ? { src: organizerAvatarUrl, alt: organizerName } : undefined"
						size="sm"
					/>
					<span v-else class="font-medium text-toned">{{ t('groups.activities.cards.no_organizer') }}</span>
				</div>

				<div class="hidden h-4 w-px bg-default md:block"></div>

				<div class="inline-flex items-center gap-2">
					<span class="text-muted">{{ t('groups.activities.management.overview.applicants') }}:</span>
					<span class="font-medium text-toned">{{ pendingApplicantsLabel }}</span>
				</div>
				</div>

				<div class="flex flex-row items-center gap-2">
					<UButton
						v-if="canComplete"
						color="success"
						variant="outline"
						class="bg-background shadow-sm"
						icon="i-lucide-flag"
						:label="t('groups.activities.management.complete_activity')"
						@click="emit('complete')"
					/>
					<UButton
						v-if="canPublishRoster"
						color="primary"
						variant="outline"
						class="bg-background shadow-sm"
						icon="i-lucide-send"
						:label="t('groups.activities.management.publish_roster')"
						@click="emit('publishRoster')"
					/>
					<UButton
						v-if="canCancel"
						color="error"
						variant="outline"
						class="bg-background shadow-sm xl:ml-auto"
						icon="i-lucide-ban"
						:label="t('groups.activities.management.cancel_activity')"
						@click="emit('cancel')"
					/>
				</div>
			</div>

			<div class="flex flex-col gap-4 border-t border-default pt-4 xl:flex-row xl:items-start xl:justify-between">
				<div v-if="description || notes || completedProgression" class="flex flex-1 flex-col gap-4">
					<div class="inline-flex items-center gap-2">
						<div v-if="description" class="text-sm whitespace-pre-wrap text-toned">
							{{ description }}
						</div>
					</div>

					<div v-if="notes" class="text-sm whitespace-pre-wrap text-muted">
						{{ notes }}
					</div>


				</div>

				<div class="flex flex-wrap items-center gap-3 xl:ml-auto xl:justify-end">
					<div class="flex flex-wrap items-center gap-3">
						<span class="text-sm font-medium text-toned">
							{{ t('groups.activities.management.controls.view') }}
						</span>

						<div class="inline-flex items-center rounded-md border border-default bg-background p-1">
							<UButton
								v-for="option in rosterViewOptions"
								:key="option.key"
								color="neutral"
								:variant="rosterView === option.key ? 'solid' : 'ghost'"
								size="sm"
								:icon="option.icon"
								:label="option.label"
								@click="emit('updateRosterView', option.key)"
							/>
						</div>
					</div>

					<UButton
						color="neutral"
						variant="ghost"
						size="sm"
						:trailing-icon="showApplicantQueue ? 'i-lucide-chevron-right' : 'i-lucide-chevron-left'"
						:label="applicationsToggleLabel"
						@click="emit('toggleApplicantQueue')"
					/>
				</div>
			</div>
		</div>
	</section>
	<div
		v-if="completedProgression"
		class="flex flex-col gap-4 border border-default bg-muted dark:bg-elevated/50 p-4 mt-4"
	>
		<div class="flex flex-col gap-1">
			<h3 class="font-semibold text-sm uppercase tracking-wide text-toned">
				{{ t('groups.activities.management.overview.progression.title') }}
			</h3>
			<p class="text-sm text-muted">
				{{ t('groups.activities.management.overview.progression.subtitle') }}
			</p>
		</div>

		<div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
			<div class="flex flex-col gap-1">
				<span class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.management.overview.progression.completed_at') }}</span>
				<span class="text-sm font-medium text-toned">{{ completedAtLabel }}</span>
			</div>

			<div class="flex flex-col gap-1">
				<span class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.management.overview.progression.source') }}</span>
				<span class="text-sm font-medium text-toned">{{ completedProgression.sourceLabel }}</span>
			</div>

			<div class="flex flex-col gap-1">
				<span class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.management.overview.progression.furthest_point') }}</span>
				<span class="text-sm font-medium text-toned">
									{{ completedProgression.furthestPointLabel || t('groups.activities.management.overview.progression.not_recorded') }}
								</span>
			</div>

			<div class="flex flex-col gap-1">
				<span class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.management.overview.progression.best_progress') }}</span>
				<span class="text-sm font-medium text-toned">
									{{ completedProgression.bestProgressPercent !== null ? `${completedProgression.bestProgressPercent}%` : t('groups.activities.management.overview.progression.not_recorded') }}
								</span>
			</div>
		</div>

		<div
			v-if="completedProgression.progressLinkUrl || completedProgression.notes"
			class="grid gap-3 md:grid-cols-2"
		>
			<div v-if="completedProgression.progressLinkUrl" class="flex flex-col gap-1">
				<span class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.management.overview.progression.evidence') }}</span>
				<a
					:href="completedProgression.progressLinkUrl"
					target="_blank"
					rel="noopener noreferrer"
					class="text-sm font-medium text-primary hover:underline"
				>
					{{ t('groups.activities.management.overview.progression.view_fflogs') }}
				</a>
			</div>

			<div v-if="completedProgression.notes" class="flex flex-col gap-1">
				<span class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.management.overview.progression.notes') }}</span>
				<p class="text-sm whitespace-pre-wrap text-toned">
					{{ completedProgression.notes }}
				</p>
			</div>
		</div>

		<div v-if="completedProgression.milestones.length > 0" class="flex flex-col gap-2">
			<span class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.management.overview.progression.milestones') }}</span>

			<div class="grid gap-2">
				<div
					v-for="milestone in completedProgression.milestones"
					:key="milestone.key"
					class="relative overflow-hidden rounded-sm border border-default bg-muted/70"
				>
					<div
						class="absolute inset-y-0 left-0 bg-success/20 transition-[width] duration-300 ease-out"
						:style="{ width: milestoneProgressWidth(milestone.bestProgressPercent) }"
					/>
					<div class="relative flex flex-col gap-2 px-3 py-2 md:flex-row md:items-center md:justify-between">
						<span class="text-sm font-medium text-toned">{{ milestone.label }}</span>
						<div class="flex flex-wrap items-center gap-3 text-sm text-muted">
							<span>{{ t('groups.activities.management.complete_activity_modal.kills') }}: {{ milestone.kills }}</span>
							<span>{{ t('groups.activities.management.complete_activity_modal.best_progress_percent') }}: {{ milestone.bestProgressPercent !== null ? `${milestone.bestProgressPercent}%` : t('groups.activities.management.overview.progression.not_recorded') }}</span>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>
