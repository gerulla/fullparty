<script setup lang="ts">
import { computed, ref } from "vue";
import { breakpointsTailwind, useBreakpoints } from "@vueuse/core";
import { useI18n } from "vue-i18n";
import { route } from "ziggy-js";
import type { YourGroupRecord } from "@/Types/Groups";
import FeaturedGroupCard from "@/components/Groups/FeaturedGroupCard.vue";

const props = defineProps<{ groups: YourGroupRecord[] }>();
const { t } = useI18n();
const breakpoints = useBreakpoints(breakpointsTailwind);
const isTabletOrLarger = breakpoints.greaterOrEqual("md");
const isDesktop = breakpoints.greaterOrEqual("2xl");
const showAll = ref(false);
const collapsedLimit = computed(() => isDesktop.value ? 8 : isTabletOrLarger.value ? 6 : 4);
const visibleGroups = computed(() => (showAll.value ? props.groups : props.groups.slice(0, collapsedLimit.value))
	.map((group) => ({
		...group,
		experience_badge: group.badge_meta.experience_expectation,
		tag_badges: group.badge_meta.tags,
	})));
</script>

<template>
	<section v-if="groups.length" :aria-label="t('groups.index.your_groups.title')" class="space-y-4">
		<div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:gap-4 2xl:grid-cols-4">
			<FeaturedGroupCard
				v-for="group in visibleGroups"
				:key="group.id"
				:group="group"
				:href="route('groups.dashboard', { group: group.slug })"
				variant="membership"
				:last-activity-at="group.stats.last_activity_at"
				class="min-w-0"
			/>
		</div>
		<div v-if="groups.length > collapsedLimit" class="flex justify-center">
			<UButton
				color="brand"
				variant="ghost"
				class="rounded-md dark:text-brand-400"
				:trailing-icon="showAll ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
				:aria-expanded="showAll"
				@click="showAll = !showAll"
			>
				{{ showAll ? t('groups.index.your_groups.show_less') : t('groups.index.your_groups.view_all', { count: groups.length }) }}
			</UButton>
		</div>
	</section>
	<p v-else class="rounded-xl border border-dashed border-neutral-300 bg-elevation-1 p-6 text-sm text-muted dark:border-default dark:bg-elevation-2">
		{{ t('groups.index.your_groups.empty') }}
	</p>
</template>
