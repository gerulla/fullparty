<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import PageHeader from "@/components/PageHeader.vue";

const props = defineProps<{
	group: any
}>();

const { t } = useI18n();

const roleBadge = computed(() => {
	if (props.group.current_user_role === 'owner') {
		return {
			label: 'Owner Access',
			color: 'warning',
			icon: 'i-lucide-crown',
		};
	}

	if (props.group.current_user_role === 'moderator') {
		return {
			label: 'Moderator Access',
			color: 'primary',
			icon: 'i-lucide-shield',
		};
	}

	return {
		label: 'Member Access',
		color: 'neutral',
		icon: 'i-lucide-user',
	};
});

const placeholderStats = computed(() => [
	{
		label: 'Members',
		value: 24,
		icon: 'i-lucide-users',
		help: '6 active this week',
	},
	{
		label: 'Moderators',
		value: 3,
		icon: 'i-lucide-shield',
		help: '1 owner, 2 moderators',
	},
	{
		label: 'Scheduled Runs',
		value: 8,
		icon: 'i-lucide-calendar-range',
		help: '3 upcoming this week',
	},
	{
		label: 'Completed Runs',
		value: 42,
		icon: 'i-lucide-flag',
		help: 'Up 6 from last month',
	},
	{
		label: 'Banned Users',
		value: 2,
		icon: 'i-lucide-ban',
		help: 'Moderation only',
	},
	{
		label: 'Last Activity',
		value: '2h',
		icon: 'i-lucide-activity',
		help: 'Latest run update',
	},
	]);

const placeholderRuns = computed(() => [
	{
		title: 'Forked Tower Blood Learning Run',
		status: 'Upcoming',
		statusColor: 'primary',
		organizer: 'Yenpress',
		time: 'Today at 19:30',
		note: 'Roster draft is nearly complete.',
	},
	{
		title: 'Clear Reclears',
		status: 'Scheduled',
		statusColor: 'warning',
		organizer: 'Aetherwindrunner',
		time: 'Tomorrow at 20:00',
		note: 'Waiting on two support confirmations.',
	},
	{
		title: 'Planning Session',
		status: 'Draft',
		statusColor: 'neutral',
		organizer: 'Light Rampant',
		time: 'Friday at 18:00',
		note: 'Still in planning, not visible publicly yet.',
	},
	{
		title: 'Occult Practice Night',
		status: 'Complete',
		statusColor: 'success',
		organizer: 'Yenpress',
		time: '2 days ago',
		note: 'Good turnout and two first-time clears.',
	},
]);

const weeklyActivity = computed(() => [
	{ label: 'Mon', value: 4 },
	{ label: 'Tue', value: 7 },
	{ label: 'Wed', value: 5 },
	{ label: 'Thu', value: 9 },
	{ label: 'Fri', value: 12 },
	{ label: 'Sat', value: 8 },
	{ label: 'Sun', value: 6 },
]);

const runPipeline = computed(() => [
	{ label: 'Draft', value: 18, color: 'bg-neutral-400 dark:bg-neutral-500' },
	{ label: 'Scheduled', value: 34, color: 'bg-amber-500' },
	{ label: 'Upcoming', value: 28, color: 'bg-blue-500' },
	{ label: 'Complete', value: 20, color: 'bg-emerald-500' },
]);

const readinessBreakdown = computed(() => [
	{ label: 'Ready', value: 71, color: 'bg-emerald-500' },
	{ label: 'Needs setup', value: 21, color: 'bg-amber-500' },
	{ label: 'Inactive', value: 8, color: 'bg-neutral-400 dark:bg-neutral-500' },
]);

const managementCards = computed(() => [
	{
		title: 'Invite Links',
		subtitle: 'Quick access for member onboarding',
		icon: 'i-lucide-link',
		items: [
			{ label: 'System invite', value: props.group.is_public ? 'Active' : 'Disabled' },
			{ label: 'Custom invites', value: '3 active' },
			{ label: 'Last invite used', value: '4 hours ago' },
		],
	},
	{
		title: 'Group Setup',
		subtitle: 'Configuration health at a glance',
		icon: 'i-lucide-settings-2',
		items: [
			{ label: 'Profile picture', value: props.group.profile_picture_url ? 'Set' : 'Missing' },
			{ label: 'Discord invite', value: props.group.discord_invite_url ? 'Set' : 'Missing' },
			{ label: 'Visibility', value: props.group.is_visible ? 'Discoverable' : 'Hidden' },
		],
	},
	{
		title: 'Moderation Snapshot',
		subtitle: 'Useful signals for owners and moderators',
		icon: 'i-lucide-shield-check',
		items: [
			{ label: 'Pending roster review', value: '5 members' },
			{ label: 'Members without characters', value: '2' },
			{ label: 'Latest moderation action', value: 'Ban updated yesterday' },
		],
	},
	{
		title: 'Activity Pulse',
		subtitle: 'Quick operational context',
		icon: 'i-lucide-activity',
		items: [
			{ label: 'Last run activity', value: '2 hours ago' },
			{ label: 'Last member join', value: '3 days ago' },
			{ label: 'This week engagement', value: 'High' },
		],
	},
]);
</script>

<template>
	<div class="w-full">
		<PageHeader :title="group.name" :subtitle="group.description || t('groups.index.subtitle')">
			<UBadge
				size="lg"
				variant="subtle"
				class="min-w-44 justify-center py-2"
				:color="roleBadge.color"
				:icon="roleBadge.icon"
				:label="roleBadge.label"
			/>
		</PageHeader>

		<div class="mt-4 flex flex-col gap-6">
			<UCard class="dark:bg-elevated/25 overflow-hidden">
				<div class="grid grid-cols-[1.35fr_0.85fr]">
					<div class="relative px-8 py-8">
						<div class="absolute inset-0 bg-gradient-to-br from-primary/8 via-transparent to-emerald-500/8 pointer-events-none" />
						<div class="relative flex flex-col gap-8">
							<div class="flex items-start justify-between gap-6">
								<div class="flex items-start gap-5">
									<div v-if="group.profile_picture_url" class="h-24 w-24 overflow-hidden rounded-sm border border-default bg-muted/30 shadow-sm">
										<img
											:src="group.profile_picture_url"
											:alt="`${group.name} profile picture`"
											class="h-full w-full object-cover"
										>
									</div>
									<div v-else class="flex h-24 w-24 items-center justify-center rounded-sm border border-default bg-muted/20 shadow-sm">
										<UIcon name="i-lucide-users" size="30" class="text-muted" />
									</div>

									<div class="flex max-w-2xl flex-col gap-4">
										<div class="flex flex-wrap items-center gap-2">
											<UBadge
												:label="group.is_public ? 'Public Group' : 'Private Group'"
												:icon="group.is_public ? 'i-lucide-globe' : 'i-lucide-lock'"
												:color="group.is_public ? 'primary' : 'neutral'"
												variant="subtle"
											/>
											<UBadge
												:label="group.is_visible ? 'Visible in Discovery' : 'Hidden from Discovery'"
												:color="group.is_visible ? 'success' : 'neutral'"
												variant="subtle"
											/>
											<UBadge
												:label="group.datacenter"
												color="neutral"
												variant="subtle"
												icon="i-lucide-map-pinned"
											/>
										</div>

										<div class="space-y-2">
											<p class="text-lg font-semibold text-toned">Group identity and operating context</p>
											<p class="max-w-2xl text-sm leading-6 text-muted">
												This area is meant to answer the immediate dashboard questions:
												what kind of group this is, where it operates, who owns it, and whether its setup looks complete.
											</p>
										</div>
									</div>
								</div>

								<div class="flex flex-wrap justify-end gap-2">
									<UButton color="neutral" variant="subtle" icon="i-lucide-link" label="Invite Preview" />
									<UButton color="primary" icon="i-lucide-calendar-plus" label="Quick Schedule" />
								</div>
							</div>

							<div class="grid grid-cols-4 gap-4">
								<div class="rounded-sm border border-default bg-background/80 px-4 py-4">
									<p class="text-xs uppercase tracking-wide text-muted">Owner</p>
									<p class="mt-2 text-base font-semibold text-toned">{{ group.owner?.name || 'Unassigned' }}</p>
								</div>
								<div class="rounded-sm border border-default bg-background/80 px-4 py-4">
									<p class="text-xs uppercase tracking-wide text-muted">Claimed Slug</p>
									<p class="mt-2 text-base font-semibold text-toned">{{ group.slug }}</p>
								</div>
								<div class="rounded-sm border border-default bg-background/80 px-4 py-4">
									<p class="text-xs uppercase tracking-wide text-muted">Discord</p>
									<p class="mt-2 text-base font-semibold text-toned">{{ group.discord_invite_url ? 'Configured' : 'Not set' }}</p>
								</div>
								<div class="rounded-sm border border-default bg-background/80 px-4 py-4">
									<p class="text-xs uppercase tracking-wide text-muted">Status</p>
									<p class="mt-2 text-base font-semibold text-toned">Healthy and active</p>
								</div>
							</div>
						</div>
					</div>

					<div class="border-l border-default bg-muted/10 px-6 py-8">
						<div class="flex h-full flex-col gap-6">
							<div class="space-y-2">
								<p class="text-sm font-semibold text-toned">Quick Summary</p>
								<p class="text-sm leading-6 text-muted">
									This panel is the short version of the dashboard. It is meant to be instantly readable, not a second mystery widget.
								</p>
							</div>

							<div class="space-y-4">
								<div class="space-y-3">
									<div class="flex items-center justify-between text-sm">
										<span class="text-muted">Roster readiness</span>
										<span class="font-medium text-toned">82%</span>
									</div>
									<UProgress :model-value="82" color="primary" />
								</div>

								<div class="space-y-3">
									<div class="flex items-center justify-between text-sm">
										<span class="text-muted">Configuration health</span>
										<span class="font-medium text-toned">91%</span>
									</div>
									<UProgress :model-value="91" color="success" />
								</div>

								<div class="space-y-3">
									<div class="flex items-center justify-between text-sm">
										<span class="text-muted">Community activity</span>
										<span class="font-medium text-toned">High</span>
									</div>
									<UProgress :model-value="74" color="warning" />
								</div>
							</div>

							<div class="rounded-sm border border-default bg-background/80 px-4 py-4">
								<p class="text-xs uppercase tracking-wide text-muted">Read This As</p>
								<p class="mt-2 text-sm leading-6 text-muted">
									“If I just arrived on this page, would I understand whether the group is healthy, configured, and actively organizing runs?”
								</p>
							</div>
						</div>
					</div>
				</div>
			</UCard>

			<UCard class="dark:bg-elevated/25">
				<div class="grid grid-cols-6 gap-4">
					<div
						v-for="stat in placeholderStats"
						:key="stat.label"
						class="rounded-sm border border-default bg-muted/15 px-4 py-4"
					>
						<div class="flex items-center justify-between gap-3">
							<div class="flex flex-col gap-1">
								<p class="text-sm text-muted">{{ stat.label }}</p>
								<p class="text-2xl font-bold">{{ stat.value }}</p>
							</div>
							<div class="flex h-10 w-10 items-center justify-center rounded-sm border border-default bg-background">
								<UIcon :name="stat.icon" size="18" class="text-muted" />
							</div>
						</div>
						<p class="mt-3 text-xs text-muted">{{ stat.help }}</p>
					</div>
				</div>
			</UCard>

			<div class="grid grid-cols-3 gap-6">
				<UCard class="dark:bg-elevated/25">
					<template #header>
						<div class="flex items-center justify-between gap-4">
							<div class="flex flex-col gap-1">
								<p class="font-semibold text-md">Weekly Activity</p>
								<p class="text-sm text-muted">Placeholder graph for run and member interaction volume.</p>
							</div>
							<div class="text-right">
								<p class="text-lg font-semibold text-toned">51 actions</p>
								<UBadge label="+18%" color="success" variant="subtle" />
							</div>
						</div>
					</template>

					<div class="flex h-52 items-end gap-3">
						<div
							v-for="day in weeklyActivity"
							:key="day.label"
							class="flex flex-1 flex-col items-center justify-end gap-3"
						>
							<div class="w-full min-h-[18px] rounded-t-sm bg-gradient-to-t from-primary to-emerald-400/80" :style="{ height: `${day.value * 10}px` }" />
							<p class="text-xs text-muted">{{ day.label }}</p>
						</div>
					</div>
				</UCard>

				<UCard class="dark:bg-elevated/25">
					<template #header>
						<div class="flex flex-col gap-1">
							<p class="font-semibold text-md">Run Pipeline</p>
							<p class="text-sm text-muted">How placeholder runs are distributed by stage.</p>
						</div>
					</template>

					<div class="space-y-4">
						<div class="flex h-4 w-full overflow-hidden rounded-full bg-muted/30">
							<div
								v-for="segment in runPipeline"
								:key="segment.label"
								:class="segment.color"
								:style="{ width: `${segment.value}%` }"
							/>
						</div>

						<div class="space-y-3">
							<div
								v-for="segment in runPipeline"
								:key="segment.label"
								class="flex items-center justify-between gap-3"
							>
								<div class="flex items-center gap-2">
									<div class="h-2.5 w-2.5 rounded-full" :class="segment.color" />
									<p class="text-sm text-muted">{{ segment.label }}</p>
								</div>
								<p class="text-sm font-medium text-toned">{{ segment.value }}%</p>
							</div>
						</div>
					</div>
				</UCard>

				<UCard class="dark:bg-elevated/25">
					<template #header>
						<div class="flex flex-col gap-1">
							<p class="font-semibold text-md">Member Readiness</p>
							<p class="text-sm text-muted">A simple placeholder split for linked-character health.</p>
						</div>
					</template>

					<div class="space-y-4">
						<div class="flex h-4 w-full overflow-hidden rounded-full bg-muted/30">
							<div
								v-for="segment in readinessBreakdown"
								:key="segment.label"
								:class="segment.color"
								:style="{ width: `${segment.value}%` }"
							/>
						</div>

						<div class="space-y-3">
							<div
								v-for="segment in readinessBreakdown"
								:key="segment.label"
								class="flex items-center justify-between gap-3"
							>
								<div class="flex items-center gap-2">
									<div class="h-2.5 w-2.5 rounded-full" :class="segment.color" />
									<p class="text-sm text-muted">{{ segment.label }}</p>
								</div>
								<p class="text-sm font-medium text-toned">{{ segment.value }}%</p>
							</div>
						</div>
					</div>
				</UCard>
			</div>

			<div class="grid grid-cols-2 gap-6">
				<UCard class="dark:bg-elevated/25">
					<template #header>
						<div class="flex items-center justify-between gap-4">
							<div class="flex flex-col gap-1">
								<p class="font-semibold text-md">Recent Runs</p>
								<p class="text-sm text-muted">A quick read on what the group is actively organizing.</p>
							</div>
							<UButton color="neutral" variant="ghost" icon="i-lucide-arrow-right" label="View All" />
						</div>
					</template>

					<div class="flex flex-col">
						<div
							v-for="(run, index) in placeholderRuns"
							:key="run.title"
							class="flex items-start justify-between gap-4 px-1 py-4"
							:class="index === 0 ? '' : 'border-t border-default'"
						>
							<div class="min-w-0">
								<div class="flex flex-wrap items-center gap-2">
									<p class="font-medium">{{ run.title }}</p>
									<UBadge :label="run.status" :color="run.statusColor" variant="subtle" />
								</div>
								<p class="mt-1 text-sm text-muted">{{ run.note }}</p>
							</div>
							<div class="shrink-0 text-right text-sm text-muted">
								<p>{{ run.time }}</p>
								<p class="mt-1">by {{ run.organizer }}</p>
							</div>
						</div>
					</div>
				</UCard>

				<UCard class="dark:bg-elevated/25">
					<template #header>
						<div class="flex items-center justify-between gap-4">
							<div class="flex flex-col gap-1">
								<p class="font-semibold text-md">Operational Notes</p>
								<p class="text-sm text-muted">A placeholder panel for summaries, quick wins, and admin-facing context.</p>
							</div>
							<UButton color="neutral" variant="ghost" icon="i-lucide-settings-2" label="Open Settings" />
						</div>
					</template>

					<div class="space-y-4">
						<div
							v-for="(card, index) in managementCards"
							:key="card.title"
							class="rounded-sm border border-default bg-muted/10 px-4 py-4"
						>
							<div class="flex items-start gap-3">
								<div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-sm border border-default bg-background">
									<UIcon :name="card.icon" size="16" class="text-muted" />
								</div>
								<div class="min-w-0 flex-1">
									<div class="flex items-center justify-between gap-3">
										<div>
											<p class="font-medium text-toned">{{ card.title }}</p>
											<p class="mt-1 text-sm text-muted">{{ card.subtitle }}</p>
										</div>
										<UBadge :label="index === 0 ? 'Healthy' : index === 1 ? 'Configured' : index === 2 ? 'Needs Review' : 'Live'" color="neutral" variant="subtle" />
									</div>

									<div class="mt-4 grid grid-cols-3 gap-3">
										<div
											v-for="item in card.items"
											:key="item.label"
											class="rounded-sm border border-default bg-background/80 px-3 py-3"
										>
											<p class="text-xs uppercase tracking-wide text-muted">{{ item.label }}</p>
											<p class="mt-2 text-sm font-medium text-toned">{{ item.value }}</p>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				</UCard>
			</div>
		</div>
	</div>
</template>

<style scoped>

</style>
