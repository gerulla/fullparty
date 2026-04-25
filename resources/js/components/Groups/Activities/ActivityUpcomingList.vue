<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import ActivityUpcomingListItem from "@/components/Groups/Activities/ActivityUpcomingListItem.vue";
import type { ActivityIndexItem } from "@/components/Groups/Activities/types";
import { getActivityStatusDotClass } from "@/utils/activityStatusMeta";

const props = defineProps<{
	groupSlug: string
	canManageActivities: boolean
	activities: ActivityIndexItem[]
	selectedDateKey?: string | null
}>();

const { t, locale } = useI18n();

const toLocalDateKey = (date: Date) => {
	const year = date.getFullYear();
	const month = `${date.getMonth() + 1}`.padStart(2, '0');
	const day = `${date.getDate()}`.padStart(2, '0');

	return `${year}-${month}-${day}`;
};

const upcomingActivities = computed(() => {
	const now = Date.now();

	return props.activities
		.filter((activity) => {
			if (!activity.starts_at) {
				return false;
			}

			if (['complete', 'cancelled'].includes(activity.status)) {
				return false;
			}

			return new Date(activity.starts_at).getTime() >= now;
		})
		.sort((left, right) => new Date(left.starts_at ?? 0).getTime() - new Date(right.starts_at ?? 0).getTime());
});

const selectedDateActivities = computed(() => {
	if (!props.selectedDateKey) {
		return [];
	}

	return props.activities
		.filter((activity) => {
			if (!activity.starts_at) {
				return false;
			}

			return toLocalDateKey(new Date(activity.starts_at)) === props.selectedDateKey;
		})
		.sort((left, right) => new Date(left.starts_at ?? 0).getTime() - new Date(right.starts_at ?? 0).getTime());
});

const visibleActivities = computed(() => props.selectedDateKey ? selectedDateActivities.value : upcomingActivities.value);

const selectedDateLabel = computed(() => {
	if (!props.selectedDateKey) {
		return '';
	}

	const [year, month, day] = props.selectedDateKey.split('-').map(Number);

	return new Intl.DateTimeFormat(locale.value, {
		weekday: 'long',
		day: 'numeric',
		month: 'long',
		year: 'numeric',
	}).format(new Date(year, month - 1, day));
});

const statusLegendItems = computed(() => [
	{ key: 'draft', colorClass: getActivityStatusDotClass('draft') },
	{ key: 'planned', colorClass: getActivityStatusDotClass('planned') },
	{ key: 'scheduled', colorClass: getActivityStatusDotClass('scheduled') },
	{ key: 'upcoming', colorClass: getActivityStatusDotClass('upcoming') },
	{ key: 'ongoing', colorClass: getActivityStatusDotClass('ongoing') },
	{ key: 'complete', colorClass: getActivityStatusDotClass('complete') },
	{ key: 'cancelled', colorClass: getActivityStatusDotClass('cancelled') },
]);
</script>

<template>
	<UCard class="dark:bg-elevated/25" :ui="{ body: 'p-4 sm:p-4' }">
		<template #header>
			<div class="flex items-start justify-between gap-4">
				<div class="flex flex-col gap-1">
					<p class="font-semibold text-md">
						{{ selectedDateKey ? t('groups.activities.selected_day.title') : t('groups.activities.upcoming.title') }}
					</p>
					<p class="text-sm text-muted">
						{{ selectedDateKey
							? t('groups.activities.selected_day.subtitle', { date: selectedDateLabel })
							: t('groups.activities.upcoming.subtitle') }}
					</p>
				</div>
				<UBadge
					:color="selectedDateKey ? 'neutral' : 'primary'"
					variant="subtle"
					:label="selectedDateKey
						? t('groups.activities.selected_day.count', { count: visibleActivities.length })
						: t('groups.activities.upcoming.count', { count: visibleActivities.length })"
				/>
			</div>
		</template>

		<div v-if="visibleActivities.length > 0" class="flex max-h-[calc(100vh-16rem)] flex-col gap-3 overflow-y-auto pr-1">
			<ActivityUpcomingListItem
				v-for="activity in visibleActivities"
				:key="activity.id"
				:group-slug="groupSlug"
				:can-manage-activities="canManageActivities"
				:activity="activity"
			/>
		</div>

		<div v-else class="rounded-sm border border-dashed border-default bg-muted/10 px-4 py-10 text-center text-sm text-muted">
			{{ selectedDateKey ? t('groups.activities.selected_day.empty') : t('groups.activities.upcoming.empty') }}
		</div>

		<template #footer>
			<div class="flex flex-col gap-2">
				<p class="text-xs font-medium uppercase tracking-wide text-muted">
					{{ t('groups.activities.legend.title') }}
				</p>

				<div class="flex flex-wrap gap-2">
					<div
						v-for="item in statusLegendItems"
						:key="item.key"
						class="inline-flex items-center gap-2 rounded-full border border-default px-2.5 py-1 text-xs text-muted"
					>
						<span class="h-2.5 w-2.5 rounded-full" :class="item.colorClass"></span>
						<span>{{ t(`groups.activities.statuses.${item.key}`) }}</span>
					</div>
				</div>
			</div>
		</template>
	</UCard>
</template>
