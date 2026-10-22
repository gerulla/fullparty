<script setup lang="ts">
import axios from "axios";
import V2Layout from "@/Layouts/V2Layout.vue";
import PageHeader from "@/components/PageHeader.vue";
import FeaturedGroupsCarousel from "@/components/Groups/FeaturedGroupsCarousel.vue";
import YourGroupsSection from "@/components/Groups/YourGroupsSection.vue";
import GroupDiscoverySearchSection from "@/components/Groups/GroupDiscoverySearchSection.vue";
import GroupDiscoverySlideover from "@/components/Groups/GroupDiscoverySlideover.vue";
import type { FeaturedGroupRecord, GroupDiscoveryDetailRecord, GroupIndexRecord, YourGroupRecord } from "@/Types/Groups";
import { onBeforeUnmount, onMounted, ref, watch } from "vue";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";

defineOptions({ layout: V2Layout });

defineProps<{
	yourGroups: YourGroupRecord[]
}>();

const { t } = useI18n();
const featuredGroups = ref<FeaturedGroupRecord[]>([]);
const isFeaturedGroupsLoading = ref(true);
const isGroupDetailsOpen = ref(false);
const isGroupDetailsLoading = ref(false);
const selectedGroup = ref<GroupDiscoveryDetailRecord | null>(null);
const activeGroupSlug = ref<string | null>(null);

const groupDetailsCache = new Map<string, GroupDiscoveryDetailRecord>();
let groupDetailsRequestCounter = 0;

const loadFeaturedGroups = async () => {
	isFeaturedGroupsLoading.value = true;

	try {
		const response = await axios.get<{ data: FeaturedGroupRecord[] }>(route("groups.featured"));

		featuredGroups.value = response.data.data;
	} finally {
		isFeaturedGroupsLoading.value = false;
	}
};

const isSearchResultGroup = (group: FeaturedGroupRecord | GroupIndexRecord): group is GroupIndexRecord => "badge_meta" in group;
const toPlaceholderDetailRecord = (group: GroupIndexRecord): GroupDiscoveryDetailRecord => ({
	...group,
	activity_summary: {
		completed_runs: 0,
		total_runs: 0,
		runs_per_week: 0,
		average_turnout: 0,
	},
	recent_runs: [],
	content_summary: {
		total_runs: 0,
		status_breakdown: [
			{ status: "draft", count: 0 },
			{ status: "scheduled", count: 0 },
			{ status: "active", count: 0 },
			{ status: "complete", count: 0 },
			{ status: "cancelled", count: 0 },
		],
	},
	content_items: [],
	team_members: [],
});

const setGroupQueryParam = (groupSlug: string | null) => {
	if (typeof window === "undefined") {
		return;
	}

	const url = new URL(window.location.href);

	if (groupSlug) {
		url.searchParams.set("group", groupSlug);
	} else {
		url.searchParams.delete("group");
	}

	window.history.replaceState(window.history.state, "", `${url.pathname}${url.search}${url.hash}`);
};

const loadGroupDetails = async (groupSlug: string) => {
	const requestId = ++groupDetailsRequestCounter;

	isGroupDetailsLoading.value = true;

	try {
		const response = await axios.get<{ data: GroupDiscoveryDetailRecord }>(route("groups.details", {
			group: groupSlug,
		}));

		if (requestId !== groupDetailsRequestCounter || activeGroupSlug.value !== groupSlug) {
			return;
		}

		selectedGroup.value = response.data.data;
		groupDetailsCache.set(groupSlug, response.data.data);
		isGroupDetailsOpen.value = true;
	} catch {
		if (requestId === groupDetailsRequestCounter && activeGroupSlug.value === groupSlug) {
			activeGroupSlug.value = null;
			selectedGroup.value = null;
			isGroupDetailsOpen.value = false;
			setGroupQueryParam(null);
		}
	} finally {
		if (requestId === groupDetailsRequestCounter) {
			isGroupDetailsLoading.value = false;
		}
	}
};

const refreshGroupDetails = async (groupSlug: string) => {
	groupDetailsCache.delete(groupSlug);
	await loadGroupDetails(groupSlug);
};

const openGroupDetails = (group: FeaturedGroupRecord | GroupIndexRecord) => {
	activeGroupSlug.value = group.slug;
	isGroupDetailsOpen.value = true;
	setGroupQueryParam(group.slug);

	const cachedGroup = groupDetailsCache.get(group.slug);

	if (cachedGroup) {
		selectedGroup.value = cachedGroup;
		isGroupDetailsLoading.value = false;

		return;
	}

	if (isSearchResultGroup(group)) {
		selectedGroup.value = toPlaceholderDetailRecord(group);
		isGroupDetailsLoading.value = true;
		void loadGroupDetails(group.slug);
		return;
	}

	selectedGroup.value = null;
	void loadGroupDetails(group.slug);
};

const syncGroupDetailsFromUrl = () => {
	if (typeof window === "undefined") {
		return;
	}

	const groupSlug = new URL(window.location.href).searchParams.get("group");

	if (!groupSlug) {
		activeGroupSlug.value = null;
		isGroupDetailsOpen.value = false;

		return;
	}

	if (groupSlug === activeGroupSlug.value && isGroupDetailsOpen.value) {
		return;
	}

	activeGroupSlug.value = groupSlug;
	isGroupDetailsOpen.value = true;

	const cachedGroup = groupDetailsCache.get(groupSlug);

	if (cachedGroup) {
		selectedGroup.value = cachedGroup;
		isGroupDetailsLoading.value = false;

		return;
	}

	selectedGroup.value = null;
	void loadGroupDetails(groupSlug);
};

onMounted(() => {
	void loadFeaturedGroups();
	syncGroupDetailsFromUrl();
	window.addEventListener("popstate", syncGroupDetailsFromUrl);
});

onBeforeUnmount(() => {
	window.removeEventListener("popstate", syncGroupDetailsFromUrl);
});

watch(isGroupDetailsOpen, (isOpen) => {
	if (isOpen) {
		return;
	}

	activeGroupSlug.value = null;
	setGroupQueryParam(null);
});
</script>

<template>
	<div class="w-full">
		<PageHeader desktop-breakpoint="md" class="[&_h1]:text-2xl [&_p]:text-sm" :title="t('groups.index.your_groups.title')" :subtitle="t('groups.index.your_groups.subtitle')">
			<UButton
				:to="route('groups.create')"
				:label="t('groups.index.create_modal.open_button')"
				color="neutral"
				class="w-full justify-center rounded-md"
				icon="i-lucide-plus"
			/>
		</PageHeader>

		<YourGroupsSection :groups="yourGroups" />

		<div class="mt-10 mb-5 space-y-2">
			<h2 class="text-2xl font-semibold text-highlighted">{{ t('groups.index.featured.title') }}</h2>
			<p class="text-base text-muted">{{ t('groups.index.featured.subtitle') }}</p>
		</div>
		<FeaturedGroupsCarousel
			:groups="featuredGroups"
			:is-loading="isFeaturedGroupsLoading"
			@open-group="openGroupDetails"
		/>

		<GroupDiscoverySearchSection @open-group="openGroupDetails" />

		<GroupDiscoverySlideover
			v-model:open="isGroupDetailsOpen"
			:group="selectedGroup"
			:loading="isGroupDetailsLoading"
			@refresh-group="refreshGroupDetails"
		/>
	</div>
</template>

<style scoped>

</style>
