<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { localizedValue } from "@/utils/localizedValue";
import { usePage } from "@inertiajs/vue3";

type ActivityTypeOption = {
	id: number
	slug: string
	draft_name: Record<string, string | null | undefined> | null | undefined
	current_published_version_id: number | null
	slot_count: number
	prog_points: Array<{
		key: string
		label: Record<string, string | null | undefined> | null | undefined
	}>
}

type OrganizerCharacterOption = {
	id: number
	user_id: number
	name: string | null
	user_name: string | null
	avatar_url: string | null
	world: string | null
}

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
		target_prog_point_key: string | null
		is_public: boolean
		needs_application: boolean
	}
	activityTypes: ActivityTypeOption[]
	organizerCharacters: OrganizerCharacterOption[]
}>();

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

const serverStartLabel = computed(() => {
	if (!props.form.starts_at) {
		return t('groups.activities.create.summary.no_date');
	}

	const serverTimeDate = new Date(`${props.form.starts_at}:00Z`);

	return new Intl.DateTimeFormat(locale.value, {
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

	return new Intl.DateTimeFormat(locale.value, {
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
</script>

<template>
	<UCard class="dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-col gap-1">
				<p class="font-semibold text-md">{{ t('groups.activities.create.summary.title') }}</p>
				<p class="text-sm text-muted">{{ t('groups.activities.create.summary.subtitle') }}</p>
			</div>
		</template>

		<div class="flex flex-col gap-4">
			<div class="rounded-sm border border-default bg-muted/10 px-4 py-4">
				<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.create.summary.activity') }}</p>
				<p class="mt-2 font-semibold text-toned">{{ displayTitle }}</p>
				<p class="mt-1 text-sm text-muted">{{ activityTypeName }}</p>
			</div>

			<div class="grid grid-cols-1 xl:grid-cols-2 gap-3">
				<div class="rounded-sm border border-default bg-muted/10 px-4 py-4">
					<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.create.summary.organizer') }}</p>
					<div class="flex flex-row items-center gap-2">
						<div class="p-1">
							<img :src="selectedOrganizerCharacter?.avatar_url" :alt="selectedOrganizerCharacter?.name || t('groups.activities.create.summary.no_organizer')" class="h-10 w-10 rounded-full"/>
						</div>
						<div class="flex flex-col items-start justify-start">
							<p class="mt-2 font-semibold text-toned">{{ selectedOrganizerCharacter?.name || t('groups.activities.create.summary.no_organizer') }}</p>
							<p v-if="selectedOrganizerCharacter?.user_name" class="mt-1 text-sm text-muted">{{ selectedOrganizerCharacter.user_name }}</p>
						</div>
					</div>
				</div>
				<div class="rounded-sm border border-default bg-muted/10 px-4 py-4">
					<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.create.summary.status') }}</p>
					<p class="mt-2 text-xl font-semibold text-toned">{{ t(`groups.activities.statuses.${form.status}`) }}</p>
				</div>
			</div>


			<div class="grid grid-cols-1 gap-3">
				<div class="rounded-sm border border-default bg-muted/10 px-4 py-4">
					<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.create.summary.starts_at') }}</p>
					<div class="mt-2 flex flex-col gap-2">
						<div>
							<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.create.summary.starts_at_st') }}</p>
							<p class="font-semibold text-toned">{{ serverStartLabel }}</p>
						</div>

						<div>
							<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.create.summary.starts_at_local', { timezone: localTimeZone }) }}</p>
							<p class="font-semibold text-toned">{{ localStartLabel }}</p>
						</div>
					</div>
				</div>
			</div>

			<div class="grid grid-cols-1 gap-3 xl:grid-cols-3">
				<div class="rounded-sm border border-default bg-muted/10 px-4 py-4">
					<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.create.summary.slots') }}</p>
					<p class="mt-2 text-xl font-semibold text-toned">{{ selectedActivityType?.slot_count ?? 0 }}</p>
				</div>
				<div class="rounded-sm border border-default bg-muted/10 px-4 py-4">
					<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.create.summary.duration') }}</p>
					<p class="mt-2 font-semibold text-toned">{{ t('groups.activities.create.summary.duration_value', { count: form.duration_hours || 0 }) }}</p>
				</div>
				<div class="rounded-sm border border-default bg-muted/10 px-4 py-4">
					<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.create.summary.visibility') }}</p>
					<p class="mt-2 font-semibold text-toned">{{ visibilityLabel }}</p>
				</div>
			</div>
			<div class="w-full flex flex-row gap-3 ">
				<div v-if="selectedActivityType?.prog_points?.length" class="w-full rounded-sm border border-default bg-muted/10 px-4 py-4">
					<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.create.summary.target_prog_point') }}</p>
					<p class="mt-2 font-semibold text-toned">
						{{ selectedTargetProgPoint ? (localizedValue(selectedTargetProgPoint.label, locale, fallbackLocale) || selectedTargetProgPoint.key) : t('groups.activities.create.summary.no_target_prog_point') }}
					</p>
				</div>

				<div class="w-full rounded-sm border border-default bg-muted/10 px-4 py-4">
					<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.create.summary.assignment') }}</p>
					<p class="mt-2 font-semibold text-toned">{{ assignmentLabel }}</p>
				</div>
			</div>


			<div class="rounded-sm border border-default bg-muted/10 px-4 py-4">
				<p class="text-xs uppercase tracking-wide text-muted">{{ t('groups.activities.create.summary.notes') }}</p>
				<p class="mt-2 text-sm text-toned whitespace-pre-wrap">
					{{ form.notes?.trim() || t('groups.activities.create.summary.no_notes') }}
				</p>
			</div>
		</div>
	</UCard>
</template>
