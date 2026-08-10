<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import ActivityContextMenu from "@/components/Groups/Activities/ActivityContextMenu.vue";
import ActivityHostGroupBadge from "@/components/Groups/Activities/ActivityHostGroupBadge.vue";
import { localizedValue } from "@/utils/localizedValue";
import type { ActivityIndexItem } from "@/Types/ActivityCore";
import { getActivityStatusMeta } from "@/utils/activityStatusMeta";
import { createDateTimeFormatter, createRelativeTimeFormatter } from "@/utils/dateTimeFormat";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";

const props = defineProps<{
	groupSlug?: string
	canManageActivities: boolean
	activity: ActivityIndexItem
	showGroupBadge?: boolean
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));
const { withDisplayTimeZone } = useTimeDisplayMode();

const activityDate = computed(() => props.activity.starts_at ? new Date(props.activity.starts_at) : null);

const activityTypeName = computed(() => {
	return localizedValue(props.activity.activity_type?.draft_name, locale.value, fallbackLocale.value)
		|| props.activity.activity_type?.slug
		|| t('groups.activities.cards.unknown_type');
});

const activityTitle = computed(() => props.activity.title || activityTypeName.value);

const targetProgPointLabel = computed(() => (
	props.activity.target_prog_point_key
		? localizedValue(props.activity.target_prog_point_label, locale.value, fallbackLocale.value) || props.activity.target_prog_point_key
		: null
));

const dateParts = computed(() => {
	if (!activityDate.value) {
		return {
			weekday: t('groups.activities.cards.unscheduled'),
			day: '—',
			month: '',
		};
	}

	return {
		weekday: createDateTimeFormatter(locale.value, withDisplayTimeZone({ weekday: 'short' })).format(activityDate.value),
		day: createDateTimeFormatter(locale.value, withDisplayTimeZone({ day: 'numeric' })).format(activityDate.value),
		month: createDateTimeFormatter(locale.value, withDisplayTimeZone({ month: 'short' })).format(activityDate.value),
	};
});

const startsAtLabel = computed(() => {
	if (!activityDate.value) {
		return t('groups.activities.cards.no_time');
	}

	return createDateTimeFormatter(locale.value, withDisplayTimeZone({
		weekday: 'long',
		day: 'numeric',
		month: 'long',
		hour: '2-digit',
		minute: '2-digit',
	})).format(activityDate.value);
});

const relativeLabel = computed(() => {
	if (!activityDate.value) {
		return t('groups.activities.cards.no_relative_time');
	}

	const diffMs = activityDate.value.getTime() - Date.now();
	const diffMinutes = Math.round(diffMs / (1000 * 60));
	const absoluteMinutes = Math.abs(diffMinutes);
	const formatter = createRelativeTimeFormatter(locale.value, { numeric: 'auto' });

	if (absoluteMinutes < 60) {
		return formatter.format(diffMinutes, 'minute');
	}

	const diffHours = Math.round(diffMinutes / 60);
	const absoluteHours = Math.abs(diffHours);

	if (absoluteHours < 24) {
		return formatter.format(diffHours, 'hour');
	}

	const diffDays = Math.round(diffHours / 24);

	return formatter.format(diffDays, 'day');
});

const statusMeta = computed(() => getActivityStatusMeta(props.activity.status));
const resolvedGroupSlug = computed(() => props.activity.group?.slug ?? props.groupSlug ?? '');
const canManageActivity = computed(() => props.activity.group?.can_manage_activities ?? props.canManageActivities);

const goToManagementPage = () => {
	if (!canManageActivity.value) {
		router.get(route('groups.activities.overview', {
			group: resolvedGroupSlug.value,
			activity: props.activity.id,
		}));

		return;
	}

	router.get(route('groups.dashboard.activities.show', {
		group: resolvedGroupSlug.value,
		activity: props.activity.id,
	}));
};
</script>

<template>
	<ActivityContextMenu
		:group-slug="resolvedGroupSlug"
		:can-manage-activities="canManageActivity"
		:activity="activity"
	>
		<div
			class="relative cursor-pointer overflow-visible rounded-sm border border-default bg-elevated/50 px-4 py-4 transition hover:border-primary/40 hover:bg-primary/5 hover:shadow-sm"
			role="button"
			tabindex="0"
			@click="goToManagementPage"
			@keydown.enter.prevent="goToManagementPage"
			@keydown.space.prevent="goToManagementPage"
		>
			<div
				v-if="activity.has_existing_application"
				class="pointer-events-none absolute -left-2 -top-2 z-20 flex h-7 w-7 items-center justify-center"
				:aria-label="t('groups.dashboard.upcoming_runs.view_application')"
				:title="t('groups.dashboard.upcoming_runs.view_application')"
			>
				<UIcon
					name="i-lucide-pin"
					class="h-7 w-7 -rotate-35 text-brand-400 drop-shadow-[0_4px_10px_rgba(168,85,247,0.8)]"
				/>
			</div>

			<div class="flex flex-col gap-4 xl:flex-row xl:items-start">
				<div class="flex h-20 w-20 shrink-0 flex-col items-center justify-center rounded-sm border border-default bg-background text-center">
					<p class="text-xs font-semibold uppercase tracking-[0.16em] text-muted">
						{{ dateParts.weekday }}
					</p>
					<p class="mt-1 text-2xl font-black text-toned">
						{{ dateParts.day }}
					</p>
					<p class="text-xs uppercase tracking-wide text-muted">
						{{ dateParts.month }}
					</p>
				</div>

				<div class="min-w-0 flex-1">
					<div class="flex flex-col gap-3">
						<div class="flex flex-wrap items-start justify-between gap-3">
							<div class="min-w-0">
								<div class="flex flex-wrap items-center gap-2">
									<h3 class="break-words [overflow-wrap:anywhere] text-base font-semibold text-toned">
										{{ activityTitle }}
									</h3>
									<UBadge
										v-if="targetProgPointLabel"
										:label="targetProgPointLabel"
										color="neutral"
										variant="soft"
										size="md"
									/>
									<UBadge
										:label="t(`groups.activities.statuses.${activity.status}`)"
										:color="statusMeta.color"
										:icon="statusMeta.icon"
										variant="subtle"
									/>
								</div>
								<p class="mt-1 break-words [overflow-wrap:anywhere] text-sm text-muted">
									{{ activityTypeName }}
								</p>
								<ActivityHostGroupBadge
									v-if="showGroupBadge"
									class="mt-2"
									:group="activity.group"
								/>
							</div>

							<div class="flex w-full flex-col gap-2 text-sm xl:items-end">
								<p class="font-medium text-toned xl:text-right">
									{{ startsAtLabel }}
								</p>
								<p class="text-muted xl:text-right">
									{{ relativeLabel }}
								</p>
							</div>
						</div>

						<div class="flex flex-wrap items-center justify-between gap-4 text-sm text-muted">
							<div class="flex items-center gap-2">
								<UIcon name="i-lucide-user-round" class="text-base" />
								<span>{{ activity.organized_by?.name || t('groups.activities.cards.no_organizer') }}</span>
							</div>
							<div class="flex items-center gap-2">
								<UIcon name="i-lucide-users-round" class="text-base" />
								<span>{{ t('groups.activities.cards.slots', { count: activity.slot_count }) }}</span>
							</div>
							<div class="flex items-center gap-2">
								<UIcon name="i-lucide-file-text" class="text-base" />
								<span>{{ t('groups.activities.cards.applications', { count: activity.application_count }) }}</span>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</ActivityContextMenu>
</template>
