<script setup lang="ts">
import type { ContextMenuItem } from "@nuxt/ui";
import type { ActivityCalendarDay, ActivityIndexItem, GroupQuickCreateShortcut } from "@/Types/ActivityCore";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import ActivityContextMenu from "@/components/Groups/Activities/ActivityContextMenu.vue";
import { localizedValue } from "@/utils/localizedValue";
import { getActivityStatusBorderClass } from "@/utils/activityStatusMeta";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";
import { quickCreateTimeModeLabel, resolveQuickCreateStartsAt } from "@/utils/groupQuickCreateShortcuts";

const props = defineProps<{
	groupSlug: string
	day: ActivityCalendarDay
	isSelected?: boolean
	canManageActivities?: boolean
	quickCreateShortcuts: GroupQuickCreateShortcut[]
	opensUpward?: boolean
}>();

const emit = defineEmits<{
	select: [dayKey: string]
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));
const { withDisplayTimeZone } = useTimeDisplayMode();

const visibleActivities = computed(() => props.day.activities.slice(0, 3));
const hiddenCount = computed(() => Math.max(0, props.day.activities.length - visibleActivities.value.length));

const activityTypeName = (activity: ActivityIndexItem) => {
	return localizedValue(activity.activity_type?.draft_name, locale.value, fallbackLocale.value)
		|| activity.activity_type?.slug
		|| t('groups.activities.cards.unknown_type');
};

const activityLabel = (activity: ActivityIndexItem) => activity.title || activityTypeName(activity);

const activityTargetProgPointLabel = (activity: ActivityIndexItem) => (
	activity.target_prog_point_key
		? localizedValue(activity.target_prog_point_label, locale.value, fallbackLocale.value) || activity.target_prog_point_key
		: null
);

const activityMemberCount = (activity: ActivityIndexItem) => t("groups.activities.calendar.member_count", {
	assigned: activity.assigned_slot_count,
	total: activity.slot_count,
});

const activityTime = (activity: ActivityIndexItem) => {
	if (!activity.starts_at) {
		return '';
	}

	return createDateTimeFormatter(locale.value, withDisplayTimeZone({
		hour: '2-digit',
		minute: '2-digit',
	})).format(new Date(activity.starts_at));
};

const activityStatusBorderClass = (activity: ActivityIndexItem) => getActivityStatusBorderClass(activity.status);

const selectDay = () => {
	emit('select', props.day.key);
};

const openCreateRunPage = (shortcut: GroupQuickCreateShortcut) => {
	if (!props.canManageActivities) {
		return;
	}

	router.get(route("groups.dashboard.activities.create", {
		group: props.groupSlug,
		starts_at: resolveQuickCreateStartsAt(props.day.key, shortcut),
	}));
};

const dayContextMenuItems = computed<ContextMenuItem[][]>(() => (
	props.canManageActivities
		? [props.quickCreateShortcuts.map((shortcut) => ({
			label: t("groups.shortcuts.context_menu.create_run_at", {
				time: shortcut.time,
				mode: quickCreateTimeModeLabel(shortcut),
			}),
			icon: "i-lucide-calendar-plus",
			onSelect: () => openCreateRunPage(shortcut),
		}))]
		: []
));
</script>

<template>
	<UContextMenu :items="dayContextMenuItems" :disabled="!canManageActivities">
		<div
			class="flex min-h-[9rem] cursor-pointer flex-col gap-2 border border-default/70 p-2 transition hover:border-primary/30 hover:bg-primary/5"
			role="button"
			tabindex="0"
			:class="[
				day.isCurrentMonth ? 'bg-background' : 'bg-muted/10',
				day.isToday ? 'bg-primary/6 ring-1 ring-primary/20' : '',
				isSelected ? 'border-primary/40 bg-primary/10 ring-1 ring-primary/35' : '',
			]"
			@click="selectDay"
			@keydown.enter.prevent="selectDay"
			@keydown.space.prevent="selectDay"
		>
			<div class="flex items-center justify-between">
				<div
					class="flex h-7 w-7 items-center justify-center rounded-full text-sm font-semibold"
					:class="isSelected
						? 'bg-primary text-white'
						: day.isToday
							? 'bg-primary/12 text-primary'
							: day.isCurrentMonth
								? 'text-toned'
								: 'text-muted'"
				>
					{{ day.date.getDate() }}
				</div>
			</div>

			<div class="flex flex-1 flex-col gap-1.5">
				<ActivityContextMenu
					v-for="activity in visibleActivities"
					:key="activity.id"
					:group-slug="groupSlug"
					:can-manage-activities="Boolean(canManageActivities)"
					:activity="activity"
				>
					<div
						class="group relative z-0 origin-top-left overflow-visible rounded-sm border-t-2 bg-primary/15 px-2 py-1.5 text-xs shadow-sm transition duration-150 ease-out hover:z-50 hover:scale-125 hover:bg-elevated hover:shadow-xl"
						:class="activityStatusBorderClass(activity)"
					>
						<div
							v-if="activity.has_existing_application"
							class="pointer-events-none absolute -left-1.5 -top-2 z-20 flex h-5 w-5 items-center justify-center"
							:aria-label="t('groups.dashboard.upcoming_runs.view_application')"
							:title="t('groups.dashboard.upcoming_runs.view_application')"
						>
							<UIcon
								name="i-lucide-pin"
								class="h-5 w-5 -rotate-35 text-brand-400 drop-shadow-[0_3px_8px_rgba(168,85,247,0.75)]"
							/>
						</div>

						<p class="font-medium text-toned">
							{{ activityTime(activity) }}
						</p>
						<p class="mt-0.5 line-clamp-2 text-muted">
							{{ activityLabel(activity) }}
						</p>

						<div
							class="pointer-events-none absolute inset-x-0 z-50 rounded-sm border border-default bg-elevated px-2 py-1.5 opacity-0 shadow-xl transition-opacity duration-150 group-hover:opacity-100"
							:class="opensUpward ? 'bottom-full mb-1' : 'top-full mt-1'"
						>
							<div class="flex items-start gap-1.5 text-[0.68rem] font-medium text-toned">
								<UIcon name="i-lucide-layers-3" class="size-3 shrink-0 text-primary" />
								<span class="min-w-0 flex-1 whitespace-normal break-words leading-snug">{{ activityTypeName(activity) }}</span>
							</div>
							<div class="mt-1 flex items-center gap-1.5 text-[0.68rem] font-medium text-muted">
								<UIcon name="i-lucide-users" class="size-3 shrink-0 text-primary" />
								<span>{{ activityMemberCount(activity) }}</span>
							</div>
							<UBadge
								v-if="activityTargetProgPointLabel(activity)"
								class="mt-2"
								:label="activityTargetProgPointLabel(activity)"
								color="neutral"
								variant="soft"
								size="md"
							/>
						</div>
					</div>
				</ActivityContextMenu>

				<p v-if="hiddenCount > 0" class="mt-auto text-xs font-medium text-muted">
					{{ t('groups.activities.calendar.more', { count: hiddenCount }) }}
				</p>
			</div>
		</div>
	</UContextMenu>
</template>
