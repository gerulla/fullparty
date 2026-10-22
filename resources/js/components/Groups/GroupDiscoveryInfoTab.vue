<script setup lang="ts">
import type { GroupDiscoveryDetailRecord } from "@/Types/Groups";
import GroupDiscoveryBadge from "@/components/Groups/GroupDiscoveryBadge.vue";
import { formatRelativeTime } from "@/utils/formatRelativeTime";
import { computed } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	group: GroupDiscoveryDetailRecord
}>();

const { locale, t } = useI18n();

const experienceBadge = computed(() => props.group.badge_meta.experience_expectation ?? null);
const voiceBadge = computed(() => props.group.badge_meta.voice_expectation ?? null);
const focusBadges = computed(() => props.group.badge_meta.primary_focuses ?? []);
const tagBadges = computed(() => props.group.badge_meta.tags ?? []);

const languagesText = computed(() => (props.group.preferred_languages ?? [])
	.map((language) => resolveLanguageLabel(language))
	.join(", "));

const activeHoursText = computed(() => {
	if (!props.group.active_start_time || !props.group.active_end_time) {
		return t("groups.common.states.not_shared");
	}

	return `${formatTime(props.group.active_start_time)} - ${formatTime(props.group.active_end_time)}`;
});

const activityDayValues = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"] as const;

const weeklyActivityRows = computed(() => activityDayValues.map((day) => {
	const isActive = (props.group.active_days ?? []).includes(day);

	return {
		value: day,
		label: t(`groups.common.active_days.${day}`),
		isActive,
		hours: isActive ? activeHoursText.value : "",
	};
}));

const lastActivityText = computed(() => formatRelativeTime(
	props.group.stats.last_activity_at ?? null,
	locale.value,
	t("notifications.ui.just_now"),
	t("groups.common.states.no_recent_activity"),
));

const descriptionText = computed(() => props.group.description || t("groups.common.states.no_description_short"));

function resolveLanguageLabel(value: string) {
	const languageLabels: Record<string, string> = {
		en: "English",
		de: "Deutsch",
		fr: "Français",
		ja: "日本語",
	};

	return languageLabels[value] ?? value.toUpperCase();
}

function formatTime(value: string) {
	return value.slice(0, 5);
}
</script>

<template>
	<div class="space-y-5">
		<section class="space-y-2">
			<p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-dimmed">
				{{ t('groups.common.labels.about_us') }}
			</p>
			<p class="text-sm leading-7 text-toned break-words [overflow-wrap:anywhere]">
				{{ descriptionText }}
			</p>
		</section>

		<div class="h-px bg-default/80 dark:bg-elevation-6" />

		<section class="flex flex-wrap items-start justify-between gap-4">
			<div class="min-w-0 flex-1">
				<p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-dimmed">
					{{ t('groups.common.labels.owner') }}
				</p>
				<div class="mt-2">
					<UUser
						:name="group.owner.name ?? '—'"
						size="lg"
						:avatar="group.owner.avatar_url ? { src: group.owner.avatar_url } : undefined"
					/>
				</div>
			</div>

			<div class="min-w-32 shrink-0 text-left sm:text-right">
				<p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-dimmed">
					{{ t('groups.common.labels.members') }}
				</p>
				<p class="mt-2 text-2xl font-semibold text-highlighted">
					{{ group.stats.member_count }}
				</p>
			</div>
		</section>

		<div class="h-px bg-default/80 dark:bg-elevation-6" />

		<section class="flex flex-col gap-4 sm:flex-row">
			<div class="flex items-start gap-3 sm:flex-1">
				<div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg border border-default bg-muted/40 text-dimmed dark:border-elevation-7 dark:bg-elevation-5">
					<UIcon name="i-lucide-activity" class="size-4" />
				</div>
				<div class="min-w-0">
					<p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-dimmed">
						{{ t('groups.common.labels.recent_activity') }}
					</p>
					<p class="mt-1 text-sm text-toned">
						{{ lastActivityText }}
					</p>
				</div>
			</div>

			<div class="flex items-start gap-3 sm:flex-1">
				<div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg border border-default bg-muted/40 text-dimmed dark:border-elevation-7 dark:bg-elevation-5">
					<UIcon name="i-lucide-link-2" class="size-4" />
				</div>
				<div class="min-w-0">
					<p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-dimmed">
						{{ t('groups.common.labels.discord') }}
					</p>
					<a
						v-if="group.discord_invite_url"
						:href="group.discord_invite_url"
						target="_blank"
						rel="noreferrer"
						class="mt-1 inline-flex max-w-full items-center gap-2 text-sm text-primary hover:text-primary/80 dark:text-brand-400 dark:hover:text-brand-300"
					>
						<UIcon name="i-lucide-external-link" class="size-4 shrink-0" />
						<span class="truncate">{{ group.discord_invite_url }}</span>
					</a>
					<p v-else class="mt-1 text-sm text-muted">
						{{ t('groups.common.states.not_shared') }}
					</p>
				</div>
			</div>

			<div class="flex items-start gap-3 sm:flex-1">
				<div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg border border-default bg-muted/40 text-dimmed dark:border-elevation-7 dark:bg-elevation-5">
					<UIcon name="i-lucide-languages" class="size-4" />
				</div>
				<div class="min-w-0">
					<p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-dimmed">
						{{ t('groups.common.labels.languages') }}
					</p>
					<p class="mt-1 text-sm text-toned">
						{{ languagesText || t('groups.common.states.not_shared') }}
					</p>
				</div>
			</div>
		</section>

		<div class="h-px bg-default/80 dark:bg-elevation-6" />

		<section class="space-y-3">
			<p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-dimmed">
				{{ t('groups.common.labels.metadata_tags') }}
			</p>
			<div class="flex flex-wrap gap-2">
				<GroupDiscoveryBadge
					v-if="experienceBadge"
					:color="experienceBadge.color"
					:label="t(`groups.index.create_modal.fields.experience_expectation.options.${experienceBadge.value}`)"
				/>
				<GroupDiscoveryBadge
					v-if="voiceBadge"
					:color="voiceBadge.color"
					:label="t(`groups.common.voice_expectations.${voiceBadge.value}`)"
				/>
				<GroupDiscoveryBadge
					v-for="focus in focusBadges"
					:key="focus.value"
					:color="focus.color"
					:label="t(`groups.index.create_modal.fields.primary_focuses.options.${focus.value}`)"
				/>
			</div>
		</section>

		<div class="h-px bg-default/80 dark:bg-elevation-6" />

		<section class="space-y-3">
			<p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-dimmed">
				{{ t('groups.common.labels.extra_tags') }}
			</p>
			<div v-if="tagBadges.length > 0" class="flex flex-wrap gap-2">
				<GroupDiscoveryBadge
					v-for="tag in tagBadges"
					:key="tag.value"
					:color="tag.color"
					:label="tag.label"
				/>
			</div>
			<p v-else class="text-sm text-muted">
				{{ t('groups.common.states.not_shared') }}
			</p>
		</section>

		<div class="h-px bg-default/80 dark:bg-elevation-6" />

		<section class="space-y-3">
			<p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-dimmed">
				{{ t('groups.common.labels.activity_window') }}
			</p>
			<div class="overflow-hidden rounded-xl border border-default bg-muted/20 dark:border-elevation-7 dark:bg-elevation-3">
				<div class="border-b border-default/80 px-4 py-3 dark:border-elevation-7 dark:bg-elevation-4">
					<p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-dimmed">
						{{ t('groups.common.labels.timezone') }}
					</p>
					<p class="mt-1 text-sm text-toned break-words">
						{{ group.active_timezone || t('groups.common.states.not_shared') }}
					</p>
				</div>

				<div
					v-for="row in weeklyActivityRows"
					:key="row.value"
					class="flex items-center justify-between gap-4 border-t border-default/70 px-4 py-3 first:border-t-0 dark:border-elevation-7"
					:class="row.isActive ? 'bg-elevated/55 dark:bg-elevation-5' : 'bg-transparent'"
				>
					<div class="flex items-center gap-3">
						<div
							class="size-2.5 shrink-0 rounded-full"
							:class="row.isActive ? 'bg-primary dark:bg-brand-400' : 'bg-neutral-700 dark:bg-elevation-9'"
						/>
						<p class="text-sm font-medium" :class="row.isActive ? 'text-highlighted' : 'text-muted'">
							{{ row.label }}
						</p>
					</div>

					<p
						v-if="row.hours"
						class="text-sm text-right"
						:class="row.isActive ? 'text-toned' : 'text-dimmed'"
					>
						{{ row.hours }}
					</p>
				</div>
			</div>
		</section>
	</div>
</template>
