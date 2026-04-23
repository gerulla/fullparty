<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import {router, usePage} from "@inertiajs/vue3";
import PageHeader from "@/components/PageHeader.vue";
import { localizedValue } from "@/utils/localizedValue";
import {route} from "ziggy-js";

const props = defineProps<{
	group: {
		id: number
		name: string
		slug: string
		current_user_role: string | null
		permissions: {
			can_manage_activities: boolean
		}
	}
	activity: {
		id: number
		activity_type: {
			id: number | null
			slug: string | null
			draft_name: Record<string, string | null | undefined> | null | undefined
		}
		activity_type_version_id: number
		title: string | null
		description: string | null
		status: string
		starts_at: string | null
		organized_by: {
			id: number
			name: string
			avatar_url: string | null
		} | null
		slot_count: number
		application_count: number
		progress_milestone_count: number
	}
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));

const activityTypeName = computed(() => {
	return localizedValue(props.activity.activity_type?.draft_name, locale.value, fallbackLocale.value)
		|| props.activity.activity_type?.slug
		|| t('groups.activities.cards.unknown_type');
});

const activityTitle = computed(() => props.activity.title || activityTypeName.value);

const startsAtLabel = computed(() => {
	if (!props.activity.starts_at) {
		return t('groups.activities.cards.no_time');
	}

	return new Intl.DateTimeFormat(locale.value, {
		weekday: 'long',
		day: 'numeric',
		month: 'long',
		hour: '2-digit',
		minute: '2-digit',
	}).format(new Date(props.activity.starts_at));
});

const goBack = () => {
	router.get(route('groups.dashboard.activities.index', props.group.slug));
};
</script>

<template>
	<div class="w-full">
		<UButton
			:label="t('groups.activities.back')"
			icon="i-lucide-arrow-left"
			variant="ghost"
			color="neutral"
			@click.stop="goBack"
		/>
		<PageHeader
			:title="activityTitle"
			:subtitle="t('groups.activities.management.subtitle', { type: activityTypeName })"
		>
			<UBadge
				size="lg"
				variant="subtle"
				class="min-w-44 justify-center py-2"
				color="primary"
				icon="i-lucide-panel-right-open"
				:label="t(`groups.activities.statuses.${activity.status}`)"
			/>
		</PageHeader>

		<div class="mt-4 grid grid-cols-1 gap-6 xl:grid-cols-[1.25fr_0.75fr]">
			<UCard class="dark:bg-elevated/25">
				<template #header>
					<div class="flex flex-col gap-1">
						<p class="font-semibold text-md">{{ t('groups.activities.management.title') }}</p>
						<p class="text-sm text-muted">{{ t('groups.activities.management.placeholder') }}</p>
					</div>
				</template>

				<div class="rounded-sm border border-dashed border-default bg-muted/10 px-5 py-10 text-sm text-muted">
					{{ t('groups.activities.management.coming_soon') }}
				</div>
			</UCard>

			<UCard class="dark:bg-elevated/25">
				<template #header>
					<div class="flex flex-col gap-1">
						<p class="font-semibold text-md">{{ t('groups.activities.management.summary_title') }}</p>
						<p class="text-sm text-muted">{{ t('groups.activities.management.summary_subtitle') }}</p>
					</div>
				</template>

				<div class="flex flex-col gap-3">
					<div class="rounded-sm border border-default bg-muted/10 px-4 py-4">
						<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.management.type') }}</p>
						<p class="mt-2 font-semibold text-toned">{{ activityTypeName }}</p>
					</div>
					<div class="rounded-sm border border-default bg-muted/10 px-4 py-4">
						<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.management.starts_at') }}</p>
						<p class="mt-2 font-semibold text-toned">{{ startsAtLabel }}</p>
					</div>
					<div class="rounded-sm border border-default bg-muted/10 px-4 py-4">
						<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.management.organizer') }}</p>
						<p class="mt-2 font-semibold text-toned">{{ activity.organized_by?.name || t('groups.activities.cards.no_organizer') }}</p>
					</div>
					<div class="grid grid-cols-3 gap-3">
						<div class="rounded-sm border border-default bg-muted/10 px-4 py-4">
							<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.management.slots') }}</p>
							<p class="mt-2 text-xl font-semibold text-toned">{{ activity.slot_count }}</p>
						</div>
						<div class="rounded-sm border border-default bg-muted/10 px-4 py-4">
							<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.management.applications') }}</p>
							<p class="mt-2 text-xl font-semibold text-toned">{{ activity.application_count }}</p>
						</div>
						<div class="rounded-sm border border-default bg-muted/10 px-4 py-4">
							<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.management.milestones') }}</p>
							<p class="mt-2 text-xl font-semibold text-toned">{{ activity.progress_milestone_count }}</p>
						</div>
					</div>
				</div>
			</UCard>
		</div>
	</div>
</template>
