<script setup lang="ts">
import type { GroupMemberActivitySummary, GroupMemberActivitySummaryRun } from "@/Types/Groups";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";
import { translateCharacterClassName, translatePhantomJobName } from "@/utils/characterJobTranslations";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";

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

const sections = computed(() => [
	{
		key: "last_group_run",
		title: t("groups.members.activity_summary.last_group_run"),
		empty: t("groups.members.activity_summary.empty_group"),
		run: props.summary?.last_group_run ?? null,
	},
	{
		key: "last_run",
		title: t("groups.members.activity_summary.last_run"),
		empty: t("groups.members.activity_summary.empty_any"),
		run: props.summary?.last_run ?? null,
	},
]);

const runDisplayName = (run: GroupMemberActivitySummaryRun) => (
	run.title || run.activity_type_name || t("groups.members.activity_summary.unknown_activity")
);

const dateSource = (run: GroupMemberActivitySummaryRun) => run.starts_at ?? run.completed_at;

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
	<div class="border-t border-default bg-muted/5 p-4">
		<div v-if="loading" class="space-y-4">
			<div v-for="item in 2" :key="item" class="space-y-4 border border-primary/30 bg-primary/5 p-4">
				<div class="flex items-center gap-3">
					<USkeleton class="h-8 w-8" />
					<USkeleton class="h-4 w-56" />
				</div>
				<div class="grid gap-4 lg:grid-cols-[6rem_minmax(0,1fr)]">
					<USkeleton class="h-24 w-24" />
					<div class="space-y-3">
						<USkeleton class="h-6 w-2/3" />
						<USkeleton class="h-4 w-80 max-w-full" />
						<USkeleton class="h-px w-full" />
						<div class="flex gap-3">
							<USkeleton class="h-12 w-12" />
							<div class="flex-1 space-y-2">
								<USkeleton class="h-4 w-48" />
								<USkeleton class="h-3 w-36" />
							</div>
						</div>
					</div>
				</div>
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

		<div v-else class="space-y-4">
			<section
				v-for="section in sections"
				:key="section.key"
				class="border border-primary/40 bg-primary/5 p-4 shadow-sm shadow-primary/5"
			>
				<div class="flex items-center gap-3">
					<div class="flex h-8 w-8 shrink-0 items-center justify-center border border-primary/35 bg-primary/15">
						<UIcon name="i-lucide-history" class="size-4 text-primary" />
					</div>
					<p class="text-xs font-semibold uppercase tracking-[0.16em] text-muted">
						{{ section.title }}
					</p>
				</div>

				<div v-if="section.run" class="mt-4 grid gap-4 lg:grid-cols-[6rem_minmax(0,1fr)_auto] lg:items-center">
					<div v-if="section.run.activity_icon_url" class="h-20 w-20 shrink-0 overflow-hidden border border-default bg-muted/30 lg:h-24 lg:w-24">
						<img
							:src="section.run.activity_icon_url"
							:alt="`${runDisplayName(section.run)} icon`"
							class="h-full w-full object-cover"
							loading="lazy"
						>
					</div>
					<div v-else class="flex h-20 w-20 shrink-0 items-center justify-center border border-default bg-muted/20 lg:h-24 lg:w-24">
						<UIcon name="i-lucide-swords" class="size-7 text-muted" />
					</div>

					<div class="min-w-0 space-y-3">
						<div class="min-w-0">
							<p class="break-words text-lg font-semibold leading-tight text-toned [overflow-wrap:anywhere]">
								{{ runDisplayName(section.run) }}
							</p>
							<p v-if="section.run.activity_type_name" class="mt-1 text-sm text-muted">
								{{ section.run.activity_type_name }}
							</p>
						</div>

						<div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-muted">
							<span class="inline-flex items-center gap-1.5">
								<UIcon name="i-lucide-calendar-days" class="size-4" />
								{{ formatDate(dateSource(section.run)) }}
							</span>
							<span class="inline-flex items-center gap-1.5">
								<UIcon name="i-lucide-clock" class="size-4" />
								{{ formatTime(dateSource(section.run)) }}
							</span>
						</div>

						<div class="border-t border-default"></div>

						<div class="flex min-w-0 flex-col gap-4 xl:flex-row xl:items-center">
							<div class="flex min-w-0 items-center gap-3 xl:min-w-64">
								<div v-if="section.run.character.avatar_url" class="h-10 w-10 shrink-0 overflow-hidden border border-default bg-muted/30">
									<img
										:src="section.run.character.avatar_url"
										:alt="`${section.run.character.name} avatar`"
										class="h-full w-full object-cover"
										loading="lazy"
									>
								</div>
								<div v-else class="flex h-10 w-10 shrink-0 items-center justify-center border border-default bg-primary/10 text-sm font-semibold text-toned">
									{{ section.run.character.name.slice(0, 2).toUpperCase() }}
								</div>

								<div class="min-w-0">
									<p class="truncate font-medium text-toned">{{ section.run.character.name }}</p>
									<p class="truncate text-sm text-muted">{{ characterWorld(section.run) }}</p>
								</div>
							</div>

							<div class="hidden h-10 border-l border-default xl:block"></div>

							<div class="flex flex-wrap gap-2">
								<span class="inline-flex items-center gap-1.5 border border-default bg-muted/10 px-3 py-1.5 text-sm font-medium text-toned">
									<img
										v-if="classIconUrl(section.run)"
										:src="classIconUrl(section.run) || undefined"
										:alt="characterClassLabel(section.run)"
										class="size-5 object-contain"
										loading="lazy"
									>
									<UIcon v-else name="i-lucide-circle-help" class="size-5" />
									{{ characterClassLabel(section.run) }}
								</span>

								<span
									v-if="phantomJobLabel(section.run)"
									class="inline-flex items-center gap-1.5 border border-default bg-muted/10 px-3 py-1.5 text-sm font-medium text-toned"
								>
									<img
										v-if="phantomJobIconUrl(section.run)"
										:src="phantomJobIconUrl(section.run) || undefined"
										:alt="phantomJobLabel(section.run) || ''"
										class="size-5 object-contain"
										loading="lazy"
									>
									<UIcon v-else name="i-lucide-sparkles" class="size-5" />
									{{ phantomJobLabel(section.run) }}
								</span>
							</div>
						</div>
					</div>

					<div v-if="section.run.group" class="lg:self-start">
						<UBadge
							color="neutral"
							variant="outline"
							size="lg"
							icon="i-lucide-users"
							:label="section.run.group.name"
						/>
					</div>
				</div>

				<div v-else class="mt-5 flex min-h-28 items-center justify-center border border-dashed border-default bg-muted/10 px-4 py-6 text-center">
					<p class="text-sm text-muted">{{ section.empty }}</p>
				</div>
			</section>
		</div>
	</div>
</template>
