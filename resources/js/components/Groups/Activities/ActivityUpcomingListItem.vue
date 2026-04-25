<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { localizedValue } from "@/utils/localizedValue";
import type { ActivityIndexItem } from "@/components/Groups/Activities/types";

const props = defineProps<{
	groupSlug: string
	canManageActivities: boolean
	activity: ActivityIndexItem
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));

const activityDate = computed(() => props.activity.starts_at ? new Date(props.activity.starts_at) : null);

const activityTypeName = computed(() => {
	return localizedValue(props.activity.activity_type?.draft_name, locale.value, fallbackLocale.value)
		|| props.activity.activity_type?.slug
		|| t('groups.activities.cards.unknown_type');
});

const activityTitle = computed(() => props.activity.title || activityTypeName.value);

const dateParts = computed(() => {
	if (!activityDate.value) {
		return {
			weekday: t('groups.activities.cards.unscheduled'),
			day: '—',
			month: '',
		};
	}

	return {
		weekday: new Intl.DateTimeFormat(locale.value, { weekday: 'short' }).format(activityDate.value),
		day: new Intl.DateTimeFormat(locale.value, { day: 'numeric' }).format(activityDate.value),
		month: new Intl.DateTimeFormat(locale.value, { month: 'short' }).format(activityDate.value),
	};
});

const startsAtLabel = computed(() => {
	if (!activityDate.value) {
		return t('groups.activities.cards.no_time');
	}

	return new Intl.DateTimeFormat(locale.value, {
		weekday: 'long',
		day: 'numeric',
		month: 'long',
		hour: '2-digit',
		minute: '2-digit',
	}).format(activityDate.value);
});

const relativeLabel = computed(() => {
	if (!activityDate.value) {
		return t('groups.activities.cards.no_relative_time');
	}

	const diffMs = activityDate.value.getTime() - Date.now();
	const diffMinutes = Math.round(diffMs / (1000 * 60));
	const absoluteMinutes = Math.abs(diffMinutes);
	const formatter = new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' });

	if (absoluteMinutes < 60) {
		return formatter.format(diffMinutes, 'minute');
	}

	const diffHours = Math.round(diffMinutes / 60);
	const absoluteHours = Math.abs(diffHours);

	if (absoluteHours < 24) {
		return formatter.format(diffHours, 'hour');
	}

	const diffDays = Math.round(diffHours / 24);

	return formatter.format(diffDays, 'day');
});

const statusMeta = computed(() => ({
	draft: { color: 'neutral', icon: 'i-lucide-file-pen-line' },
	planned: { color: 'neutral', icon: 'i-lucide-clipboard-list' },
	scheduled: { color: 'warning', icon: 'i-lucide-calendar-check-2' },
	upcoming: { color: 'primary', icon: 'i-lucide-sparkles' },
	ongoing: { color: 'secondary', icon: 'i-lucide-activity' },
	complete: { color: 'success', icon: 'i-lucide-flag' },
	cancelled: { color: 'error', icon: 'i-lucide-ban' },
}[props.activity.status] ?? { color: 'neutral', icon: 'i-lucide-calendar-range' }));

const goToManagementPage = () => {
	if (!props.canManageActivities) {
		router.get(route('groups.activities.overview', {
			group: props.groupSlug,
			activity: props.activity.id,
		}));

		return;
	}

	router.get(route('groups.dashboard.activities.show', {
		group: props.groupSlug,
		activity: props.activity.id,
	}));
};
</script>

<template>
	<div
		class="cursor-pointer rounded-sm border border-default bg-elevated/50 px-4 py-4 transition hover:border-primary/40 hover:bg-primary/5 hover:shadow-sm"
		role="button"
		tabindex="0"
		@click="goToManagementPage"
		@keydown.enter.prevent="goToManagementPage"
		@keydown.space.prevent="goToManagementPage"
	>
		<div class="flex flex-col gap-4 xl:flex-row xl:items-start">
			<div class="flex h-20 w-20 shrink-0 flex-col items-center justify-center rounded-sm border border-default bg-background text-center">
				<p class="text-xs font-semibold uppercase tracking-[0.16em] text-muted">
					{{ dateParts.weekday }}
				</p>
				<p class="mt-1 text-2xl font-black text-toned">
					{{ dateParts.day }}
				</p>
				<p class="text-xs uppercase tracking-wide text-muted">
					{{ dateParts.month }}
				</p>
			</div>

			<div class="min-w-0 flex-1">
				<div class="flex flex-col gap-3">
					<div class="flex flex-wrap items-start justify-between gap-3">
						<div class="min-w-0">
							<div class="flex flex-wrap items-center gap-2">
								<h3 class="text-base font-semibold text-toned">
									{{ activityTitle }}
								</h3>
								<UBadge
									:label="t(`groups.activities.statuses.${activity.status}`)"
									:color="statusMeta.color"
									:icon="statusMeta.icon"
									variant="subtle"
								/>
							</div>
							<p class="mt-1 text-sm text-muted">
								{{ activityTypeName }}
							</p>
						</div>

						<div class="flex w-full flex-col gap-2 text-sm xl:items-end">
							<p class="font-medium text-toned xl:text-right">
								{{ startsAtLabel }}
							</p>
							<p class="text-muted xl:text-right">
								{{ relativeLabel }}
							</p>
						</div>
					</div>

					<div class="flex flex-wrap items-center justify-between gap-4 text-sm text-muted">
						<div class="flex items-center gap-2">
							<UIcon name="i-lucide-user-round" class="text-base" />
							<span>{{ activity.organized_by?.name || t('groups.activities.cards.no_organizer') }}</span>
						</div>
						<div class="flex items-center gap-2">
							<UIcon name="i-lucide-users-round" class="text-base" />
							<span>{{ t('groups.activities.cards.slots', { count: activity.slot_count }) }}</span>
						</div>
						<div class="flex items-center gap-2">
							<UIcon name="i-lucide-file-text" class="text-base" />
							<span>{{ t('groups.activities.cards.applications', { count: activity.application_count }) }}</span>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>
