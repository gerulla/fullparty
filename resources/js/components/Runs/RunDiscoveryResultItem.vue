<script setup lang="ts">
import type { RunDiscoveryResultItemData } from "../../Types/RunDiscovery";
import { computed } from "vue";
import { router } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";

const props = defineProps<{
	item: RunDiscoveryResultItemData
	savePending?: boolean
}>();

const emit = defineEmits<{
	toggleSaved: [item: RunDiscoveryResultItemData]
}>();

const { t, locale } = useI18n();
const { withDisplayTimeZone } = useTimeDisplayMode();

const roleSlotIconUrls: Record<string, string> = {
	tank: "/role-icons/tank.png",
	healer: "/role-icons/healer.png",
	dps: "/role-icons/dps.png",
};

const contentName = computed(() => props.item.activity_type_name);
const showContentName = computed(() => contentName.value !== "");
const targetProgPointLabel = computed(() => props.item.target_prog_point_label || props.item.target_prog_point_key);

const descriptionLabel = computed(() => props.item.description || t("groups.activities.overview.details.no_description"));

const tagLabels = computed(() => {
	const tags: string[] = [];

	if (props.item.run_style) {
		tags.push(t(`runs.discovery.filters.options.run_styles.${props.item.run_style}`));
	}

	if (props.item.intensity) {
		tags.push(t(`runs.discovery.filters.options.intensity.${props.item.intensity}`));
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
const organizerName = computed(() => props.item.organizer?.name || t("groups.activities.cards.no_organizer"));
const organizerAvatarUrl = computed(() => props.item.organizer?.avatar_url ?? null);

const goToViewDetails = () => {
	router.get(props.item.links.view);
};

const goToApply = () => {
	if (!props.item.links.apply) {
		return;
	}

	router.get(props.item.links.apply);
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

const toggleSaved = () => {
	emit("toggleSaved", props.item);
};
</script>

<template>
	<article
		class="relative isolate overflow-visible border border-white/10 bg-neutral-950/72 shadow-[0_20px_40px_rgba(0,0,0,0.2)]"
		:class="props.item.has_existing_application ? 'border-r-4 border-r-brand-400' : ''"
	>
		<div
			v-if="item.has_existing_application"
			class="pointer-events-none absolute -left-2 -top-2 z-20 flex h-8 w-8 items-center justify-center"
			:aria-label="t('runs.discovery.results.placeholder_item.actions.view_application')"
			:title="t('runs.discovery.results.placeholder_item.actions.view_application')"
		>
			<UIcon
				name="i-lucide-pin"
				class="h-8 w-8 -rotate-35 text-brand-400 drop-shadow-[0_4px_10px_rgba(168,85,247,0.85)]"
			/>
		</div>

		<img
			v-if="item.image_url"
			:src="item.image_url"
			:alt="item.title"
			class="absolute inset-0 h-full w-full object-cover xl:hidden"
		>
		<div
			v-else
			class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(123,97,153,0.34),transparent_46%),radial-gradient(circle_at_center_right,rgba(84,136,184,0.28),transparent_38%),linear-gradient(180deg,#201c24_0%,#151217_100%)] xl:hidden"
		/>
		<div class="absolute inset-0 bg-linear-to-b from-neutral-950/58 via-neutral-950/74 to-neutral-950/94 xl:hidden" />

		<div class="relative z-10 grid grid-cols-2 gap-3 p-4 sm:grid-cols-[minmax(0,1.5fr)_minmax(8rem,0.85fr)_minmax(8rem,0.85fr)] sm:items-center sm:gap-4 sm:p-5 xl:grid-cols-[7rem_minmax(0,1.6fr)_11rem_10rem_11rem] xl:items-stretch xl:p-0">
			<div class="relative hidden h-full border border-white/8 bg-neutral-900/70 xl:block">
				<img
					v-if="item.image_url"
					:src="item.image_url"
					:alt="item.title"
					class="h-full min-h-38 w-34 object-cover"
				>
				<div
					v-else
					class="h-full min-h-38 w-34 bg-[radial-gradient(circle_at_top_left,rgba(123,97,153,0.34),transparent_46%),radial-gradient(circle_at_center_right,rgba(84,136,184,0.28),transparent_38%),linear-gradient(180deg,#201c24_0%,#151217_100%)]"
				/>
				<div v-if="showContentName" class="pointer-events-none absolute inset-x-0 bottom-0 p-2 bg-neutral-950/70">
					<p class="block text-center font-semibold uppercase text-xs">{{ contentName }}</p>
				</div>
			</div>

			<div class="col-span-2 min-w-0 space-y-3 sm:col-span-1 xl:py-4 xl:pr-2">
				<div class="flex items-start justify-between gap-3">
					<div class="min-w-0 space-y-2">
						<div class="flex flex-wrap items-center gap-2">
							<h3 class="text-xl font-semibold leading-tight text-white">
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

						<div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-white/70">
							<button
								v-if="item.group_name && item.group_slug"
								type="button"
								class="flex max-w-96 min-w-0 cursor-pointer items-center gap-2 text-left transition-colors hover:text-white"
								@click="goToGroup"
							>
								<UIcon name="i-lucide-users" class="size-4 shrink-0 text-white/50" />
								<span class="truncate">{{ item.group_name }}</span>
							</button>
							<div v-else-if="item.group_name" class="flex max-w-96 min-w-0 items-center gap-2">
								<UIcon name="i-lucide-users" class="size-4 shrink-0 text-white/50" />
								<span class="truncate">{{ item.group_name }}</span>
							</div>
						</div>
					</div>

					<UButton
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

				<p class="max-w-2xl text-sm leading-6 text-white/68">
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

			<div class="col-span-1 space-y-3 border-t border-white/10 pt-3 sm:border-l sm:border-t-0 sm:pl-5 xl:border-white/8 xl:pt-0">
				<div class="flex items-start gap-3">
					<UIcon name="i-lucide-calendar-days" class="mt-0.5 size-4 text-white/46" />
					<div class="space-y-1">
						<p class="text-sm font-medium text-white">
							{{ scheduleLabel }}
						</p>
						<p class="text-2xl font-semibold leading-none text-white">
							{{ timeLabel }}
						</p>
						<p class="text-sm uppercase tracking-[0.18em] text-white/46">
							{{ timezoneLabel }}
						</p>
					</div>
				</div>

				<div class="flex items-start gap-3 text-white/70 xl:hidden">
					<UAvatar
						v-if="organizerAvatarUrl"
						:src="organizerAvatarUrl"
						:alt="organizerName"
						size="xs"
						class="mt-0.5 shrink-0"
					/>
					<UIcon v-else name="i-lucide-user-round" class="mt-0.5 size-4 shrink-0 text-white/46" />
					<div class="min-w-0 space-y-1">
						<p class="text-xs font-semibold uppercase tracking-[0.16em] text-white/46">
							{{ t("groups.activities.context_menu.summary_labels.host") }}
						</p>
						<p class="truncate text-sm font-medium text-white sm:text-base">
							{{ organizerName }}
						</p>
					</div>
				</div>

				<div class="hidden items-start gap-3 text-white/70 xl:flex">
					<UIcon name="i-lucide-globe" class="mt-0.5 size-4 text-white/46" />
					<div class="space-y-1">
						<p class="text-base font-medium text-white">
							{{ item.datacenter || "—" }}
						</p>
						<p v-if="item.world" class="text-sm">
							{{ item.world }}
						</p>
					</div>
				</div>
			</div>

			<div class="hidden border-t border-white/10 pt-3 xl:block xl:border-l xl:border-t-0 xl:border-white/8 xl:pt-0 xl:pl-5">
				<div class=" p-3">
					<p class="mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-white/48">
						{{ t("runs.discovery.results.placeholder_item.open_slots") }}
					</p>

					<div class="grid gap-3">
						<div
							v-for="roleSlot in item.role_slots"
							:key="roleSlot.key"
							class="grid grid-cols-[1.25rem_1fr_auto] items-center gap-2"
						>
							<img
								:src="roleSlotIconUrls[roleSlot.key]"
								:alt="roleSlot.key"
								class="size-5 object-contain"
							>
							<div class="h-px bg-white/10" />
							<span class="text-sm font-medium text-white/78">{{ roleSlot.count > 0 ? roleSlot.count : "—" }}</span>
						</div>
					</div>
				</div>
			</div>

			<div class="col-span-1 border-t border-white/10 pt-3 sm:border-l sm:border-t-0 sm:pl-5 xl:border-white/8 xl:pt-0">
				<div class="flex h-full flex-col justify-between gap-4">
					<div class="flex items-center justify-center">
						<p class="text-md font-semibold text-white">
							{{ memberCountLabel }} {{ t("general.members") }}
						</p>
					</div>

					<div class="space-y-2 xl:pr-4">
						<UButton
							color="primary"
							class="w-full justify-center rounded-none"
							:label="t('runs.discovery.results.placeholder_item.actions.view_details')"
							@click="goToViewDetails"
						/>
						<UButton
							v-if="item.links.apply"
							color="neutral"
							variant="outline"
							class="w-full justify-center rounded-none border-brand-400/45 text-white hover:bg-brand-500/10"
							:label="item.has_existing_application
								? t('runs.discovery.results.placeholder_item.actions.view_application')
								: t('runs.discovery.results.placeholder_item.actions.apply_now')"
							@click="goToApply"
						/>
					</div>
				</div>
			</div>
		</div>
	</article>
</template>
