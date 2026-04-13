<script setup lang="ts">
// Group management page: show groups the user owns, moderates, belongs to, and public groups they can discover or join.
import PageHeader from "@/components/PageHeader.vue";
import CreateGroupModal from "@/components/Groups/CreateGroupModal.vue";
import GroupIndexTable from "@/components/Groups/GroupIndexTable.vue";
import GroupSearchBox from "@/components/Groups/GroupSearchBox.vue";
import { router } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import {useI18n} from "vue-i18n";
import {computed, ref} from "vue";

const {t} = useI18n();

const props = defineProps<{
	ownedGroups: Array<any>
	moderatedGroups: Array<any>
	memberGroups: Array<any>
	discoverGroups: {
		data: Array<any>
		meta: {
			current_page: number
			last_page: number
			per_page: number
			total: number
		}
	}
}>();

const tabs = computed(() => [
	{
		label: t('groups.index.tabs.my_groups'),
		value: 'my-groups',
		slot: 'my-groups',
	},
	{
		label: t('groups.index.tabs.discover'),
		value: 'discover',
		slot: 'discover',
	},
]);

const active_tab = ref('my-groups');
const isSearchActive = ref(false);

const setSearchState = (value: boolean) => {
	isSearchActive.value = value;
};

const goToDiscoverPage = (page: number) => {
	router.get(route('groups.index'), {
		discover_page: page,
	}, {
		only: ['discoverGroups'],
		preserveState: true,
		preserveScroll: true,
		replace: true,
	});
};
</script>

<template>
	<div class="w-full ">
		<PageHeader :title="t('groups.index.title')" :subtitle="t('groups.index.subtitle')">
			<CreateGroupModal />
		</PageHeader>

		<div class="w-full flex flex-col items-start mt-4 gap-8">
			<GroupSearchBox
				@search-started="setSearchState"
			/>

			<UTabs
				v-if="!isSearchActive"
				v-model="active_tab"
				:items="tabs"
				class="w-full"
				:ui="{ list: 'w-auto mr-auto mb-4' }"
			>
				<template #my-groups>
					<div class="flex w-full flex-col gap-6">
						<div id="owned-groups" class="w-full scroll-mt-6">
							<GroupIndexTable
								:title="t('groups.index.sections.owned')"
								:subtitle="t('groups.index.section_subtitles.owned')"
								:groups="ownedGroups"
							/>
						</div>
						<div id="moderated-groups" class="w-full scroll-mt-6">
							<GroupIndexTable
								:title="t('groups.index.sections.moderated')"
								:subtitle="t('groups.index.section_subtitles.moderated')"
								:groups="moderatedGroups"
							/>
						</div>
						<div id="member-groups" class="w-full scroll-mt-6">
							<GroupIndexTable
								:title="t('groups.index.sections.member')"
								:subtitle="t('groups.index.section_subtitles.member')"
								:groups="memberGroups"
							/>
						</div>
					</div>
				</template>

				<template #discover>
					<GroupIndexTable
						:title="t('groups.index.sections.discover')"
						:subtitle="t('groups.index.section_subtitles.discover')"
						:groups="discoverGroups.data"
						paginated
						server-side-pagination
						:page-size="discoverGroups.meta.per_page"
						:current-page="discoverGroups.meta.current_page"
						:total="discoverGroups.meta.total"
						@page-change="goToDiscoverPage"
					/>
				</template>
			</UTabs>
		</div>
	</div>
</template>

<style scoped>

</style>
