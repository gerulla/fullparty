<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useNow } from "@vueuse/core";
import { router } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import PageHeader from "@/components/PageHeader.vue";
import ActivityUpcomingList from "@/components/Groups/Activities/ActivityUpcomingList.vue";
import ActivityMonthCalendar from "@/components/Groups/Activities/ActivityMonthCalendar.vue";
import ActivityResponsiveAgendaCalendar from "@/components/Groups/Activities/ActivityResponsiveAgendaCalendar.vue";
import type { ActivityIndexItem } from "@/Types/ActivityCore";
import { isArchivedActivityStatus } from "@/utils/activityLifecycle";
import { buildGroupCalendarColors } from "@/utils/groupCalendarColors";
import MyRunsToolColumn from "@/components/Runs/MyRunsToolColumn.vue";
import type { MyRunsCommitment, MyRunsToolState } from "@/Types/MyRuns";
import MyRunsResults from "@/components/Runs/MyRunsResults.vue";
import { filterMyRuns, groupMyRunsByDay } from "@/utils/myRuns";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";
import { toDisplayDateKey } from "@/utils/activityCalendar";

const props = defineProps<{
	activities: ActivityIndexItem[]
	commitments: MyRunsCommitment[]
	groups: Array<{
		id: number
		name: string
		slug: string
		profile_picture_url: string | null
	}>
}>();

const { t } = useI18n();
const { displayTimeZone } = useTimeDisplayMode();
const now = useNow({ interval: 60_000 });
const todayDateKey = computed(() => toDisplayDateKey(now.value, displayTimeZone.value));
const tools = ref<MyRunsToolState>({
	date: todayDateKey.value,
	search: "",
	appliedOnly: false,
	hideOverlapping: false,
	groupIds: props.groups.map((group) => group.id),
});
watch(todayDateKey, (today) => {
	if (tools.value.date < today) tools.value = { ...tools.value, date: today };
});
const resultDays = computed(() => groupMyRunsByDay(filterMyRuns(props.activities, tools.value, props.commitments, todayDateKey.value, displayTimeZone.value), displayTimeZone.value));
const runDates = computed(() => resultDays.value.map((day) => day.date));
const participatingIds = computed(() => props.commitments.map((run) => run.id));
const resultsPanel = ref<InstanceType<typeof MyRunsResults> | null>(null);
const groupColors = computed(() => buildGroupCalendarColors(props.groups.map((group) => group.id)));
const selectedDateKey = ref<string | null>(null);
const desktopMediaQueryString = '(min-width: 1280px)';
const shouldRenderDesktopLayout = ref(
	typeof window !== 'undefined'
		? window.matchMedia(desktopMediaQueryString).matches
		: false,
);
let desktopMediaQuery: MediaQueryList | null = null;

const syncDesktopLayout = () => {
	shouldRenderDesktopLayout.value = desktopMediaQuery?.matches ?? false;
};

onMounted(() => {
	if (typeof window === 'undefined') {
		return;
	}

	desktopMediaQuery = window.matchMedia(desktopMediaQueryString);
	syncDesktopLayout();
	desktopMediaQuery.addEventListener('change', syncDesktopLayout);
});

onBeforeUnmount(() => {
	desktopMediaQuery?.removeEventListener('change', syncDesktopLayout);
});

const upcomingCount = computed(() => {
	const now = Date.now();

	return props.activities.filter((activity) => (
		activity.starts_at
		&& !isArchivedActivityStatus(activity.status)
		&& new Date(activity.starts_at).getTime() >= now
	)).length;
});

const browseGroups = () => {
	router.get(route('groups.index'));
};
</script>

<template>
	<div class="w-full">
		<!-- Original header wrapper: <div v-if="shouldRenderDesktopLayout" class="hidden xl:block"> -->
		<div>
			<PageHeader
				:title="t('my_runs.title')"
				:subtitle="t('my_runs.subtitle')"
			>
				<div class="flex flex-wrap items-center justify-center gap-2 xl:justify-end">
					<UBadge
						size="lg"
						variant="subtle"
						color="neutral"
						icon="i-lucide-shield"
						:label="t('my_runs.groups_count', { count: groups.length })"
					/>
					<UBadge
						size="lg"
						variant="subtle"
						color="primary"
						icon="i-lucide-calendar-range"
						:label="t('my_runs.upcoming_count', { count: upcomingCount })"
					/>
				</div>
			</PageHeader>
		</div>

		<div class="mt-4 grid min-w-0 grid-cols-1 items-start gap-6 lg:h-[calc(100dvh-16rem)] lg:min-h-0 lg:grid-cols-[20rem_minmax(0,1fr)]">
			<MyRunsToolColumn v-model="tools" :groups="groups" :run-dates="runDates" :min-date="todayDateKey" class="lg:h-full lg:overflow-y-auto" @date-selected="resultsPanel?.scrollToDate($event)" />
			<MyRunsResults ref="resultsPanel" :days="resultDays" :selected-date="tools.date" :today-date="todayDateKey" :participating-ids="participatingIds" />
		</div>

		<!-- Temporarily disabled for the My Runs redesign. Keep until a full cleanout is requested.
		<UCard
			v-if="groups.length === 0"
			class="mt-4 dark:bg-elevated/25"
		>
			<div class="flex flex-col items-center px-4 py-12 text-center">
				<UIcon name="i-lucide-calendar-plus" class="size-10 text-primary" />
				<h2 class="mt-4 text-lg font-semibold text-toned">
					{{ t('my_runs.empty.title') }}
				</h2>
				<p class="mt-2 max-w-xl text-sm text-muted">
					{{ t('my_runs.empty.description') }}
				</p>
				<UButton
					class="mt-5"
					color="neutral"
					icon="i-lucide-search"
					:label="t('my_runs.empty.browse')"
					@click="browseGroups"
				/>
			</div>
		</UCard>

		<template v-else>
			<section class="my-4 border-b border-default pb-4" :aria-label="t('my_runs.hosting_groups')">
				<h2 class="mb-2 text-sm font-semibold text-toned">{{ t('my_runs.hosting_groups') }}</h2>
				<ul class="flex flex-wrap gap-x-5 gap-y-2">
					<li v-for="group in groups" :key="group.id" class="flex min-w-0 max-w-full items-center gap-2 text-sm">
						<span class="size-3 shrink-0" :style="{ backgroundColor: groupColors[group.id] }" aria-hidden="true" />
						<span class="min-w-0 break-words" :style="{ color: groupColors[group.id] }">{{ group.name }}</span>
					</li>
				</ul>
			</section>
			<div v-if="!shouldRenderDesktopLayout" class="xl:hidden">
				<ActivityResponsiveAgendaCalendar
					:activities="activities"
					:group-colors="groupColors"
					:show-group-badge="true"
					:discovery-style="true"
				/>
			</div>

			<div v-if="shouldRenderDesktopLayout" class="mt-4 hidden items-start gap-6 xl:flex">
				<ActivityMonthCalendar
					class="w-full xl:w-2/3"
					:activities="activities"
					:group-colors="groupColors"
					:selected-date-key="selectedDateKey"
					:quick-create-shortcuts="[]"
					:show-group-badge="true"
					@update-selected-date-key="selectedDateKey = $event"
				/>
				<ActivityUpcomingList
					class="w-full xl:w-1/3"
					:activities="activities"
					:group-colors="groupColors"
					:selected-date-key="selectedDateKey"
					:show-group-badge="true"
					:discovery-style="true"
				/>
			</div>
		</template>
		-->
	</div>
</template>
