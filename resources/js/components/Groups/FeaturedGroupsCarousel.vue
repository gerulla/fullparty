<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import FeaturedGroupCard from "@/components/Groups/FeaturedGroupCard.vue";
import type { FeaturedGroupRecord } from "@/Types/Groups";

const props = defineProps<{
	groups: FeaturedGroupRecord[]
	isLoading?: boolean
}>();
const emit = defineEmits<{
	openGroup: [group: FeaturedGroupRecord]
}>();

const { t } = useI18n();
const scrollContainer = ref<HTMLElement | null>(null);

const skeletonItems = computed(() => Array.from({ length: 4 }, (_, index) => index));

const scrollByDirection = (direction: "left" | "right") => {
	if (!scrollContainer.value) {
		return;
	}

	const amount = Math.round(scrollContainer.value.clientWidth * 0.82);

	scrollContainer.value.scrollBy({
		left: direction === "left" ? -amount : amount,
		behavior: "smooth",
	});
};
</script>

<template>
	<section>
		<div class="relative">
			<UButton
				class="absolute rounded-full top-1/2 left-0 z-10 hidden -translate-x-1/2 -translate-y-1/2 border border-neutral-200 bg-elevation-1 text-neutral-700 shadow-sm hover:bg-neutral-100 dark:border-white/10 dark:bg-neutral-950/85 dark:text-white dark:hover:bg-neutral-900 backdrop-blur-sm lg:inline-flex"
				color="neutral"
				variant="soft"
				icon="i-lucide-chevron-left"
				:aria-label="t('groups.index.featured.previous')"
				@click="scrollByDirection('left')"
			/>
			<UButton
				class="absolute rounded-full top-1/2 right-0 z-10 hidden translate-x-1/2 -translate-y-1/2 border border-neutral-200 bg-elevation-1 text-neutral-700 shadow-sm hover:bg-neutral-100 dark:border-white/10 dark:bg-neutral-950/85 dark:text-white dark:hover:bg-neutral-900 backdrop-blur-sm lg:inline-flex"
				color="neutral"
				variant="soft"
				icon="i-lucide-chevron-right"
				:aria-label="t('groups.index.featured.next')"
				@click="scrollByDirection('right')"
			/>

			<div
				ref="scrollContainer"
				class="-mx-4 -mt-4 -mb-4 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto px-4 pt-4 pb-6 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
			>
				<template v-if="isLoading">
					<div
						v-for="item in skeletonItems"
						:key="item"
						class="min-w-[18.5rem] flex-1 snap-start md:min-w-[22rem]"
					>
						<div class="min-h-52 rounded-xl border border-neutral-200 bg-elevation-1 p-4 dark:border-white/10 dark:bg-neutral-900/70">
							<USkeleton class="h-6 w-20" />
							<div class="mt-20 space-y-3">
								<USkeleton class="h-6 w-2/3" />
								<div class="flex gap-2">
									<USkeleton class="h-5 w-16" />
									<USkeleton class="h-5 w-20" />
									<USkeleton class="h-5 w-14" />
								</div>
								<USkeleton class="h-4 w-24" />
							</div>
						</div>
					</div>
				</template>

				<template v-else-if="groups.length > 0">
					<div
						v-for="group in groups"
						:key="group.id"
						class="min-w-[18.5rem] flex-1 snap-start md:min-w-[22rem]"
					>
						<FeaturedGroupCard
							:group="group"
							@open-group="emit('openGroup', $event)"
						/>
					</div>
				</template>

				<UAlert
					v-else
					color="neutral"
					variant="soft"
					icon="i-lucide-users"
					class="w-full rounded-xl border border-dashed border-neutral-300 bg-elevation-1 dark:border-default dark:bg-elevation-2"
					:title="t('groups.index.featured.empty_title')"
					:description="t('groups.index.featured.empty_description')"
				/>
			</div>
		</div>
	</section>
</template>
