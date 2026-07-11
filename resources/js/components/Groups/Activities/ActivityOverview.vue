<script setup lang="ts">
import ActivityCompletionSummaryPanel from "@/components/Groups/Activities/ActivityCompletionSummaryPanel.vue";
import ActivityRosterSummaryPanel from "@/components/Groups/Activities/ActivityRosterSummaryPanel.vue";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { getActivityStatusMeta } from "@/utils/activityStatusMeta";
import type { ActivitySlot } from "@/Types/ActivityRoster";
import type { ActivityCompletionSummary } from "@/Types/ActivityProgression";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import { formatRelativeTime } from "@/utils/formatRelativeTime";
import { useMinuteTicker } from "@/composables/useMinuteTicker";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";

const props = defineProps<{
	title: string
	status: string
	canEdit: boolean
	canSchedule: boolean
	canComplete: boolean
	canPublishRoster: boolean
	canDelete: boolean
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
	hasApplicantQueue: boolean
	description: string | null
	notes: string | null
	rosterSummaryPresets: Array<{
		key: string
		label: Record<string, string | null | undefined> | null | undefined
		description: Record<string, string | null | undefined> | null | undefined
		requirements: Array<{
			source: string
			source_id: number
			comparison: 'at_least' | 'exactly' | 'at_most'
			target_count: number
			scope_type: 'all_slots' | 'slot_group' | 'slot_group_set'
			scope_group_keys: string[]
			scope_groups: Array<{
				key: string
				label: Record<string, string | null | undefined> | null | undefined
			}>
			item: {
				id: number
				label: Record<string, string | null | undefined> | null | undefined
				meta: {
					role?: string | null
					shorthand?: string | null
					icon_url?: string | null
					flaticon_url?: string | null
					black_icon_url?: string | null
					transparent_icon_url?: string | null
					sprite_url?: string | null
				} | null
			}
		}>
	}>
	slots: ActivitySlot[]
	completedProgression: ActivityCompletionSummary | null
	canPublishPartyFinderInfo: boolean
}>();

const emit = defineEmits<{
	edit: []
	viewOverview: []
	goToApplication: []
	copyApplicationLink: []
	exportRoster: []
	schedule: []
	complete: []
	publishRoster: []
	delete: []
	cancel: []
	updateRosterView: [value: 'party' | 'role' | 'list']
	toggleApplicantQueue: []
	publishPartyFinderInfo: []
}>();

const { t, locale } = useI18n();
const statusMeta = computed(() => getActivityStatusMeta(props.status));
const relativeTimeTick = useMinuteTicker();
const { withDisplayTimeZone } = useTimeDisplayMode();

const dateLabel = computed(() => {
	if (!props.startsAt) {
		return t('groups.activities.cards.no_time');
	}

	return createDateTimeFormatter(locale.value, withDisplayTimeZone({
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	})).format(new Date(props.startsAt));
});

const timeLabel = computed(() => {
	if (!props.startsAt) {
		return t('groups.activities.cards.no_time');
	}

	return createDateTimeFormatter(locale.value, withDisplayTimeZone({
		hour: '2-digit',
		minute: '2-digit',
		timeZoneName: 'short',
	})).format(new Date(props.startsAt));
});

const durationLabel = computed(() => {
	if (!props.durationHours) {
		return t('groups.activities.management.overview.no_duration');
	}

	return t('groups.activities.management.overview.duration', { count: props.durationHours });
});

const relativeStartsAtLabel = computed(() => {
	return formatRelativeTime(
		props.startsAt,
		locale.value,
		t("notifications.just_now"),
		t("groups.activities.cards.no_relative_time"),
		relativeTimeTick.value,
	);
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

</script>

<template>
	<section class="border border-default bg-muted dark:bg-elevated/50 px-5 py-5 shadow-sm">
		<div class="flex flex-col gap-4">
				<div class="flex flex-col gap-4 border-b border-default pb-4 md:flex-row md:items-start md:justify-between">
					<div class="flex min-w-0 flex-1 flex-col gap-2">
						<div class="flex min-w-0 flex-wrap items-start gap-3">
							<h1 class="min-w-0 break-words [overflow-wrap:anywhere] font-semibold text-2xl text-toned">
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

				<div class="flex flex-wrap items-center gap-2 md:justify-end">
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

			<div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
				<div class="flex flex-col gap-4">
					<div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-muted">
						<div class="inline-flex items-center gap-2">
							<UIcon name="i-lucide-calendar-days" class="size-4" />
							<span>{{ dateLabel }}</span>
						</div>

						<div class="inline-flex items-center gap-2">
							<UIcon name="i-lucide-clock-3" class="size-4" />
							<span>{{ timeLabel }} ({{ relativeStartsAtLabel }})</span>
						</div>

						<div class="inline-flex items-center gap-2">
							<UIcon name="i-lucide-hourglass" class="size-4" />
							<span>{{ durationLabel }}</span>
						</div>

						<div class="inline-flex items-center gap-2">
							<UIcon name="i-lucide-users" class="size-4" />
							<span>{{ assignedLabel }}</span>
						</div>
					</div>
				</div>

				<div class="flex flex-wrap items-center gap-2 md:justify-end">
					<UButton
						color="neutral"
						variant="outline"
						class="bg-background shadow-sm"
						icon="i-lucide-send"
						:label="t('party_finder.publish.button')"
						:disabled="!canPublishPartyFinderInfo"
						@click="emit('publishPartyFinderInfo')"
					/>
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

				<div class="flex flex-col gap-3 border-t border-default pt-4 text-sm md:flex-row md:items-start md:justify-between">
				<div class="flex flex-wrap items-center gap-x-6 gap-y-2">
				<div class="inline-flex items-center gap-2">
					<span class="text-muted">{{ t('groups.activities.management.overview.group') }}:</span>
					<span class="font-medium text-toned break-words [overflow-wrap:anywhere]">{{ groupName }}</span>
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

				<div v-if="hasApplicantQueue" class="inline-flex items-center gap-2">
					<span class="text-muted">{{ t('groups.activities.management.overview.applicants') }}:</span>
					<span class="font-medium text-toned">{{ pendingApplicantsLabel }}</span>
				</div>
				</div>

				<div class="flex flex-wrap items-center gap-2 md:justify-end">
					<UButton
						v-if="canSchedule"
						color="primary"
						variant="outline"
						class="bg-background shadow-sm"
						icon="i-lucide-calendar-check-2"
						:label="t('groups.activities.management.schedule_activity')"
						@click="emit('schedule')"
					/>
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
						v-if="canDelete"
						color="error"
						variant="outline"
						class="bg-background shadow-sm xl:ml-auto"
						icon="i-lucide-trash-2"
						:label="t('groups.activities.management.delete_activity')"
						@click="emit('delete')"
					/>
					<UButton
						v-else-if="canCancel"
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
				<div
					v-if="description || notes || completedProgression"
					class="flex min-w-0 flex-1 flex-col gap-4"
				>
					<div v-if="description" class="break-words [overflow-wrap:anywhere] text-sm whitespace-pre-wrap text-toned">
						{{ description }}
					</div>

					<div v-if="notes" class="break-words [overflow-wrap:anywhere] text-sm whitespace-pre-wrap text-muted">
						{{ notes }}
					</div>

				</div>

				<div class="hidden flex-wrap items-center gap-3 xl:ml-auto xl:flex xl:justify-end">
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
						v-if="hasApplicantQueue"
						color="neutral"
						variant="ghost"
						size="sm"
						:trailing-icon="showApplicantQueue ? 'i-lucide-chevron-right' : 'i-lucide-chevron-left'"
						:label="applicationsToggleLabel"
						@click="emit('toggleApplicantQueue')"
					/>
				</div>
			</div>

			<div class="hidden xl:block">
				<ActivityRosterSummaryPanel
					v-if="rosterSummaryPresets.length > 0"
					:presets="rosterSummaryPresets"
					:slots="slots"
				/>
			</div>
		</div>
	</section>
	<ActivityCompletionSummaryPanel
		v-if="completedProgression"
		class="mt-4"
		:completed-progression="completedProgression"
	/>
</template>
