<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import PageHeader from "@/components/PageHeader.vue";
import ActivityUpcomingList from "@/components/Groups/Activities/ActivityUpcomingList.vue";
import ActivityMonthCalendar from "@/components/Groups/Activities/ActivityMonthCalendar.vue";
import ActivityResponsiveAgendaCalendar from "@/components/Groups/Activities/ActivityResponsiveAgendaCalendar.vue";
import type { ActivityIndexItem } from "@/Types/ActivityCore";
import { isArchivedActivityStatus } from "@/utils/activityLifecycle";

const props = defineProps<{
	activities: ActivityIndexItem[]
	groups: Array<{
		id: number
		name: string
		slug: string
		profile_picture_url: string | null
	}>
}>();

const { t } = useI18n();
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
		<div v-if="shouldRenderDesktopLayout" class="hidden xl:block">
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
			<div v-if="!shouldRenderDesktopLayout" class="xl:hidden">
				<ActivityResponsiveAgendaCalendar
					:activities="activities"
					:show-group-badge="true"
					:discovery-style="true"
				/>
			</div>

			<div v-if="shouldRenderDesktopLayout" class="mt-4 hidden items-start gap-6 xl:flex">
				<ActivityMonthCalendar
					class="w-full xl:w-2/3"
					:activities="activities"
					:selected-date-key="selectedDateKey"
					:quick-create-shortcuts="[]"
					:show-group-badge="true"
					@update-selected-date-key="selectedDateKey = $event"
				/>
				<ActivityUpcomingList
					class="w-full xl:w-1/3"
					:activities="activities"
					:selected-date-key="selectedDateKey"
					:show-group-badge="true"
					:discovery-style="true"
				/>
			</div>
		</template>
	</div>
</template>