<script setup lang="ts">
import { computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import PageHeader from "@/components/PageHeader.vue";
import { localizedValue } from "@/utils/localizedValue";
import { getActivityStatusMeta } from "@/utils/activityStatusMeta";

const props = defineProps<{
	group: {
		id: number
		name: string
		slug: string
		is_public: boolean
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
		notes: string | null
		status: string
		starts_at: string | null
		duration_hours: number | null
		target_prog_point_key: string | null
		needs_application: boolean
		organized_by: {
			id: number
			name: string
			avatar_url: string | null
		} | null
		organized_by_character: {
			id: number
			user_id: number
			name: string
			avatar_url: string | null
		} | null
	}
	permissions: {
		can_apply: boolean
		can_manage: boolean
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

const organizerLabel = computed(() => props.activity.organized_by_character?.name || props.activity.organized_by?.name || t('groups.activities.cards.no_organizer'));

const statusMeta = computed(() => getActivityStatusMeta(props.activity.status));

const goToManagementPage = () => {
	router.get(route('groups.dashboard.activities.show', {
		group: props.group.slug,
		activity: props.activity.id,
	}));
};
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="activityTitle"
			:subtitle="t('groups.activities.overview.subtitle', { group: group.name, type: activityTypeName })"
		>
			<div class="flex items-center justify-end gap-2">
				<UBadge
					size="md"
					variant="subtle"
					class="min-w-44 justify-center py-2"
					:color="statusMeta.color"
					:icon="statusMeta.icon"
					:label="t(`groups.activities.statuses.${activity.status}`)"
				/>
				<UButton
					v-if="permissions.can_manage"
					color="neutral"
					variant="outline"
					icon="i-lucide-settings-2"
					:label="t('groups.activities.overview.go_to_management')"
					@click="goToManagementPage"
				/>
			</div>
		</PageHeader>

		<div class="mt-4 grid grid-cols-1 gap-6 xl:grid-cols-[1.1fr_0.9fr]">
			<UCard class="dark:bg-elevated/25">
				<template #header>
					<div class="flex flex-col gap-1">
						<p class="font-semibold text-md">{{ t('groups.activities.overview.title') }}</p>
						<p class="text-sm text-muted">{{ t('groups.activities.overview.description') }}</p>
					</div>
				</template>

				<div class="flex flex-col gap-4 text-sm">
					<div v-if="activity.description" class="rounded-sm border border-default bg-muted/10 px-4 py-4 text-toned">
						{{ activity.description }}
					</div>

					<div v-if="activity.notes" class="rounded-sm border border-default bg-muted/10 px-4 py-4 text-toned">
						{{ activity.notes }}
					</div>

					<UAlert
						color="neutral"
						variant="soft"
						icon="i-lucide-file-pen-line"
						:title="t('groups.activities.overview.applications_title')"
						:description="permissions.can_apply
							? t('groups.activities.overview.applications_ready')
							: t('groups.activities.overview.applications_login')"
					/>
				</div>
			</UCard>

			<UCard class="dark:bg-elevated/25">
				<template #header>
					<div class="flex flex-col gap-1">
						<p class="font-semibold text-md">{{ t('groups.activities.overview.summary_title') }}</p>
						<p class="text-sm text-muted">{{ t('groups.activities.overview.summary_subtitle') }}</p>
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
						<p class="mt-2 font-semibold text-toned">{{ organizerLabel }}</p>
					</div>

					<div class="grid grid-cols-2 gap-3">
						<div class="rounded-sm border border-default bg-muted/10 px-4 py-4">
							<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.create.summary.duration') }}</p>
							<p class="mt-2 text-xl font-semibold text-toned">{{ activity.duration_hours ?? '-' }}<span v-if="activity.duration_hours">h</span></p>
						</div>

						<div class="rounded-sm border border-default bg-muted/10 px-4 py-4">
							<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.create.summary.target_prog_point') }}</p>
							<p class="mt-2 font-semibold text-toned">{{ activity.target_prog_point_key || t('groups.activities.create.summary.no_target_prog_point') }}</p>
						</div>
					</div>
				</div>
			</UCard>
		</div>
	</div>
</template>
