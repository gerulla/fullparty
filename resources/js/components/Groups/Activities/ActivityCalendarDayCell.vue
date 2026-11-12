<script setup lang="ts">
import type { ContextMenuItem } from "@nuxt/ui";
import type { ActivityCalendarDay, ActivityIndexItem, GroupQuickCreateShortcut } from "@/Types/ActivityCore";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Link, router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import ActivityContextMenu from "@/components/Groups/Activities/ActivityContextMenu.vue";
import { localizedValue } from "@/utils/localizedValue";
import { getActivityStatusMeta } from "@/utils/activityStatusMeta";
import { quickCreateTimeModeLabel, resolveQuickCreateStartsAt } from "@/utils/groupQuickCreateShortcuts";
import { useDateTimeFormat } from "@/composables/useDateTimeFormat";

const props = defineProps<{
	groupSlug?: string
	day: ActivityCalendarDay
	isSelected?: boolean
	canManageActivities?: boolean
	quickCreateShortcuts: GroupQuickCreateShortcut[]
	groupColors?: Record<number, string>
	expandSelected?: boolean
}>();

const emit = defineEmits<{
	select: [dayKey: string]
	preview: [activity: ActivityIndexItem, anchor: HTMLElement, focusContent?: boolean]
	leavePreview: []
	closePreview: []
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));
const { formatTimeWithMode } = useDateTimeFormat();

const visibleActivities = computed(() => props.expandSelected && props.isSelected
	? props.day.activities
	: props.day.activities.slice(0, 3));
const hiddenCount = computed(() => Math.max(0, props.day.activities.length - visibleActivities.value.length));

const activityTypeName = (activity: ActivityIndexItem) => {
	return localizedValue(activity.activity_type?.draft_name, locale.value, fallbackLocale.value)
		|| activity.activity_type?.slug
		|| t('groups.activities.cards.unknown_type');
};

const activityLabel = (activity: ActivityIndexItem) => activity.title || activityTypeName(activity);

const activityMemberCount = (activity: ActivityIndexItem) => activity.assigned_slot_count > 0
	? t('groups.activities.calendar.assigned_count', {
		assigned: activity.assigned_slot_count,
		total: activity.slot_count,
	})
	: t('groups.activities.calendar.applied_count', { count: activity.application_count });

const activityTime = (activity: ActivityIndexItem) => formatTimeWithMode(
	activity.starts_at,
	t('groups.activities.cards.no_time'),
);

const activityAvatar = (activity: ActivityIndexItem) => ({
	src: activity.organized_by_character?.avatar_url || activity.organized_by?.avatar_url || undefined,
	alt: activity.organized_by_character?.name || activity.organized_by?.name || t('groups.activities.cards.no_organizer'),
	icon: 'i-lucide-user-round',
});

const activityBorderColor = (activity: ActivityIndexItem) => {
	const groupColor = activity.group ? props.groupColors?.[activity.group.id] : undefined;
	const { color } = getActivityStatusMeta(activity.status);
	return groupColor || (color === 'neutral' ? 'var(--ui-text-highlighted)' : `var(--ui-${color})`);
};

const activityGroupSlug = (activity: ActivityIndexItem) => activity.group?.slug ?? props.groupSlug ?? '';
const canManageActivity = (activity: ActivityIndexItem) => activity.group?.can_manage_activities ?? Boolean(props.canManageActivities);
const activityHref = (activity: ActivityIndexItem) => canManageActivity(activity)
	? route('groups.dashboard.activities.show', { group: activityGroupSlug(activity), activity: activity.id })
	: activity.links.view;

const previewActivity = (activity: ActivityIndexItem, event: Event, focusContent = false) => {
	if ('pointerType' in event && event.pointerType === 'touch') return;
	if (event.currentTarget instanceof HTMLElement) {
		emit('preview', activity, event.currentTarget, focusContent);
	}
};

const selectDay = () => {
	emit('select', props.day.key);
};

const openCreateRunPage = (shortcut: GroupQuickCreateShortcut) => {
	if (!props.canManageActivities || !props.groupSlug) {
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
			class="flex min-w-0 cursor-pointer flex-col gap-3 border-b border-default/70 px-1 py-3 outline-offset-[-2px] focus-visible:outline-2 focus-visible:outline-primary sm:px-2"
			role="button"
			tabindex="0"
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

			<div class="flex min-w-0 flex-1 flex-col gap-3">
				<ActivityContextMenu
					v-for="activity in visibleActivities"
					:key="activity.id"
					:group-slug="activityGroupSlug(activity)"
					:can-manage-activities="canManageActivity(activity)"
					:activity="activity"
				>
					<Link
						:href="activityHref(activity)"
						class="relative grid min-w-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 rounded-sm border-l-3 bg-elevation-1 py-2 pl-2 text-xs shadow-md outline-offset-2 transition duration-150 hover:z-10 hover:scale-105 hover:bg-elevation-1 focus-visible:outline-2 focus-visible:outline-primary dark:bg-elevation-4 dark:shadow-none dark:hover:bg-elevation-5 motion-reduce:transition-none motion-reduce:hover:scale-100"
						:style="{ borderLeftColor: activityBorderColor(activity) }"
						tabindex="0"
						aria-haspopup="dialog"
						:aria-label="activityLabel(activity)"
						@pointerenter="previewActivity(activity, $event)"
						@pointerleave="emit('leavePreview')"
						@focus="previewActivity(activity, $event)"
						@blur="emit('leavePreview')"
						@click.stop="emit('closePreview')"
						@keydown.enter.stop
						@keydown.down.stop.prevent="previewActivity(activity, $event, true)"
						@keydown.space.stop.prevent="previewActivity(activity, $event, true)"
						@keydown.esc.stop="emit('closePreview')"
						@contextmenu="emit('closePreview')"
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

						<UUser
							:name="activityLabel(activity)"
							:description="activityTime(activity)"
							:avatar="activityAvatar(activity)"
							size="sm"
							class="min-w-0 flex-nowrap items-center gap-2 xl:col-span-2"
							:ui="{
								avatar: 'size-10 shrink-0 xl:hidden',
								wrapper: 'min-w-0 flex-1',
								name: 'whitespace-normal break-words text-sm font-semibold leading-5',
								description: 'whitespace-nowrap text-xs leading-5 text-muted xl:hidden',
							}"
						>
							<template #name><span :title="activityLabel(activity)">{{ activityLabel(activity) }}</span></template>
						</UUser>

						<span class="hidden whitespace-nowrap text-xs leading-5 text-muted xl:block">{{ activityTime(activity) }}</span>

						<div class="flex shrink-0 flex-col items-end gap-1 whitespace-nowrap text-right">
							<UBadge
								:label="t(`groups.activities.statuses.${activity.status}`)"
								:icon="getActivityStatusMeta(activity.status).icon"
								:color="getActivityStatusMeta(activity.status).color"
								variant="soft"
								size="xs"
								class="rounded-md"
							/>
							<span class="text-xs leading-5 text-muted xl:hidden">{{ activityMemberCount(activity) }}</span>
						</div>
					</Link>
				</ActivityContextMenu>

				<p v-if="hiddenCount > 0" class="mt-auto text-xs font-medium text-muted">
					{{ t('groups.activities.calendar.more', { count: hiddenCount }) }}
				</p>
			</div>
		</div>
	</UContextMenu>
</template>
