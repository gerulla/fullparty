<script setup lang="ts">
import { computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { useI18n } from "vue-i18n";
import { localizedValue } from "@/utils/localizedValue";
import { getActivityStatusMeta } from "@/utils/activityStatusMeta";
import ActivityApplicationForm from "@/components/Groups/Activities/ActivityApplicationForm.vue";

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
		slot_count: number
		assigned_slot_count: number
		pending_application_count: number
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
	applicationSchema: Array<{
		key: string
		label: Record<string, string | null | undefined>
		type: string
		source: string | null
		required?: boolean
		help_text?: Record<string, string | null | undefined> | null
		options: Array<{
			key: string
			label: Record<string, string | null | undefined>
			meta?: {
				icon_url?: string | null
				role?: string | null
				shorthand?: string | null
			} | null
		}>
	}>
	application: {
		id: number
		selected_character_id: number | null
		status: string
		notes: string | null
		submitted_at: string | null
		answers: Record<string, unknown>
	} | null
	characters: Array<{
		id: number
		name: string
		avatar_url: string | null
		world: string | null
	}>
	permissions: {
		can_apply: boolean
		can_manage: boolean
		has_existing_application: boolean
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
const statusMeta = computed(() => getActivityStatusMeta(props.activity.status));
const dateLabel = computed(() => {
	if (!props.activity.starts_at) {
		return t('groups.activities.cards.no_time');
	}

	return new Intl.DateTimeFormat(locale.value, {
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	}).format(new Date(props.activity.starts_at));
});

const startsAtLabel = computed(() => {
	if (!props.activity.starts_at) {
		return t('groups.activities.cards.no_time');
	}

	return new Intl.DateTimeFormat(locale.value, {
		hour: '2-digit',
		minute: '2-digit',
		timeZone: 'UTC',
		timeZoneName: 'short',
	}).format(new Date(props.activity.starts_at));
});

const timeDurationLabel = computed(() => {
	if (!props.activity.starts_at) {
		return t('groups.activities.cards.no_time');
	}

	const duration = props.activity.duration_hours
		? ` (${t('groups.activities.management.overview.duration', { count: props.activity.duration_hours })})`
		: '';

	return `${startsAtLabel.value}${duration}`;
});

const organizerLabel = computed(() => {
	return props.activity.organized_by_character?.name
		|| props.activity.organized_by?.name
		|| t('groups.activities.cards.no_organizer');
});

const applicationPageSubtitle = computed(() => {
	return props.permissions.has_existing_application
		? t('groups.activities.application.subtitle_existing', {
			group: props.group.name,
			type: activityTypeName.value,
		})
		: t('groups.activities.application.subtitle', {
			group: props.group.name,
			type: activityTypeName.value,
		});
});

const currentSecretKey = computed(() => {
	if (typeof window === 'undefined') {
		return undefined;
	}

	const segments = window.location.pathname.split('/').filter(Boolean);
	const lastSegment = segments.at(-1);
	const previousSegment = segments.at(-2);

	if (previousSegment === 'application' && lastSegment && /^[A-Za-z0-9]{40}$/.test(lastSegment)) {
		return lastSegment;
	}

	return undefined;
});

const goBack = () => {
	router.get(route('groups.activities.overview', {
		group: props.group.slug,
		activity: props.activity.id,
		secretKey: currentSecretKey.value,
	}));
};
</script>

<template>
	<div class="w-full">
		<UButton
			:label="t('groups.activities.application.back')"
			icon="i-lucide-arrow-left"
			variant="ghost"
			color="neutral"
			@click="goBack"
		/>

		<div class="flex flex-col gap-6 mt-2">
			<UCard class="dark:bg-elevated/25">
				<template #header>
					<div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
						<div class="flex flex-col gap-1">
							<p class="font-semibold text-muted text-md">{{ t('groups.activities.application.run_info_title') }}</p>
							<h1 class="font-semibold text-2xl text-toned">{{ activityTitle }}</h1>
							<p class="text-sm text-muted">{{ activity.description ?? applicationPageSubtitle }}</p>
						</div>

						<div class="h-full flex flex-col items-start justify-center gap-4 xl:items-end">
							<div class="flex flrex-row gap-2">
								<UBadge
									size="md"
									variant="subtle"
									:color="statusMeta.color"
									:icon="statusMeta.icon"
									:label="t(`groups.activities.statuses.${activity.status}`)"
								/>
								<UBadge
									color="neutral"
									variant="soft"
									size="md"
									:label="activityTypeName"
								/>
							</div>
							<div class="flex flex-row gap-2 text-sm text-muted xl:items-end">
								<div class="inline-flex items-center gap-2">
									<UIcon name="i-lucide-calendar-days" class="size-4" />
									<span>{{ dateLabel }}</span>
								</div>

								<div class="inline-flex items-center gap-2">
									<UIcon name="i-lucide-clock-3" class="size-4" />
									<span>{{ timeDurationLabel }}</span>
								</div>
							</div>
						</div>
					</div>
				</template>

				<div class="flex flex-col gap-4">
					<div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
						<div class="inline-flex items-center gap-2">
							<span class="text-muted">{{ t('groups.activities.management.overview.group') }}:</span>
							<span class="font-medium text-toned">{{ group.name }}</span>
						</div>

						<div class="hidden h-4 w-px bg-default md:block"></div>

						<div class="inline-flex items-center gap-2">
							<span class="text-muted">{{ t('groups.activities.management.organizer') }}:</span>
							<UUser
								v-if="activity.organized_by_character"
								:name="activity.organized_by_character.name"
								:avatar="activity.organized_by_character.avatar_url ? { src: activity.organized_by_character.avatar_url, alt: activity.organized_by_character.name } : undefined"
								size="sm"
							/>
							<span v-else class="font-medium text-toned">{{ organizerLabel }}</span>
						</div>
					</div>

					<div
						v-if="activity.description || activity.notes"
						class="border-t border-default pt-4"
					>
						<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.application.summary_notes') }}</p>
						<div class="mt-2 flex flex-col gap-3 text-sm text-toned">
							<p v-if="activity.description" class="whitespace-pre-wrap">{{ activity.description }}</p>
							<p v-if="activity.notes" class="whitespace-pre-wrap text-muted">{{ activity.notes }}</p>
						</div>
					</div>
				</div>
			</UCard>

			<ActivityApplicationForm
				:group-slug="group.slug"
				:activity-id="activity.id"
				:secret-key="currentSecretKey"
				:characters="characters"
				:questions="applicationSchema"
				:application="application"
				:can-apply="permissions.can_apply"
				@cancel="goBack"
			/>
		</div>
	</div>
</template>
