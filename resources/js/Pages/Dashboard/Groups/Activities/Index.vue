<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import PageHeader from "@/components/PageHeader.vue";
import ActivityUpcomingList from "@/components/Groups/Activities/ActivityUpcomingList.vue";
import ActivityMonthCalendar from "@/components/Groups/Activities/ActivityMonthCalendar.vue";
import ActivityResponsiveAgendaCalendar from "@/components/Groups/Activities/ActivityResponsiveAgendaCalendar.vue";
import type { ActivityIndexItem, GroupQuickCreateShortcut } from "@/Types/ActivityCore";
import { isArchivedActivityStatus } from "@/utils/activityLifecycle";

const props = defineProps<{
	group: {
		id: number
		name: string
		slug: string
		current_user_role: string | null
		permissions: {
			can_manage_activities: boolean
		}
	}
	activities: ActivityIndexItem[]
	quickCreateShortcuts: GroupQuickCreateShortcut[]
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

const goToCreatePage = () => {
	router.get(route('groups.dashboard.activities.create', { group: props.group.slug }));
};

const upcomingCount = computed(() => {
	const now = Date.now();

	return props.activities.filter((activity) => {
		if (!activity.starts_at) {
			return false;
		}

		if (isArchivedActivityStatus(activity.status)) {
			return false;
		}

		return new Date(activity.starts_at).getTime() >= now;
	}).length;
});
</script>

<template>
	<div class="w-full">
		<div v-if="shouldRenderDesktopLayout" class="hidden xl:block">
			<PageHeader
				:title="t('groups.activities.title')"
				:subtitle="t('groups.activities.subtitle', { group: group.name })"
			>
				<div class="flex items-center gap-2">
					<UBadge
						size="lg"
						variant="subtle"
						class="min-w-44 justify-center py-2"
						color="primary"
						icon="i-lucide-calendar-range"
						:label="t('groups.activities.header_badge', { count: upcomingCount })"
					/>
					<UButton
						v-if="group.permissions.can_manage_activities"
						color="neutral"
						icon="i-lucide-plus"
						:label="t('groups.activities.create.cta')"
						@click="goToCreatePage"
					/>
				</div>
			</PageHeader>
		</div>

		<div v-if="!shouldRenderDesktopLayout" class="xl:hidden">
			<ActivityResponsiveAgendaCalendar
				:group-slug="group.slug"
				:can-manage-activities="group.permissions.can_manage_activities"
				:activities="activities"
			/>
		</div>

		<div v-if="shouldRenderDesktopLayout" class="mt-4 hidden items-start gap-6 xl:flex">
			<ActivityUpcomingList
				class="w-full xl:w-1/3"
				:group-slug="group.slug"
				:can-manage-activities="group.permissions.can_manage_activities"
				:activities="activities"
				:selected-date-key="selectedDateKey"
			/>
			<ActivityMonthCalendar
				class="w-full xl:w-2/3"
				:group-slug="group.slug"
				:activities="activities"
				:selected-date-key="selectedDateKey"
				:can-manage-activities="group.permissions.can_manage_activities"
				:quick-create-shortcuts="quickCreateShortcuts"
				@update-selected-date-key="selectedDateKey = $event"
			/>
		</div>
	</div>
</template>
