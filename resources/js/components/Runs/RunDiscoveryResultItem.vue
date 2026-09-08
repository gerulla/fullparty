<script setup lang="ts">
import type { RunDiscoveryResultItemData } from "../../Types/RunDiscovery";
import { computed } from "vue";
import { router } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";

const props = withDefaults(defineProps<{
	item: RunDiscoveryResultItemData
	savePending?: boolean
	showSave?: boolean
	showHost?: boolean
}>(), {
	showSave: true,
	showHost: false,
});

const emit = defineEmits<{
	toggleSaved: [item: RunDiscoveryResultItemData]
}>();

const { t, locale } = useI18n();
const { withDisplayTimeZone } = useTimeDisplayMode();

const contentName = computed(() => props.item.activity_type_name);
const showContentName = computed(() => contentName.value !== "");
const targetProgPointLabel = computed(() => props.item.target_prog_point_label || props.item.target_prog_point_key);
const descriptionLabel = computed(() => props.item.description || t("groups.activities.overview.details.no_description"));
const groupName = computed(() => props.item.group_name || t("runs.discovery.results.placeholder_item.unknown_group"));
const groupInitials = computed(() => groupName.value
	.split(/\s+/)
	.filter(Boolean)
	.slice(0, 2)
	.map((part) => part.charAt(0))
	.join("")
	.toUpperCase());
const groupLocationLabel = computed(() => {
	const datacenter = props.item.datacenter;
	const world = props.item.world;

	if (datacenter && world && world !== datacenter) {
		return `${datacenter} (${world})`;
	}

	return datacenter || world || null;
});

const hostLocationLabel = computed(() => [props.item.organizer?.world, props.item.organizer?.datacenter]
	.filter(Boolean).join(' - '));
const hasHost = computed(() => props.showHost && Boolean(props.item.organizer?.name));

const tagLabels = computed(() => {
	const tags: string[] = [];

	if (props.item.run_style) {
		tags.push(t("runs.discovery.filters.options.run_styles." + props.item.run_style));
	}

	if (props.item.intensity) {
		tags.push(t("runs.discovery.filters.options.intensity." + props.item.intensity));
	}

	if (props.item.beginner_friendly) {
		tags.push(t("runs.discovery.filters.labels.beginner_friendly"));
	}

	if (props.item.min_item_level) {
		tags.push(`ILVL ${props.item.min_item_level}+`);
	}

	return tags;
});

const startsAtDate = computed(() => props.item.starts_at ? new Date(props.item.starts_at) : null);
const nowDate = computed(() => new Date());

const calendarDateInDisplayZone = (date: Date): Date => {
	const parts = createDateTimeFormatter(locale.value, withDisplayTimeZone({
		year: "numeric",
		month: "numeric",
		day: "numeric",
	})).formatToParts(date);

	const year = Number(parts.find((part) => part.type === "year")?.value ?? date.getFullYear());
	const month = Number(parts.find((part) => part.type === "month")?.value ?? (date.getMonth() + 1));
	const day = Number(parts.find((part) => part.type === "day")?.value ?? date.getDate());

	return new Date(year, month - 1, day);
};

const scheduleLabel = computed(() => {
	if (!startsAtDate.value) {
		return t("groups.activities.cards.no_time");
	}

	const start = startsAtDate.value;
	const now = nowDate.value;
	const todayStart = calendarDateInDisplayZone(now);
	const startDay = calendarDateInDisplayZone(start);
	const diffDays = Math.round((startDay.getTime() - todayStart.getTime()) / 86400000);

	if (diffDays === 0) {
		return t("runs.discovery.results.placeholder_item.schedule.today");
	}

	if (diffDays === 1) {
		return t("runs.discovery.results.placeholder_item.schedule.tomorrow");
	}

	return createDateTimeFormatter(locale.value, withDisplayTimeZone({
		weekday: "short",
		day: "numeric",
		month: "short",
	})).format(start);
});

const timeLabel = computed(() => {
	if (!startsAtDate.value) {
		return "—";
	}

	return createDateTimeFormatter(locale.value, withDisplayTimeZone({
		hour: "numeric",
		minute: "2-digit",
	})).format(startsAtDate.value);
});

const timezoneLabel = computed(() => {
	if (!startsAtDate.value) {
		return Intl.DateTimeFormat().resolvedOptions().timeZone;
	}

	const parts = createDateTimeFormatter(locale.value, withDisplayTimeZone({
		timeZoneName: "short",
	})).formatToParts(startsAtDate.value);

	return parts.find((part) => part.type === "timeZoneName")?.value
		|| Intl.DateTimeFormat().resolvedOptions().timeZone;
});

const memberCountLabel = computed(() => `${props.item.filled_slots} / ${props.item.total_slots}`);

const goToViewDetails = () => {
	router.get(props.item.links.view);
};

const goToApply = () => {
	if (!props.item.links.apply) {
		return;
	}

	router.get(props.item.links.apply, {}, { preserveScroll: false });
};

const goToGroup = () => {
	if (!props.item.group_slug) {
		return;
	}

	router.get(route("groups.index", {
		locale: locale.value,
		group: props.item.group_slug,
	}));
};

const openDiscordInvite = () => {
	if (!props.item.group_discord_invite_url || typeof window === "undefined") {
		return;
	}

	window.open(props.item.group_discord_invite_url, "_blank", "noopener,noreferrer");
};

const toggleSaved = () => {
	emit("toggleSaved", props.item);
};
</script>

<template>
	<article
		class="@container relative isolate overflow-visible border border-white/10 bg-neutral-950/72 shadow-[0_20px_40px_rgba(0,0,0,0.2)]"
		:class="props.item.has_existing_application ? 'border-r-4 border-r-brand-400' : ''"
	>
		<div
			v-if="item.has_existing_application"
			class="pointer-events-none absolute -left-2 -top-2 z-30 flex size-8 items-center justify-center"
			:aria-label="t('runs.discovery.results.placeholder_item.actions.view_application')"
			:title="t('runs.discovery.results.placeholder_item.actions.view_application')"
		>
			<UIcon
				name="i-lucide-pin"
				class="size-8 -rotate-35 text-brand-400 drop-shadow-[0_4px_10px_rgba(168,85,247,0.85)]"
			/>
		</div>

		<img
			v-if="item.image_url"
			:src="item.image_url"
			:alt="item.title"
			class="absolute inset-0 size-full object-cover @6xl:hidden"
		>
		<div
			v-else
			class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(123,97,153,0.34),transparent_46%),radial-gradient(circle_at_center_right,rgba(84,136,184,0.28),transparent_38%),linear-gradient(180deg,#201c24_0%,#151217_100%)] @6xl:hidden"
		/>
		<div class="absolute inset-0 bg-linear-to-b from-neutral-950/62 via-neutral-950/78 to-neutral-950/96 @6xl:hidden" />

		<div class="relative z-10 grid grid-cols-1 @6xl:min-h-56 @6xl:grid-cols-[8.5rem_minmax(0,1fr)_16rem_13rem] @6xl:items-stretch">
			<div class="relative hidden min-h-56 overflow-hidden border-r border-white/8 bg-neutral-900/70 @6xl:block">
				<img
					v-if="item.image_url"
					:src="item.image_url"
					:alt="item.title"
					class="absolute inset-0 size-full object-cover"
				>
				<div
					v-else
					class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(123,97,153,0.34),transparent_46%),radial-gradient(circle_at_center_right,rgba(84,136,184,0.28),transparent_38%),linear-gradient(180deg,#201c24_0%,#151217_100%)]"
				/>
				<div v-if="showContentName" class="pointer-events-none absolute inset-x-0 bottom-0 bg-neutral-950/82 p-2">
					<p class="text-center text-xs font-semibold uppercase">{{ contentName }}</p>
				</div>
			</div>

			<div class="order-2 min-w-0 space-y-3 bg-neutral-950/42 p-3 @6xl:order-none @6xl:space-y-4 @6xl:bg-transparent @6xl:p-5">
				<div class="flex items-start justify-between gap-3">
					<div class="min-w-0 space-y-2">
						<div class="flex flex-wrap items-center gap-2">
							<h3 class="text-base font-semibold leading-tight text-white @6xl:text-xl">
								{{ item.title }}
							</h3>
							<UBadge
								v-if="targetProgPointLabel"
								:label="targetProgPointLabel"
								color="neutral"
								variant="soft"
								size="md"
							/>
						</div>
						<div v-if="showContentName" class="flex items-center gap-1.5 text-xs text-white/62 @6xl:hidden">
							<UIcon
								name="i-lucide-swords"
								class="size-3.5 shrink-0 text-white/46"
							/>
							<span class="truncate">{{ contentName }}</span>
						</div>
					</div>

					<UButton
						v-if="showSave"
						color="neutral"
						variant="ghost"
						:icon="item.is_saved ? 'material-symbols:bookmark' : 'material-symbols:bookmark-outline'"
						class="shrink-0 rounded-none hover:text-white"
						:class="item.is_saved ? 'text-brand-300' : 'text-white/50'"
						:loading="savePending"
						:disabled="savePending"
						:aria-label="item.is_saved
							? t('runs.discovery.results.placeholder_item.actions.unsave_run')
							: t('runs.discovery.results.placeholder_item.actions.save_run')"
						@click="toggleSaved"
					/>
				</div>

				<p class="max-w-3xl text-sm leading-5 text-white/70 @6xl:leading-6">
					{{ descriptionLabel }}
				</p>

				<div v-if="tagLabels.length > 0" class="flex flex-wrap gap-2">
					<UBadge
						v-for="tag in tagLabels"
						:key="tag"
						color="neutral"
						variant="outline"
						class="rounded-none border-white/12 bg-neutral-950/65 px-2.5 py-1 text-[11px] uppercase tracking-[0.12em] text-white/74"
						:label="tag"
					/>
				</div>
			</div>

			<section class="order-1 flex flex-col items-stretch justify-center border-b border-white/10 bg-neutral-950/94 px-3 py-3 @6xl:order-none @6xl:border-b-0 @6xl:border-l @6xl:bg-neutral-900/38 @6xl:px-5 @6xl:py-6">
				<p class="mb-3 hidden text-xs font-semibold uppercase tracking-[0.18em] text-brand-300 @6xl:block">
					{{ t("runs.discovery.results.placeholder_item.host_group") }}
				</p>

				<div :class="hasHost
					? 'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 @md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] @6xl:grid-cols-1'
					: 'flex items-center justify-between gap-3'">
					<button
						type="button"
						class="order-1 flex min-w-0 items-center gap-3 text-left @6xl:gap-4"
						:class="item.group_slug ? 'cursor-pointer hover:text-brand-200' : 'cursor-default'"
						:disabled="!item.group_slug"
						@click="goToGroup"
					>
						<UAvatar
							:src="item.group_profile_picture_url || undefined"
							:alt="groupName"
							:text="groupInitials"
							size="lg"
							class="size-10 shrink-0 ring-1 ring-brand-400/55 @6xl:size-20"
						/>
						<span class="min-w-0">
							<span class="mb-0.5 block text-[9px] font-semibold uppercase tracking-[0.18em] text-brand-300 @6xl:hidden">
								{{ t("runs.discovery.results.placeholder_item.host_group") }}
							</span>
							<span class="block line-clamp-2 text-sm font-semibold leading-4 text-white @6xl:text-lg @6xl:leading-6">
								{{ groupName }}
							</span>
							<span v-if="groupLocationLabel" class="mt-0.5 block truncate text-[11px] text-white/58 @6xl:mt-1 @6xl:text-sm">
								{{ groupLocationLabel }}
							</span>
						</span>
					</button>

					<UUser
						v-if="hasHost && item.organizer"
						:name="item.organizer.name || undefined"
						:description="hostLocationLabel || undefined"
						:avatar="{ src: item.organizer.avatar_url || undefined, alt: item.organizer.name || undefined }"
						size="lg"
						class="order-3 min-w-0 @md:order-2"
						:ui="{ root: 'gap-3', avatar: 'size-10 shrink-0 ring-1 ring-brand-400/55', wrapper: 'min-w-0' }"
					>
						<span class="mb-0.5 block text-[9px] font-semibold uppercase text-brand-300">
							{{ t('groups.activities.management.roster.host_badge') }}
						</span>
						<span class="block break-words text-sm font-semibold leading-4 text-white">{{ item.organizer.name }}</span>
						<span v-if="hostLocationLabel" class="mt-0.5 block break-words text-[11px] text-white/58">{{ hostLocationLabel }}</span>
					</UUser>

					<UTooltip
						v-if="item.group_discord_invite_url"
						:text="t('runs.discovery.results.placeholder_item.group_discord')"
						class="order-2 @md:order-3 @6xl:hidden"
					>
						<UButton
							color="neutral"
							variant="ghost"
							icon="ic:baseline-discord"
							:aria-label="t('runs.discovery.results.placeholder_item.group_discord')"
							class="shrink-0 rounded-none text-brand-400"
							@click="openDiscordInvite"
						/>
					</UTooltip>
				</div>

				<button
					v-if="item.group_discord_invite_url"
					type="button"
					class="mt-5 hidden items-center gap-2 self-start text-left text-sm text-white/62 transition-colors hover:text-white @6xl:flex"
					@click="openDiscordInvite"
				>
					<UIcon name="ic:baseline-discord" class="size-5 text-brand-400" />
					<span>{{ t("runs.discovery.results.placeholder_item.group_discord") }}</span>
				</button>
				<button
					v-else-if="item.group_slug"
					type="button"
					class="mt-5 hidden items-center gap-2 self-start text-left text-sm text-white/62 transition-colors hover:text-white @6xl:flex"
					@click="goToGroup"
				>
					<UIcon name="i-lucide-users" class="size-5 text-brand-400" />
					<span>{{ t("runs.discovery.results.placeholder_item.view_group") }}</span>
				</button>
			</section>
			<section class="order-3 grid grid-cols-[minmax(0,1fr)_auto_minmax(7.25rem,1.15fr)] items-end gap-3 border-t border-white/10 bg-neutral-950/88 p-3 md:grid-cols-[minmax(0,1fr)_minmax(0,0.75fr)_minmax(0,1fr)] md:gap-5 md:px-5 @6xl:order-none @6xl:flex @6xl:flex-col @6xl:items-stretch @6xl:gap-4 @6xl:border-l @6xl:border-t-0 @6xl:bg-neutral-950/58 @6xl:p-5">
				<div class="flex items-start gap-2 @6xl:gap-3">
					<UIcon name="i-lucide-calendar-days" class="mt-0.5 size-3.5 shrink-0 text-white/46 @6xl:size-4" />
					<div class="space-y-1">
						<p class="text-xs font-medium text-white @6xl:text-sm">
							{{ scheduleLabel }}
						</p>
						<p class="text-2xl font-semibold leading-none text-white">
							{{ timeLabel }}
						</p>
						<p class="text-[10px] uppercase tracking-[0.16em] text-white/46 @6xl:text-xs">
							{{ timezoneLabel }}
						</p>
					</div>
				</div>

				<div class="flex items-start gap-1.5 self-center text-sm text-white md:justify-self-center @6xl:w-full @6xl:items-center @6xl:gap-2 @6xl:self-auto @6xl:justify-self-auto">
					<UIcon name="i-lucide-users" class="mt-0.5 size-3.5 shrink-0 text-white/46 @6xl:mt-0 @6xl:size-4" />
					<span class="flex flex-col font-semibold leading-4 @6xl:hidden">
						<span>{{ memberCountLabel }}</span>
						<span class="font-normal text-white/62">{{ t("general.members") }}</span>
					</span>
					<span class="hidden font-semibold @6xl:inline">{{ memberCountLabel }} {{ t("general.members") }}</span>
				</div>

				<div
					class="flex min-w-0 flex-col gap-1.5 sm:w-full sm:max-w-36 sm:justify-self-end @6xl:max-w-none @6xl:justify-self-auto @6xl:gap-2"
				>
					<UButton
						v-if="item.links.apply"
						color="primary"
						class="min-h-9 w-full justify-center rounded-none"
						:label="item.has_existing_application
							? t('runs.discovery.results.placeholder_item.actions.view_application')
							: t('runs.discovery.results.placeholder_item.actions.apply_now')"
						@click="goToApply"
					/>
					<UButton
						color="neutral"
						variant="link"
						trailing-icon="i-lucide-arrow-right"
						class="w-full justify-end rounded-none px-0 py-0 text-xs text-white/72 @6xl:hidden"
						:label="t('runs.discovery.results.placeholder_item.actions.view_details')"
						@click="goToViewDetails"
					/>
					<UButton
						:color="item.links.apply ? 'neutral' : 'primary'"
						:variant="item.links.apply ? 'outline' : 'solid'"
						class="hidden w-full justify-center rounded-none @6xl:flex"
						:label="t('runs.discovery.results.placeholder_item.actions.view_details')"
						@click="goToViewDetails"
					/>
				</div>
			</section>
		</div>
	</article>
</template>
