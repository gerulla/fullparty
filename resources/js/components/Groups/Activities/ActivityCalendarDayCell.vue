<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { usePage } from "@inertiajs/vue3";
import { localizedValue } from "@/utils/localizedValue";
import type { ActivityIndexItem } from "@/components/Groups/Activities/types";
import { getActivityStatusBorderClass } from "@/utils/activityStatusMeta";

type CalendarDay = {
	key: string
	date: Date
	isCurrentMonth: boolean
	isToday: boolean
	activities: ActivityIndexItem[]
};

const props = defineProps<{
	day: CalendarDay
	isSelected?: boolean
}>();

const emit = defineEmits<{
	select: [dayKey: string]
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));

const visibleActivities = computed(() => props.day.activities.slice(0, 3));
const hiddenCount = computed(() => Math.max(0, props.day.activities.length - visibleActivities.value.length));

const activityTypeName = (activity: ActivityIndexItem) => {
	return localizedValue(activity.activity_type?.draft_name, locale.value, fallbackLocale.value)
		|| activity.activity_type?.slug
		|| t('groups.activities.cards.unknown_type');
};

const activityLabel = (activity: ActivityIndexItem) => activity.title || activityTypeName(activity);

const activityTime = (activity: ActivityIndexItem) => {
	if (!activity.starts_at) {
		return '';
	}

	return new Intl.DateTimeFormat(locale.value, {
		hour: '2-digit',
		minute: '2-digit',
	}).format(new Date(activity.starts_at));
};

const activityStatusBorderClass = (activity: ActivityIndexItem) => getActivityStatusBorderClass(activity.status);

const selectDay = () => {
	emit('select', props.day.key);
};
</script>

<template>
	<div
		class="flex min-h-[9rem] cursor-pointer flex-col gap-2 border border-default/70 p-2 transition hover:border-primary/30 hover:bg-primary/5"
		role="button"
		tabindex="0"
		:class="[
			day.isCurrentMonth ? 'bg-background' : 'bg-muted/10',
			day.isToday ? 'bg-primary/6 ring-1 ring-primary/20' : '',
			isSelected ? 'border-primary/40 bg-primary/10 ring-1 ring-primary/35' : '',
		]"
		@click="selectDay"
		@keydown.enter.prevent="selectDay"
		@keydown.space.prevent="selectDay"
	>
		<div class="flex items-center justify-between">
			<div
				class="flex h-7 w-7 items-center justify-center rounded-full text-sm font-semibold"
				:class="isSelected
					? 'bg-primary text-white'
					: day.isToday
						? 'bg-primary/12 text-primary'
						: day.isCurrentMonth
							? 'text-toned'
							: 'text-muted'"
			>
				{{ day.date.getDate() }}
			</div>
		</div>

		<div class="flex flex-1 flex-col gap-1.5">
			<div
				v-for="activity in visibleActivities"
				:key="activity.id"
				class="rounded-sm border-t-2 bg-primary/15 px-2 py-1.5 text-xs"
				:class="activityStatusBorderClass(activity)"
			>
				<p class="font-medium text-toned">
					{{ activityTime(activity) }}
				</p>
				<p class="mt-0.5 line-clamp-2 text-muted">
					{{ activityLabel(activity) }}
				</p>
			</div>

			<p v-if="hiddenCount > 0" class="mt-auto text-xs font-medium text-muted">
				{{ t('groups.activities.calendar.more', { count: hiddenCount }) }}
			</p>
		</div>
	</div>
</template>
