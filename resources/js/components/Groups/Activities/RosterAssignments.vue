<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import ActivityRosterPartyView from "@/components/Groups/Activities/ActivityRosterPartyView.vue";
import ActivityRosterRoleView from "@/components/Groups/Activities/ActivityRosterRoleView.vue";
import ActivityRosterListView from "@/components/Groups/Activities/ActivityRosterListView.vue";
import type { ActivitySlot } from "@/components/Groups/Activities/rosterTypes";

const props = defineProps<{
	view: 'party' | 'role' | 'list'
	slots: ActivitySlot[]
}>();

const { t } = useI18n();

const currentViewComponent = computed(() => {
	if (props.view === 'role') {
		return ActivityRosterRoleView;
	}

	if (props.view === 'list') {
		return ActivityRosterListView;
	}

	return ActivityRosterPartyView;
});
</script>

<template>
	<section class="flex flex-col gap-4 transition-all duration-300 ease-in-out">
		<h2 class="font-semibold text-lg text-toned">
			{{ t('groups.activities.management.roster.title') }}
		</h2>

		<component
			v-if="slots.length > 0"
			:is="currentViewComponent"
			:slots="slots"
		/>

		<div
			v-else
			class="border border-dashed border-default bg-muted/10 px-4 py-10 text-center text-sm text-muted"
		>
			{{ t('groups.activities.management.roster.empty') }}
		</div>
	</section>
</template>
