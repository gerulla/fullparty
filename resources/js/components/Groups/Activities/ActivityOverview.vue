<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { getActivityStatusMeta } from "@/utils/activityStatusMeta";

const props = defineProps<{
	title: string
	status: string
	canEdit: boolean
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
}>();

const emit = defineEmits<{
	edit: []
	viewOverview: []
	goToApplication: []
	copyApplicationLink: []
	exportRoster: []
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
					<UTooltip text="Planned for the in-game plugin">
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

			<div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-default pt-4 text-sm">
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

			<div class="flex flex-col gap-4 border-t border-default pt-4 xl:flex-row xl:items-start xl:justify-between">
				<div v-if="description || notes" class="flex flex-1 flex-col gap-3">
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
</template>
