<script setup lang="ts">
import type { GroupMemberActivitySummary, GroupMemberActivitySummaryRun } from "@/Types/Groups";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";
import { translateCharacterClassName, translatePhantomJobName } from "@/utils/characterJobTranslations";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";

const millisecondsPerDay = 24 * 60 * 60 * 1000;

const props = defineProps<{
	summary: GroupMemberActivitySummary | null
	loading: boolean
	error: boolean
}>();

const emit = defineEmits<{
	retry: []
}>();

const { locale, t } = useI18n();
const { withDisplayTimeZone } = useTimeDisplayMode();

const recentRuns = computed(() => props.summary?.recent_runs ?? []);
const recentRunCountLabel = computed(() => t("groups.members.activity_summary.recent_runs_count", {
	count: recentRuns.value.length,
}));

const runDisplayName = (run: GroupMemberActivitySummaryRun) => (
	run.title || run.activity_type_name || t("groups.members.activity_summary.unknown_activity")
);

const dateSource = (run: GroupMemberActivitySummaryRun) => run.completed_at ?? run.starts_at;

const formatDate = (value: string | null) => {
	if (!value) {
		return t("groups.members.roster.not_available");
	}

	const date = new Date(value);

	if (Number.isNaN(date.getTime())) {
		return t("groups.members.roster.not_available");
	}

	return createDateTimeFormatter(locale.value, withDisplayTimeZone({
		day: "2-digit",
		month: "short",
		year: "numeric",
	})).format(date);
};

const formatTime = (value: string | null) => {
	if (!value) {
		return t("groups.members.roster.not_available");
	}

	const date = new Date(value);

	if (Number.isNaN(date.getTime())) {
		return t("groups.members.roster.not_available");
	}

	return createDateTimeFormatter(locale.value, withDisplayTimeZone({
		hour: "2-digit",
		minute: "2-digit",
	})).format(date);
};

const daysAgo = (value: string | null) => {
	if (!value) {
		return null;
	}

	const date = new Date(value);

	if (Number.isNaN(date.getTime())) {
		return null;
	}

	const today = new Date();
	const todayDay = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate());
	const runDay = Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());

	return Math.max(0, Math.floor((todayDay - runDay) / millisecondsPerDay));
};

const formatDaysAgo = (value: string | null) => {
	const days = daysAgo(value);

	if (days === null) {
		return t("groups.members.roster.not_available");
	}

	return days === 1
		? t("groups.members.activity_summary.day_ago")
		: t("groups.members.activity_summary.days_ago", { count: days });
};

const characterWorld = (run: GroupMemberActivitySummaryRun) => [
	run.character.world,
	run.character.datacenter,
].filter(Boolean).join(" - ");

const characterClassLabel = (run: GroupMemberActivitySummaryRun) => run.character_class
	? translateCharacterClassName(t, run.character_class, run.character_class.name)
	: t("groups.members.activity_summary.not_recorded");

const phantomJobLabel = (run: GroupMemberActivitySummaryRun) => run.phantom_job
	? translatePhantomJobName(t, run.phantom_job, run.phantom_job.name)
	: null;

const classIconUrl = (run: GroupMemberActivitySummaryRun) => (
	run.character_class?.icon_url || run.character_class?.flaticon_url || null
);

const phantomJobIconUrl = (run: GroupMemberActivitySummaryRun) => (
	run.phantom_job?.transparent_icon_url || run.phantom_job?.icon_url || null
);
</script>

<template>
	<div class="space-y-4">
		<div v-if="loading" class="space-y-3">
			<div v-for="item in 6" :key="item" class="grid gap-3 border border-primary/25 bg-primary/5 p-3 sm:grid-cols-[3.5rem_minmax(0,1fr)_7rem] sm:items-center">
				<USkeleton class="h-14 w-14" />
				<div class="space-y-2">
					<USkeleton class="h-4 w-2/3" />
					<USkeleton class="h-3 w-1/2" />
					<USkeleton class="h-px w-full" />
					<USkeleton class="h-6 w-4/5" />
				</div>
				<USkeleton class="h-7 w-24 sm:justify-self-end" />
			</div>
		</div>

		<div v-else-if="error" class="flex flex-col items-start gap-3 border border-error/30 bg-error/10 p-4 sm:flex-row sm:items-center sm:justify-between">
			<div>
				<p class="font-medium text-error">{{ t("groups.members.activity_summary.load_error") }}</p>
				<p class="text-sm text-muted">{{ t("groups.members.activity_summary.load_error_hint") }}</p>
			</div>
			<UButton
				color="error"
				variant="subtle"
				icon="i-lucide-refresh-cw"
				:label="t('groups.members.activity_summary.retry')"
				@click="emit('retry')"
			/>
		</div>

		<div v-else-if="recentRuns.length === 0" class="flex min-h-48 items-center justify-center border border-dashed border-default bg-muted/10 px-4 py-8 text-center">
			<p class="text-sm text-muted">{{ t("groups.members.activity_summary.empty_recent") }}</p>
		</div>

		<div v-else class="space-y-3">
			<div class="flex flex-col gap-2 border border-default bg-muted/10 px-3 py-2 sm:flex-row sm:items-center sm:justify-between">
				<div class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-muted">
					<UIcon name="i-lucide-history" class="size-4 text-primary" />
					{{ t("groups.members.activity_summary.recent_runs") }}
				</div>
				<UBadge color="neutral" variant="subtle" :label="recentRunCountLabel" />
			</div>

			<div
				v-for="run in recentRuns"
				:key="run.id"
				class="grid gap-3 border border-primary/35 bg-primary/5 p-3 shadow-sm shadow-primary/5 transition hover:border-primary/55 hover:bg-primary/10 sm:grid-cols-[3.5rem_minmax(0,1fr)_minmax(7rem,auto)] sm:items-start"
			>
				<div v-if="run.activity_icon_url" class="h-14 w-14 shrink-0 overflow-hidden border border-default bg-muted/30">
					<img
						:src="run.activity_icon_url"
						:alt="`${runDisplayName(run)} icon`"
						class="h-full w-full object-cover"
						loading="lazy"
					>
				</div>
				<div v-else class="flex h-14 w-14 shrink-0 items-center justify-center border border-default bg-muted/20">
					<UIcon name="i-lucide-swords" class="size-5 text-muted" />
				</div>

				<div class="min-w-0 space-y-2">
					<div class="min-w-0">
						<p class="break-words text-sm font-semibold leading-tight text-toned [overflow-wrap:anywhere] sm:text-base">
							{{ runDisplayName(run) }}
						</p>
						<p v-if="run.activity_type_name" class="mt-0.5 text-xs text-muted">
							{{ run.activity_type_name }}
						</p>
					</div>

					<div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
						<span class="inline-flex items-center gap-1.5">
							<UIcon name="i-lucide-calendar-days" class="size-3.5" />
							{{ formatDate(dateSource(run)) }}
						</span>
						<span class="inline-flex items-center gap-1.5">
							<UIcon name="i-lucide-clock" class="size-3.5" />
							{{ formatTime(dateSource(run)) }}
						</span>
					</div>

					<div class="border-t border-default"></div>

					<div class="flex min-w-0 flex-col gap-2 xl:flex-row xl:items-center">
						<div class="flex min-w-0 items-center gap-2 xl:min-w-56">
							<div v-if="run.character.avatar_url" class="h-8 w-8 shrink-0 overflow-hidden border border-default bg-muted/30">
								<img
									:src="run.character.avatar_url"
									:alt="`${run.character.name} avatar`"
									class="h-full w-full object-cover"
									loading="lazy"
								>
							</div>
							<div v-else class="flex h-8 w-8 shrink-0 items-center justify-center border border-default bg-primary/10 text-xs font-semibold text-toned">
								{{ run.character.name.slice(0, 2).toUpperCase() }}
							</div>

							<div class="min-w-0">
								<p class="truncate text-sm font-medium text-toned">{{ run.character.name }}</p>
								<p class="truncate text-xs text-muted">{{ characterWorld(run) }}</p>
							</div>
						</div>

						<div class="hidden h-8 border-l border-default xl:block"></div>

						<div class="flex flex-wrap gap-1.5">
							<span class="inline-flex items-center gap-1.5 border border-default bg-muted/10 px-2 py-1 text-xs font-medium text-toned">
								<img
									v-if="classIconUrl(run)"
									:src="classIconUrl(run) || undefined"
									:alt="characterClassLabel(run)"
									class="size-4 object-contain"
									loading="lazy"
								>
								<UIcon v-else name="i-lucide-circle-help" class="size-4" />
								{{ characterClassLabel(run) }}
							</span>

							<span
								v-if="phantomJobLabel(run)"
								class="inline-flex items-center gap-1.5 border border-default bg-muted/10 px-2 py-1 text-xs font-medium text-toned"
							>
								<img
									v-if="phantomJobIconUrl(run)"
									:src="phantomJobIconUrl(run) || undefined"
									:alt="phantomJobLabel(run) || ''"
									class="size-4 object-contain"
									loading="lazy"
								>
								<UIcon v-else name="i-lucide-sparkles" class="size-4" />
								{{ phantomJobLabel(run) }}
							</span>
						</div>
					</div>
				</div>

				<div class="flex items-center justify-between gap-2 sm:flex-col sm:items-end sm:text-right">
					<p class="whitespace-nowrap text-sm font-semibold text-primary">
						{{ formatDaysAgo(dateSource(run)) }}
					</p>
					<UBadge
						v-if="run.group"
						color="neutral"
						variant="outline"
						size="sm"
						icon="i-lucide-users"
						:label="run.group.name"
					/>
				</div>
			</div>
		</div>
	</div>
</template>
