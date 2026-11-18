<script setup lang="ts">
import type { ActivityTypeOption, OrganizerCharacterOption } from "@/Types/ActivityCore";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { localizedValue } from "@/utils/localizedValue";
import { usePage } from "@inertiajs/vue3";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";
import { getActivityStatusMeta } from "@/utils/activityStatusMeta";

const props = defineProps<{
	form: {
		activity_type_id: number | null
		organized_by_user_id: number | null
		organized_by_character_id: number | null
		status: string
		title: string
		notes: string
		starts_at: string | null
		duration_hours: number
		datacenter: string | null
		intensity: string
		min_item_level: number | null
		beginner_friendly: boolean
		run_style: string
		target_prog_point_key: string | null
		is_public: boolean
		needs_application: boolean
		allow_guest_applications: boolean
	}
	activityTypes: ActivityTypeOption[]
	organizerCharacters: OrganizerCharacterOption[]
}>();

type SummaryRow = {
	icon: string
	label: string
	value: string | number
};

type SummaryChip = {
	icon: string
	label: string
	value: string
};

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));
const localTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;

const selectedActivityType = computed(() => props.activityTypes.find((activityType) => activityType.id === props.form.activity_type_id) ?? null);
const selectedOrganizerCharacter = computed(() => props.organizerCharacters.find((character) => character.id === props.form.organized_by_character_id) ?? null);
const selectedTargetProgPoint = computed(() => selectedActivityType.value?.prog_points.find((progPoint) => progPoint.key === props.form.target_prog_point_key) ?? null);

const activityTypeName = computed(() => {
	if (!selectedActivityType.value) {
		return t('groups.activities.create.summary.no_type');
	}

	return localizedValue(selectedActivityType.value.draft_name, locale.value, fallbackLocale.value)
		|| selectedActivityType.value.slug;
});

const displayTitle = computed(() => props.form.title.trim() || activityTypeName.value);
const statusMeta = computed(() => getActivityStatusMeta(props.form.status));
const statusLabel = computed(() => props.form.status ? t(`groups.activities.statuses.${props.form.status}`) : '—');
const activityArtworkUrl = computed(() => selectedTargetProgPoint.value?.banner_image_url || selectedActivityType.value?.banner_image_url || selectedActivityType.value?.small_image_url || null);
const activityThumbnailUrl = computed(() => selectedActivityType.value?.small_image_url || selectedActivityType.value?.banner_image_url || null);
const organizerName = computed(() => selectedOrganizerCharacter.value?.name || t('groups.activities.create.summary.no_organizer'));
const organizerUserName = computed(() => selectedOrganizerCharacter.value?.user_name ?? null);

const difficultyLabel = computed(() => selectedActivityType.value?.difficulty
	? t(`groups.activities.difficulties.${selectedActivityType.value.difficulty}`)
	: '—');

const serverStartLabel = computed(() => {
	if (!props.form.starts_at) {
		return t('groups.activities.create.summary.no_date');
	}

	const serverTimeDate = new Date(`${props.form.starts_at}:00Z`);

	return createDateTimeFormatter(locale.value, {
		weekday: 'long',
		day: 'numeric',
		month: 'long',
		hour: '2-digit',
		minute: '2-digit',
		timeZone: 'UTC',
	}).format(serverTimeDate);
});

const localStartLabel = computed(() => {
	if (!props.form.starts_at) {
		return t('groups.activities.create.summary.no_date');
	}

	const serverTimeDate = new Date(`${props.form.starts_at}:00Z`);

	return createDateTimeFormatter(locale.value, {
		weekday: 'long',
		day: 'numeric',
		month: 'long',
		hour: '2-digit',
		minute: '2-digit',
		timeZoneName: 'short',
	}).format(serverTimeDate);
});

const visibilityLabel = computed(() => t(
	props.form.is_public
		? 'groups.activities.create.summary.visibility_public'
		: 'groups.activities.create.summary.visibility_private'
));

const assignmentLabel = computed(() => t(
	props.form.needs_application
		? 'groups.activities.create.summary.assignment_application'
		: 'groups.activities.create.summary.assignment_self_assign'
));

const guestApplicationsLabel = computed(() => {
	if (!props.form.needs_application) {
		return t('groups.activities.create.summary.guest_applications_not_applicable');
	}

	return t(
		props.form.allow_guest_applications
			? 'groups.activities.create.summary.guest_applications_enabled'
			: 'groups.activities.create.summary.guest_applications_disabled'
	);
});

const runStyleLabel = computed(() => props.form.run_style
	? t(`groups.activities.run_styles.${props.form.run_style}`)
	: '—');

const intensityLabel = computed(() => props.form.intensity
	? t(`groups.activities.intensities.${props.form.intensity}`)
	: '—');

const minimumItemLevelLabel = computed(() => props.form.min_item_level
	? String(props.form.min_item_level)
	: t('groups.activities.create.summary.min_item_level_disabled'));

const beginnerFriendlyLabel = computed(() => t(
	props.form.beginner_friendly
		? 'general.yes'
		: 'general.no'
));

const targetProgPointLabel = computed(() => selectedTargetProgPoint.value
	? (localizedValue(selectedTargetProgPoint.value.label, locale.value, fallbackLocale.value) || selectedTargetProgPoint.value.key)
	: t('groups.activities.create.summary.no_target_prog_point'));

const activityChips = computed<SummaryChip[]>(() => [
	{
		icon: 'i-lucide-route',
		label: t('groups.activities.create.summary.run_style'),
		value: runStyleLabel.value,
	},
	{
		icon: 'i-lucide-gauge',
		label: t('groups.activities.create.summary.intensity'),
		value: intensityLabel.value,
	},
]);

const overviewRows = computed<SummaryRow[]>(() => {
	const rows: SummaryRow[] = [
		{
			icon: 'i-lucide-server',
			label: t('groups.activities.create.summary.datacenter'),
			value: props.form.datacenter || '—',
		},
		{
			icon: 'i-lucide-users-round',
			label: t('groups.activities.create.summary.slots'),
			value: selectedActivityType.value?.slot_count ?? 0,
		},
		{
			icon: 'i-lucide-swords',
			label: t('groups.activities.create.summary.difficulty'),
			value: difficultyLabel.value,
		},
		{
			icon: 'i-lucide-shield',
			label: t('groups.activities.create.summary.min_item_level'),
			value: minimumItemLevelLabel.value,
		},
		{
			icon: 'i-lucide-sparkles',
			label: t('groups.activities.create.summary.beginner_friendly'),
			value: beginnerFriendlyLabel.value,
		},
	];

	if (selectedActivityType.value?.prog_points?.length) {
		rows.push({
			icon: 'i-lucide-flag',
			label: t('groups.activities.create.summary.target_prog_point'),
			value: targetProgPointLabel.value,
		});
	}

	return rows;
});

const scheduleRows = computed<SummaryRow[]>(() => [
	{
		icon: 'i-lucide-clock-3',
		label: t('groups.activities.create.summary.starts_at_st'),
		value: serverStartLabel.value,
	},
	{
		icon: 'i-lucide-map-pin',
		label: t('groups.activities.create.summary.starts_at_local', { timezone: localTimeZone }),
		value: localStartLabel.value,
	},
	{
		icon: 'i-lucide-hourglass',
		label: t('groups.activities.create.summary.duration'),
		value: t('groups.activities.create.summary.duration_value', { count: props.form.duration_hours || 0 }),
	},
]);

const accessRows = computed<SummaryRow[]>(() => [
	{
		icon: 'i-lucide-globe-2',
		label: t('groups.activities.create.summary.visibility'),
		value: visibilityLabel.value,
	},
	{
		icon: 'i-lucide-clipboard-check',
		label: t('groups.activities.create.summary.assignment'),
		value: assignmentLabel.value,
	},
	{
		icon: 'i-lucide-user-plus',
		label: t('groups.activities.create.summary.guest_applications'),
		value: guestApplicationsLabel.value,
	},
]);
</script>

<template>
	<div class="overflow-hidden border border-default bg-elevated/30">
		<div class="relative min-h-52 overflow-hidden border-b border-default">
			<img
				v-if="activityArtworkUrl"
				:src="activityArtworkUrl"
				:alt="activityTypeName"
				class="absolute inset-0 h-full w-full object-cover opacity-35"
				loading="lazy"
				decoding="async"
			>
			<div class="absolute inset-0 bg-gradient-to-b from-neutral-950/35 via-neutral-950/75 to-neutral-950" />

			<div class="relative flex min-h-52 flex-col justify-end gap-5 p-5">
				<div class="flex items-start justify-between gap-4">
					<div class="flex min-w-0 items-start gap-4">
						<div class="flex size-24 shrink-0 items-center justify-center overflow-hidden border border-white/15 bg-white/5 shadow-lg shadow-neutral-950/30">
							<img
								v-if="activityThumbnailUrl"
								:src="activityThumbnailUrl"
								:alt="activityTypeName"
								class="h-full w-full object-cover"
								loading="lazy"
								decoding="async"
							>
							<UIcon v-else name="i-lucide-swords" class="size-8 text-muted" />
						</div>

						<div class="min-w-0 pt-1">
							<div class="flex min-w-0 flex-wrap items-center gap-2">
								<h2 class="min-w-0 break-words [overflow-wrap:anywhere] text-xl font-black text-white">
									{{ displayTitle }}
								</h2>
								<UBadge
									:color="statusMeta.color"
									variant="soft"
									:icon="statusMeta.icon"
									:label="statusLabel"
								/>
							</div>

							<p class="mt-1 break-words [overflow-wrap:anywhere] text-sm font-medium text-neutral-300">
								{{ activityTypeName }}
							</p>

							<div class="mt-3 flex min-w-0 items-center gap-2 text-sm text-neutral-300">
								<div class="flex size-7 shrink-0 items-center justify-center overflow-hidden rounded-full border border-white/15 bg-white/10">
									<img
										v-if="selectedOrganizerCharacter?.avatar_url"
										:src="selectedOrganizerCharacter.avatar_url"
										:alt="organizerName"
										class="h-full w-full object-cover"
										loading="lazy"
										decoding="async"
									>
									<UIcon v-else name="i-lucide-user-round" class="size-4 text-muted" />
								</div>
								<span class="text-muted">{{ t('groups.activities.create.summary.organizer') }}</span>
								<span class="min-w-0 truncate font-semibold text-white">{{ organizerName }}</span>
								<span v-if="organizerUserName" class="min-w-0 truncate text-neutral-400">{{ organizerUserName }}</span>
							</div>
						</div>
					</div>
				</div>

				<div class="flex flex-wrap gap-2">
					<div
						v-for="chip in activityChips"
						:key="chip.icon"
						class="inline-flex items-center gap-2 border border-white/15 bg-white/[0.06] px-3 py-1.5 text-sm font-medium text-neutral-200"
					>
						<UIcon :name="chip.icon" class="size-4 text-primary" />
						<span class="text-neutral-400">{{ chip.label }}</span>
						<span>{{ chip.value }}</span>
					</div>
				</div>
			</div>
		</div>

		<div class="divide-y divide-default">
			<section class="space-y-3 p-5">
				<p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted">
					<UIcon name="i-lucide-list-checks" class="size-4" />
					{{ t('groups.activities.create.summary.activity') }}
				</p>

				<div class="grid gap-3">
					<div
						v-for="row in overviewRows"
						:key="row.label"
						class="grid grid-cols-[minmax(0,1fr)_minmax(7rem,auto)] items-start gap-3 text-sm"
					>
						<div class="flex min-w-0 items-center gap-2 text-muted">
							<UIcon :name="row.icon" class="size-4 shrink-0" />
							<span class="min-w-0 break-words [overflow-wrap:anywhere]">{{ row.label }}</span>
						</div>
						<p class="break-words [overflow-wrap:anywhere] text-right font-semibold text-toned">
							{{ row.value }}
						</p>
					</div>
				</div>
			</section>

			<section class="space-y-3 p-5">
				<p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted">
					<UIcon name="i-lucide-calendar-days" class="size-4" />
					{{ t('groups.activities.create.summary.starts_at') }}
				</p>

				<div class="grid gap-3">
					<div
						v-for="row in scheduleRows"
						:key="row.label"
						class="grid grid-cols-[minmax(0,1fr)_minmax(7rem,auto)] items-start gap-3 text-sm"
					>
						<div class="flex min-w-0 items-center gap-2 text-muted">
							<UIcon :name="row.icon" class="size-4 shrink-0" />
							<span class="min-w-0 break-words [overflow-wrap:anywhere]">{{ row.label }}</span>
						</div>
						<p class="break-words [overflow-wrap:anywhere] text-right font-semibold text-toned">
							{{ row.value }}
						</p>
					</div>
				</div>
			</section>

			<section class="space-y-3 p-5">
				<p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted">
					<UIcon name="i-lucide-lock-keyhole" class="size-4" />
					{{ t('groups.activities.create.sections.access.title') }}
				</p>

				<div class="grid gap-3">
					<div
						v-for="row in accessRows"
						:key="row.label"
						class="grid grid-cols-[minmax(0,1fr)_minmax(7rem,auto)] items-start gap-3 text-sm"
					>
						<div class="flex min-w-0 items-center gap-2 text-muted">
							<UIcon :name="row.icon" class="size-4 shrink-0" />
							<span class="min-w-0 break-words [overflow-wrap:anywhere]">{{ row.label }}</span>
						</div>
						<p class="break-words [overflow-wrap:anywhere] text-right font-semibold text-toned">
							{{ row.value }}
						</p>
					</div>
				</div>
			</section>

			<section class="space-y-3 p-5">
				<p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted">
					<UIcon name="i-lucide-notebook-text" class="size-4" />
					{{ t('groups.activities.create.summary.notes') }}
				</p>
				<p class="break-words [overflow-wrap:anywhere] whitespace-pre-wrap text-sm text-toned">
					{{ form.notes?.trim() || t('groups.activities.create.summary.no_notes') }}
				</p>
			</section>
		</div>
	</div>
</template>
