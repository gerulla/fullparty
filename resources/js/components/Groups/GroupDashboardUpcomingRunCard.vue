<script setup lang="ts">
import type { GroupDashboardActivity } from "@/Types/Groups";
import { computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import { localizedValue } from "@/utils/localizedValue";
import { formatRelativeTime } from "@/utils/formatRelativeTime";
import { getActivityStatusMeta } from "@/utils/activityStatusMeta";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";

const props = defineProps<{
	activity: GroupDashboardActivity
}>();

const page = usePage();
const { t, locale } = useI18n();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? "en"));
const { withDisplayTimeZone } = useTimeDisplayMode();

const activityTypeName = computed(() => localizedValue(
	props.activity.activity_type?.draft_name,
	locale.value,
	fallbackLocale.value,
) || props.activity.activity_type?.slug || t("groups.activities.cards.unknown_type"));

const activityTitle = computed(() => props.activity.title || activityTypeName.value);
const activitySubtitle = computed(() => (
	props.activity.title && props.activity.title !== activityTypeName.value
		? activityTypeName.value
		: null
));

const statusMeta = computed(() => getActivityStatusMeta(props.activity.status));

const startsAtDate = computed(() => props.activity.starts_at ? new Date(props.activity.starts_at) : null);

const startsAtLabel = computed(() => {
	if (! startsAtDate.value) {
		return t("groups.activities.cards.no_time");
	}

	return createDateTimeFormatter(locale.value, withDisplayTimeZone({
		weekday: "short",
		day: "numeric",
		month: "short",
		hour: "2-digit",
		minute: "2-digit",
	})).format(startsAtDate.value);
});

const relativeStartsAtLabel = computed(() => formatRelativeTime(
	props.activity.starts_at,
	locale.value,
	t("groups.dashboard.labels.just_now"),
	t("groups.activities.cards.no_relative_time"),
));

const targetProgPointLabel = computed(() => (
	props.activity.target_prog_point_key
		? localizedValue(props.activity.target_prog_point_label, locale.value, fallbackLocale.value) || props.activity.target_prog_point_key
		: null
));

const imageUrl = computed(() => props.activity.banner_image_url || props.activity.small_image_url || null);

const goToView = () => {
	router.get(props.activity.links.view);
};

const goToApply = () => {
	if (! props.activity.links.apply) {
		return;
	}

	router.get(props.activity.links.apply);
};
</script>

<template>
	<div class="relative flex h-full w-[20rem] shrink-0 snap-start flex-col overflow-visible border border-white/10 bg-neutral-950 shadow-[0_20px_50px_rgba(0,0,0,0.32)]">
		<div
			v-if="activity.has_existing_application"
			class="pointer-events-none absolute -left-2 -top-2 z-20 flex h-8 w-8 items-center justify-center"
			:aria-label="t('groups.dashboard.upcoming_runs.view_application')"
			:title="t('groups.dashboard.upcoming_runs.view_application')"
		>
			<UIcon
				name="i-lucide-pin"
				class="h-8 w-8 -rotate-35 text-brand-400 drop-shadow-[0_4px_10px_rgba(168,85,247,0.85)]"
			/>
		</div>

		<div class="relative h-56 overflow-hidden border-b border-white/10 bg-neutral-900">
			<img
				v-if="imageUrl"
				:src="imageUrl"
				:alt="activityTitle"
				class="absolute inset-0 size-full object-cover"
			>
			<div
				v-else
				class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(123,97,153,0.34),transparent_46%),radial-gradient(circle_at_center_right,rgba(84,136,184,0.28),transparent_38%),linear-gradient(180deg,#201c24_0%,#151217_100%)]"
			/>
			<div class="absolute inset-0 bg-gradient-to-t from-neutral-950 via-neutral-950/50 to-neutral-950/10" />
			<div class="absolute inset-x-0 top-0 flex items-center justify-between gap-3 p-4">
				<UBadge
					:label="t(`groups.activities.statuses.${activity.status}`)"
					:color="statusMeta.color"
					:icon="statusMeta.icon"
					variant="subtle"
				/>
				<UBadge
					:label="activity.is_public ? t('groups.dashboard.upcoming_runs.public') : t('groups.dashboard.upcoming_runs.private')"
					color="neutral"
					variant="soft"
				/>
			</div>

			<div class="absolute flex flex-col inset-x-0 bottom-0 p-5 gap-1">
				<p class="text-[11px] uppercase tracking-[0.18em] text-white drop-shadow-[0_2px_10px_rgba(0,0,0,0.42)]">
					{{ t("groups.dashboard.upcoming_runs.starts", { time: startsAtLabel }) }}
				</p>
				<h3 class="truncate text-xl font-semibold leading-tight text-white drop-shadow-[0_2px_10px_rgba(0,0,0,0.42)] break-words [overflow-wrap:anywhere]">
					{{ activityTitle }}
				</h3>
				<p
					v-if="activitySubtitle"
					class=" text-sm text-white/72 drop-shadow-[0_2px_10px_rgba(0,0,0,0.42)] break-words [overflow-wrap:anywhere]"
				>
					{{ activitySubtitle }}
				</p>
				<div class="flex flex-wrap items-center gap-2 text-sm text-white/68 drop-shadow-[0_2px_10px_rgba(0,0,0,0.42)]">
					<span>{{ relativeStartsAtLabel }}</span>
					<UBadge
						v-if="targetProgPointLabel"
						:label="targetProgPointLabel"
						color="neutral"
						variant="soft"
					/>
				</div>

				<div class="flex flex-wrap gap-2">
					<UBadge
						v-if="activity.can_apply"
						:label="t('groups.dashboard.upcoming_runs.accepting_applications')"
						color="success"
						variant="soft"
					/>
					<UBadge
						v-if="activity.allow_guest_applications"
						:label="t('groups.dashboard.upcoming_runs.guest_welcome')"
						color="info"
						variant="soft"
					/>
				</div>
			</div>
		</div>

		<div class="flex flex-1 flex-col justify-end p-2">
			<div class="flex items-center gap-3">
				<UButton
					color="neutral"
					variant="subtle"
					icon="i-lucide-arrow-right"
					:label="t('groups.dashboard.upcoming_runs.view_more')"
					class="flex-1 justify-center"
					@click="goToView"
				/>
				<UButton
					v-if="activity.links.apply"
					color="primary"
					variant="solid"
					icon="i-lucide-file-pen-line"
					:label="activity.has_existing_application
						? t('groups.dashboard.upcoming_runs.view_application')
						: t('groups.dashboard.upcoming_runs.apply')"
					class="flex-1 justify-center"
					@click="goToApply"
				/>
			</div>
		</div>
	</div>
</template>
