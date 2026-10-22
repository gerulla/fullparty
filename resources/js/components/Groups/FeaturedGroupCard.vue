<script setup lang="ts">
import { computed } from "vue";
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import type { FeaturedGroupRecord } from "@/Types/Groups";
import GroupDiscoveryBadge from "@/components/Groups/GroupDiscoveryBadge.vue";
import { formatRelativeTime } from "@/utils/formatRelativeTime";

const props = defineProps<{
	group: FeaturedGroupRecord
	variant?: "featured" | "membership"
	lastActivityAt?: string | null
	href?: string
}>();
const emit = defineEmits<{
	openGroup: [group: FeaturedGroupRecord]
}>();

const { t, locale } = useI18n();
const isMembership = computed(() => props.variant === "membership");
const lastActiveLabel = computed(() => t("groups.index.your_groups.last_active", {
	time: formatRelativeTime(props.lastActivityAt ?? null, locale.value,
		t("notifications.ui.just_now"), t("groups.common.states.no_recent_activity")),
}));
const languageLabelMap: Record<string, string> = {
	en: "English",
	de: "Deutsch",
	fr: "Français",
	ja: "日本語",
};

const visibleTags = computed(() => props.group.tag_badges.slice(0, 3));
const experienceLabel = computed(() => (
	props.group.experience_badge
		? t(`groups.index.create_modal.fields.experience_expectation.options.${props.group.experience_badge.value}`)
		: null
));
const languagesText = computed(() => props.group.preferred_languages
	.map((language) => languageLabelMap[language] ?? language.toUpperCase())
	.join(", "));

function openGroup() {
	if (!props.href) {
		emit("openGroup", props.group);
	}
}

function handleKeydown(event: KeyboardEvent) {
	if (!props.href && (event.key === "Enter" || event.key === " ")) {
		event.preventDefault();
		openGroup();
	}
}
</script>

<template>
	<component
		:is="href ? Link : 'article'"
		:href="href"
		class="dark relative block overflow-hidden rounded-xl border border-transparent bg-neutral-950 shadow-md transition-[scale,box-shadow,border-color] duration-200 cursor-pointer hover:scale-101 hover:shadow-lg dark:border-white/10 dark:shadow-[0_18px_40px_rgba(0,0,0,0.28)] dark:hover:border-brand-400 dark:hover:shadow-[0_18px_40px_rgba(0,0,0,0.28)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary motion-reduce:transition-none motion-reduce:hover:scale-100"
		:class="isMembership ? 'min-h-32 xl:min-h-52' : 'min-h-52'"
		:role="href ? undefined : 'button'"
		:tabindex="href ? undefined : 0"
		@click="openGroup"
		@keydown="handleKeydown"
	>
		<img
			v-if="group.banner_image_url"
			:src="group.banner_image_url"
			:alt="group.name"
			loading="lazy"
			decoding="async"
			class="absolute inset-0 h-full w-full rounded-[calc(var(--radius-xl)-1px)] object-cover"
		>
		<div class="absolute inset-0 rounded-[calc(var(--radius-xl)-1px)] bg-linear-to-t from-neutral-950 via-neutral-950/72 to-neutral-950/18" />
		<div
			v-if="!group.banner_image_url"
			class="absolute inset-0 rounded-[calc(var(--radius-xl)-1px)] bg-[radial-gradient(circle_at_top_left,rgba(134,93,255,0.22),transparent_42%),radial-gradient(circle_at_80%_20%,rgba(80,145,255,0.18),transparent_36%),linear-gradient(180deg,rgba(255,255,255,0.04),rgba(255,255,255,0))]"
		/>

		<div class="relative flex h-full flex-col justify-between"
			:class="isMembership ? 'min-h-32 p-3 xl:min-h-52 xl:p-4' : 'min-h-52 p-4'">
			<div :class="isMembership ? 'hidden xl:block' : undefined">
				<GroupDiscoveryBadge
					v-if="group.experience_badge && experienceLabel"
					:label="experienceLabel"
					:color="group.experience_badge.color"
				/>
			</div>

			<div :class="isMembership ? 'mt-auto space-y-2 xl:mt-0 xl:space-y-3' : 'space-y-3'">
				<h3 class="font-semibold leading-tight text-white drop-shadow-[0_2px_8px_rgba(0,0,0,0.45)] break-words [overflow-wrap:anywhere]"
					:class="isMembership ? 'text-sm xl:text-lg' : 'text-lg'">
					{{ group.name }}
				</h3>

				<div v-if="visibleTags.length > 0" class="flex-wrap gap-2" :class="isMembership ? 'hidden xl:flex' : 'flex'">
					<GroupDiscoveryBadge
						v-for="tag in visibleTags"
						:key="tag.value"
						:label="tag.label"
						:color="tag.color"
					/>
				</div>

				<div class="flex gap-2 text-white/72"
					:class="isMembership ? 'flex-col items-start text-[11px] xl:flex-row xl:flex-wrap xl:items-center xl:text-sm' : 'items-center text-sm'">
					<div class="flex items-center" :class="isMembership ? 'gap-1 xl:gap-2' : 'gap-2'">
						<UIcon name="i-lucide-users" :class="isMembership ? 'size-3 xl:size-4' : 'size-4'" />
						<span>{{ group.stats.member_count }}</span>
						<span>{{ t('general.members') }}</span>
					</div>
					<span v-if="isMembership" class="xl:ml-auto xl:text-right">{{ lastActiveLabel }}</span>
					<span v-else-if="languagesText" class="ml-auto text-right">
						{{ languagesText }}
					</span>
				</div>
			</div>
		</div>
	</component>
</template>
