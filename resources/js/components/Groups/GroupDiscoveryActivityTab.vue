<script setup lang="ts">
import type { GroupDiscoveryDetailRecord } from "@/Types/Groups";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { useDateTimeFormat } from "@/composables/useDateTimeFormat";
import { toDisplayDateKey } from '@/utils/activityCalendar';

const { formatDateTime, displayTimeZone, timeDisplayLabel } = useDateTimeFormat();

const props = defineProps<{
	group: GroupDiscoveryDetailRecord
}>();

const { t } = useI18n();

const activitySummaryItems = computed(() => [
	{
		key: "completed_runs",
		label: t("groups.index.discovery.detail.completed_runs"),
		value: String(props.group.activity_summary.completed_runs),
	},
	{
		key: "total_runs",
		label: t("groups.index.discovery.detail.total_runs"),
		value: String(props.group.activity_summary.total_runs),
	},
	{
		key: "runs_per_week",
		label: t("groups.index.discovery.detail.runs_per_week"),
		value: formatStatNumber(props.group.activity_summary.runs_per_week),
	},
	{
		key: "average_turnout",
		label: t("groups.index.discovery.detail.average_turnout"),
		value: formatStatNumber(props.group.activity_summary.average_turnout),
	},
]);

const recentRunTimelineItems = computed(() => {
	let currentSide: "left" | "right" = "left";
	let previousDayKey: string | null = null;

	return props.group.recent_runs.map((run) => {
		const dayKey = dayGroupingKey(run.starts_at);

		if (previousDayKey !== null && dayKey !== previousDayKey) {
			currentSide = currentSide === "left" ? "right" : "left";
		}

		previousDayKey = dayKey;

		return {
			...run,
			side: currentSide,
			dateLabel: formatTimelineDate(run.starts_at),
			statusLabel: t(`groups.index.discovery.detail.statuses.${run.status}`),
			turnoutLabel: t("groups.index.discovery.detail.showed_up", { count: run.turnout_count }),
		};
	});
});

function formatTimelineDate(value: string | null) {
	if (!value) {
		return "—";
	}

	const formatted = formatDateTime(new Date(value), {
		year: "numeric",
		month: "2-digit",
		day: "2-digit",
		hour: "numeric",
		minute: "2-digit",
	});

	return `${formatted} ${timeDisplayLabel.value}`;
}

function dayGroupingKey(value: string | null) {
	if (!value) {
		return "unknown";
	}

	return toDisplayDateKey(new Date(value), displayTimeZone.value);
}

function formatStatNumber(value: number) {
	return Number.isInteger(value) ? String(value) : value.toFixed(1);
}
</script>

<template>
	<div class="space-y-6">
		<section class="space-y-3">
			<p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-dimmed">
				{{ t('groups.index.discovery.detail.activity_summary') }}
			</p>
			<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
				<div
					v-for="item in activitySummaryItems"
					:key="item.key"
					class="rounded-xl border border-default bg-muted/18 dark:border-elevation-7 dark:bg-elevation-4 px-4 py-3"
				>
					<p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-dimmed">
						{{ item.label }}
					</p>
					<p class="mt-2 text-2xl font-semibold text-highlighted">
						{{ item.value }}
					</p>
				</div>
			</div>
		</section>

		<div class="h-px bg-default/80 dark:bg-elevation-6" />

		<section class="space-y-3">
			<div class="flex items-end justify-between gap-3">
				<p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-dimmed">
					{{ t('groups.index.discovery.detail.recent_runs') }}
				</p>
				<p class="text-xs text-muted">
					{{ t('groups.index.discovery.detail.recent_runs_hint') }}
				</p>
			</div>

			<UTimeline
				v-if="recentRunTimelineItems.length > 0"
				:items="recentRunTimelineItems"
				size="lg"
				color="neutral"
				class="pl-1"
				:ui="{
					item: 'grid grid-cols-1 gap-y-2 md:grid-cols-[minmax(0,1fr)_3rem_minmax(0,1fr)] md:gap-x-4',
					container: 'flex justify-center md:col-start-2 md:row-start-1',
					indicator: 'size-12 overflow-hidden rounded-xl border border-default bg-elevated shadow-sm dark:border-elevation-7 dark:bg-elevation-5',
					wrapper: 'min-w-0 md:col-[1/-1] md:row-start-1',
				}"
			>
				<template #indicator="{ item }">
					<img
						v-if="item.activity_image_url"
						:src="item.activity_image_url"
						:alt="item.activity_name"
						class="size-12 object-cover"
					>
					<div v-else class="flex size-12 items-center justify-center bg-elevated text-dimmed dark:bg-elevation-5">
						<UIcon name="i-lucide-swords" class="size-5" />
					</div>
				</template>

				<template #wrapper="{ item }">
					<div class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_3rem_minmax(0,1fr)] md:gap-x-4">
						<div
							class="min-w-0 rounded-xl border border-default bg-muted/18 dark:border-elevation-7 dark:bg-elevation-4 px-4 py-3"
							:class="item.side === 'left'
								? 'md:col-start-1 md:text-right'
								: 'md:col-start-3 md:text-left'"
						>
							<p class="text-xs font-medium uppercase tracking-[0.12em] text-dimmed">
								{{ item.dateLabel }}
							</p>

							<div
								class="mt-2 flex flex-wrap items-center gap-2"
								:class="item.side === 'left' ? 'md:justify-end' : 'md:justify-start'"
							>
								<span class="text-sm font-semibold text-highlighted">
									{{ item.activity_name }}
								</span>
								<span class="text-xs font-medium uppercase tracking-[0.12em] text-muted">
									{{ item.statusLabel }}
								</span>
							</div>

							<p v-if="item.run_title" class="mt-2 text-sm text-muted break-words [overflow-wrap:anywhere]">
								{{ item.run_title }}
							</p>

							<div
								class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-toned"
								:class="item.side === 'left' ? 'md:justify-end' : 'md:justify-start'"
							>
								<span>{{ item.turnoutLabel }}</span>
								<span v-if="item.progress_summary">
									{{ t('groups.index.discovery.detail.progress') }}: {{ item.progress_summary }}
								</span>
							</div>
						</div>
					</div>
				</template>
			</UTimeline>

			<p v-else class="text-sm text-muted">
				{{ t('groups.index.discovery.detail.no_recent_runs') }}
			</p>
		</section>
	</div>
</template>
