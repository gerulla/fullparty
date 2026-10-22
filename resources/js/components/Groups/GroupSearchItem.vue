<script setup lang="ts">
import type { GroupIndexRecord } from "@/Types/Groups";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { formatRelativeTime } from "@/utils/formatRelativeTime";

const props = defineProps<{ group: GroupIndexRecord }>();
const emit = defineEmits<{ openGroup: [group: GroupIndexRecord] }>();
const { locale, t } = useI18n();

const languageLabelMap: Record<string, string> = {
	en: "English", de: "Deutsch", fr: "Français", ja: "日本語",
};
const experienceBadge = computed(() => props.group.badge_meta.experience_expectation);
const voiceBadge = computed(() => props.group.badge_meta.voice_expectation);
const focusBadges = computed(() => props.group.badge_meta.primary_focuses ?? []);
const extraTagBadges = computed(() => props.group.badge_meta.tags ?? []);
const languageNames = computed(() => props.group.preferred_languages
	.map((language) => languageLabelMap[language] ?? language.toUpperCase()).join(", "));
const languageText = computed(() => props.group.preferred_languages.map((language) => language.toUpperCase()).join(", ") || "—");
const lastActivityText = computed(() => formatRelativeTime(
	props.group.stats.last_activity_at, locale.value,
	t("notifications.ui.just_now"), t("groups.index.table.no_activity"),
));
const descriptionText = computed(() => props.group.description || t("groups.index.table.no_description"));
const joinModeLabel = computed(() => t("groups.common.join_modes." + props.group.join_mode + ".label"));
const joinModeColor = computed(() => props.group.join_mode === "open" ? "success" : "neutral");
const metadataBadgeClass = "max-w-full rounded-md px-2 py-0.5 text-[10px] font-normal leading-4 whitespace-normal bg-elevation-2 text-muted ring-1 ring-inset ring-neutral-200 dark:bg-white/5 dark:ring-white/10";

function badgeLabel(value: string, group: "experience_expectation" | "voice_expectation" | "primary_focuses") {
	return group === "voice_expectation"
		? t("groups.common.voice_expectations." + value)
		: t("groups.index.create_modal.fields." + group + ".options." + value);
}

function openGroup() {
	emit("openGroup", props.group);
}
</script>

<template>
	<UCard
		as="article"
		class="flex h-full w-full min-w-0 max-w-128 cursor-pointer flex-col divide-y-0 overflow-hidden rounded-xl border border-neutral-200 bg-elevation-1 shadow-sm ring-0 transition-[border-color,box-shadow] hover:border-brand-500 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 dark:border-default dark:bg-elevation-2 dark:shadow-none dark:hover:border-brand-400"
		:ui="{
			header: 'relative h-32 shrink-0 p-0 sm:p-0',
			body: 'flex flex-1 flex-col gap-5 px-5 pt-12 pb-5 sm:px-6 sm:pt-12 sm:pb-5',
			footer: 'px-5 pt-0 pb-5 sm:px-6 sm:pt-0 sm:pb-6',
		}"
		role="button"
		tabindex="0"
		:aria-label="group.name"
		aria-haspopup="dialog"
		@click="openGroup"
		@keydown.enter.prevent="openGroup"
		@keydown.space.prevent="openGroup"
	>
		<template #header>
			<div aria-hidden="true" class="absolute inset-0 overflow-hidden bg-linear-to-br from-brand-950 via-brand-800 to-brand-700">
				<img v-if="group.banner_image_url" :src="group.banner_image_url" alt="" loading="lazy" decoding="async" class="h-full w-full object-cover">
				<div v-if="group.banner_image_url" class="absolute inset-0 bg-linear-to-t from-neutral-950/30 to-neutral-950/10" />
			</div>
			<UBadge
				:color="joinModeColor"
				variant="subtle"
				class="absolute top-4 right-4 max-w-[calc(100%-2rem)] rounded-md px-3 py-1 text-[10px] font-semibold uppercase tracking-wider whitespace-normal backdrop-blur-sm"
				:class="group.join_mode === 'open' ? 'bg-neutral-950/35 text-success-300 ring-success-400/35' : 'bg-neutral-950/35 text-white/85 ring-white/25'"
			>
				{{ joinModeLabel }}
			</UBadge>
			<UAvatar
				:src="group.profile_picture_url || undefined"
				:alt="group.name"
				icon="i-lucide-users"
				loading="lazy"
				aria-hidden="true"
				class="absolute -bottom-8 left-5 z-10 size-18 rounded-lg bg-elevation-1 ring-4 ring-elevation-1 sm:left-6 dark:bg-elevation-2 dark:ring-elevation-2"
				:ui="{ icon: 'size-8 text-muted', image: 'object-cover' }"
			/>
		</template>

		<div class="min-w-0 space-y-2">
			<h3 class="text-xl font-semibold leading-6 text-highlighted break-words [overflow-wrap:anywhere]">{{ group.name }}</h3>
			<div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
				<span v-if="group.datacenter" class="inline-flex items-center gap-1">
					<UIcon name="i-lucide-server" class="size-3 shrink-0" />{{ group.datacenter }}
				</span>
				<span v-if="group.datacenter && group.region" aria-hidden="true">·</span>
				<span v-if="group.region" class="inline-flex items-center gap-1">
					<UIcon name="i-lucide-globe" class="size-3 shrink-0" />{{ group.region }}
				</span>
			</div>
		</div>
		<p class="line-clamp-3 min-h-12 text-sm leading-6 italic text-muted break-words [overflow-wrap:anywhere]">
			{{ descriptionText }}
		</p>
		<div class="space-y-2 rounded-lg bg-elevation-2 p-3 dark:bg-elevation-3">
			<p class="text-[9px] font-medium uppercase tracking-wider text-muted">
				{{ t("groups.index.discovery.results.management") }}
			</p>
			<UUser
				:name="group.owner.name || '—'"
				:description="t('groups.common.labels.owner')"
				:avatar="{ src: group.owner.avatar_url || undefined, icon: 'i-lucide-user-round' }"
				size="sm"
				:ui="{
					root: 'min-w-0',
					wrapper: 'min-w-0',
					name: 'text-xs font-semibold break-words [overflow-wrap:anywhere]',
					description: 'text-[9px] uppercase tracking-wide text-muted',
				}"
			/>
		</div>
		<div class="mt-auto flex flex-wrap items-center gap-1.5">
			<UBadge v-if="experienceBadge" color="brand" variant="soft" :class="['experience-badge', metadataBadgeClass]"
				:style="{ '--experience-accent': experienceBadge.color || 'var(--ui-primary)' }">
				{{ badgeLabel(experienceBadge.value, 'experience_expectation') }}
			</UBadge>
			<UBadge v-if="voiceBadge" color="neutral" variant="subtle" :class="metadataBadgeClass">
				{{ badgeLabel(voiceBadge.value, 'voice_expectation') }}
			</UBadge>
			<UBadge v-for="focus in focusBadges" :key="focus.value" color="neutral" variant="subtle" :class="metadataBadgeClass">
				{{ badgeLabel(focus.value, 'primary_focuses') }}
			</UBadge>
			<UBadge v-for="tag in extraTagBadges" :key="tag.value" color="neutral" variant="subtle" :class="metadataBadgeClass">
				{{ tag.label }}
			</UBadge>
		</div>

		<template #footer>
			<dl class="grid grid-cols-[1fr_1fr_1.2fr] gap-3 border-t border-neutral-200 pt-5 text-center dark:border-default">
				<div class="min-w-0 space-y-1">
					<dt class="text-[9px] font-medium uppercase tracking-wider text-muted">{{ t("groups.common.labels.members") }}</dt>
					<dd class="text-sm font-semibold tabular-nums text-highlighted">{{ group.stats.member_count }}</dd>
				</div>
				<div class="min-w-0 space-y-1">
					<dt class="text-[9px] font-medium uppercase tracking-wider text-muted">{{ t("groups.common.labels.languages") }}</dt>
					<dd class="text-sm font-semibold text-highlighted break-words" :title="languageNames || undefined">{{ languageText }}</dd>
				</div>
				<div class="min-w-0 space-y-1">
					<dt class="text-[9px] font-medium uppercase tracking-wider text-muted">{{ t("groups.index.discovery.results.active") }}</dt>
					<dd class="text-sm font-medium text-toned">{{ lastActivityText }}</dd>
				</div>
			</dl>
		</template>
	</UCard>
</template>

<style scoped>
.experience-badge {
	background-color: color-mix(in srgb, var(--experience-accent) 12%, transparent);
	color: color-mix(in srgb, var(--experience-accent) 45%, var(--ui-text-highlighted));
	box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--experience-accent) 25%, transparent);
}
</style>
