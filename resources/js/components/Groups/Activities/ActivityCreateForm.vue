<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { route } from "ziggy-js";
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
	groupSlug: string
	activityTypes: ActivityTypeOption[]
	organizerCharacters: OrganizerCharacterOption[]
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
		errors: Record<string, string | undefined>
		processing: boolean
		post: (url: string, options?: Record<string, unknown>) => void
	}
}>();

const { t, locale } = useI18n();
const page = usePage();
const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? 'en'));

const activityTypeItems = computed(() => props.activityTypes.map((activityType) => ({
	label: localizedValue(activityType.draft_name, locale.value, fallbackLocale.value) || activityType.slug,
	value: activityType.id,
	slot_count: activityType.slot_count,
})));

const organizerCharacterItems = computed(() => props.organizerCharacters.map((character) => ({
	id: character.id,
	user_id: character.user_id,
	label: character.name || `#${character.id}`,
	user_name: character.user_name || t('groups.activities.create.fields.organizer.no_user'),
	world: character.world,
	avatar_url: character.avatar_url,
	avatar: character.avatar_url ? {
		src: character.avatar_url,
		alt: character.name || `#${character.id}`,
	} : undefined,
	description: character.user_name || t('groups.activities.create.fields.organizer.no_user'),
})));

const selectedOrganizerCharacter = computed(() => (
	organizerCharacterItems.value.find((character) => character.id === props.form.organized_by_character_id) ?? null
));

const statusItems = computed(() => [
	{ label: t('groups.activities.statuses.draft'), value: 'draft' },
	{ label: t('groups.activities.statuses.planned'), value: 'planned' },
	{ label: t('groups.activities.statuses.scheduled'), value: 'scheduled' },
	{ label: t('groups.activities.statuses.upcoming'), value: 'upcoming' },
]);

const canSubmit = computed(() => Boolean(props.form.activity_type_id && props.form.status));

const selectedActivityType = computed(() => (
	props.activityTypes.find((activityType) => activityType.id === props.form.activity_type_id) ?? null
));

const progPointItems = computed(() => (selectedActivityType.value?.prog_points ?? []).map((progPoint) => ({
	label: localizedValue(progPoint.label, locale.value, fallbackLocale.value) || progPoint.key,
	value: progPoint.key,
})));

watch(selectedActivityType, (activityType) => {
	const validProgPointKeys = (activityType?.prog_points ?? []).map((progPoint) => progPoint.key);

	if (!validProgPointKeys.length) {
		props.form.target_prog_point_key = null;

		return;
	}

	if (!props.form.target_prog_point_key || !validProgPointKeys.includes(props.form.target_prog_point_key)) {
		props.form.target_prog_point_key = validProgPointKeys[0];
	}
}, { immediate: true });

const updateOrganizerCharacter = (character: (typeof organizerCharacterItems.value)[number] | null) => {
	props.form.organized_by_character_id = character?.id ?? null;
	props.form.organized_by_user_id = character?.user_id ?? null;
};

const buildDefaultStartsAt = () => {
	const now = new Date();
	const target = new Date(now.getTime() + (60 * 60 * 1000));
	target.setSeconds(0, 0);

	if (target.getMinutes() !== 0) {
		target.setHours(target.getHours() + 1, 0, 0, 0);
	}

	const year = target.getUTCFullYear();
	const month = String(target.getUTCMonth() + 1).padStart(2, '0');
	const day = String(target.getUTCDate()).padStart(2, '0');
	const hour = String(target.getUTCHours()).padStart(2, '0');

	return `${year}-${month}-${day}T${hour}:00`;
};

if (!props.form.starts_at) {
	props.form.starts_at = buildDefaultStartsAt();
}

const startDate = ref(props.form.starts_at ? props.form.starts_at.slice(0, 10) : '');
const startHour = ref(props.form.starts_at ? props.form.starts_at.slice(11, 13) : '');
const startMinute = ref(props.form.starts_at ? props.form.starts_at.slice(14, 16) : '00');

watch([startDate, startHour, startMinute], ([date, hour, minute]) => {
	props.form.starts_at = date && hour && minute
		? `${date}T${hour}:${minute}`
		: null;
});

const hourItems = Array.from({ length: 24 }, (_, hour) => ({
	label: hour.toString().padStart(2, '0'),
	value: hour.toString().padStart(2, '0'),
}));
const minuteItems = Array.from({ length: 12 }, (_, index) => {
	const minute = (index * 5).toString().padStart(2, '0');

	return {
		label: minute,
		value: minute,
	};
});

const durationPresets = [2, 3, 6] as const;
const durationItems = computed(() => [
	...durationPresets.map((hours) => ({
		label: t('groups.activities.create.fields.duration.preset', { count: hours }),
		value: String(hours),
	})),
	{
		label: t('groups.activities.create.fields.duration.custom'),
		value: 'custom',
	},
]);

const selectedDurationOption = computed({
	get: () => durationPresets.includes(props.form.duration_hours as 2 | 3 | 6)
		? String(props.form.duration_hours)
		: 'custom',
	set: (value: string) => {
		if (value === 'custom') {
			if (durationPresets.includes(props.form.duration_hours as 2 | 3 | 6)) {
				props.form.duration_hours = 4;
			}

			return;
		}

		props.form.duration_hours = Number(value) || 2;
	},
});

const isCustomDuration = computed(() => selectedDurationOption.value === 'custom');

const submit = () => {
	props.form.post(route('groups.dashboard.activities.store', { group: props.groupSlug }), {
		preserveScroll: true,
	});
};
</script>

<template>
	<UCard class="dark:bg-elevated/25">
		<template #header>
			<div class="flex flex-col gap-1">
				<p class="font-semibold text-md">{{ t('groups.activities.create.form.title') }}</p>
				<p class="text-sm text-muted">{{ t('groups.activities.create.form.subtitle') }}</p>
			</div>
		</template>

		<form class="flex flex-col gap-5" @submit.prevent="submit">
			<div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
				<UFormField
					:label="t('groups.activities.create.fields.activity_type.label')"
					:error="form.errors.activity_type_id"
					required
				>
					<USelect
						v-model="form.activity_type_id"
						size="lg"
						class="w-full"
						:items="activityTypeItems"
						value-key="value"
						:placeholder="t('groups.activities.create.fields.activity_type.placeholder')"
					/>
				</UFormField>

					<UFormField
						:label="t('groups.activities.create.fields.organizer.label')"
						:error="form.errors.organized_by_character_id || form.errors.organized_by_user_id"
					>
						<USelectMenu
							:model-value="selectedOrganizerCharacter"
							class="w-full"
							size="lg"
							:avatar="{
								src: selectedOrganizerCharacter?.avatar_url,
								loading: 'lazy'
							}"
							:items="organizerCharacterItems"
							:placeholder="t('groups.activities.create.fields.organizer.placeholder')"
							@update:model-value="updateOrganizerCharacter"
					/>
					</UFormField>
			</div>

			<div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_320px_180px]">
				<UFormField
					:label="t('groups.activities.create.fields.title.label')"
					:error="form.errors.title"
				>
					<UInput
						v-model="form.title"
						size="lg"
						class="w-full"
						:placeholder="t('groups.activities.create.fields.title.placeholder')"
					/>
				</UFormField>

				<UFormField
					:label="t('groups.activities.create.fields.duration.label')"
					:error="form.errors.duration_hours"
					required
				>
					<div class="flex flex-col gap-3">
						<USelect
							v-model="selectedDurationOption"
							size="lg"
							class="w-full"
							:items="durationItems"
							value-key="value"
							:placeholder="t('groups.activities.create.fields.duration.select_placeholder')"
						/>

						<UInput
							v-if="isCustomDuration"
							:model-value="isCustomDuration ? String(form.duration_hours ?? '') : ''"
							type="number"
							min="1"
							max="24"
							size="lg"
							class="w-full"
							:placeholder="t('groups.activities.create.fields.duration.placeholder')"
							@focus="form.duration_hours = 4"
							@update:model-value="(value) => form.duration_hours = Math.min(24, Math.max(1, Number(value) || 1))"
						/>
					</div>
				</UFormField>

				<UFormField
					:label="t('groups.activities.create.fields.status.label')"
					:error="form.errors.status"
					required
				>
					<USelect
						v-model="form.status"
						size="lg"
						class="w-full"
						:items="statusItems"
						value-key="value"
						:placeholder="t('groups.activities.create.fields.status.placeholder')"
					/>
				</UFormField>
			</div>

			<UFormField
				v-if="progPointItems.length > 0"
				:label="t('groups.activities.create.fields.prog_point.label')"
				:error="form.errors.target_prog_point_key"
			>
				<USelectMenu
					v-model="form.target_prog_point_key"
					size="lg"
					class="w-full"
					:items="progPointItems"
					value-key="value"
					:placeholder="t('groups.activities.create.fields.prog_point.placeholder')"
				/>
			</UFormField>

			<UFormField
				:label="t('groups.activities.create.fields.notes.label')"
				:error="form.errors.notes"
			>
				<UTextarea
					v-model="form.notes"
					size="lg"
					class="w-full"
					:rows="5"
					:placeholder="t('groups.activities.create.fields.notes.placeholder')"
				/>
			</UFormField>

			<div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,420px)_minmax(0,1fr)]">
				<div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
					<UFormField
						:label="t('groups.activities.create.fields.start_date.label')"
						:error="form.errors.starts_at"
					>
						<UInput
							v-model="startDate"
							type="date"
							size="lg"
							class="w-full"
						/>
					</UFormField>

					<UFormField
						:label="t('groups.activities.create.fields.start_time.label')"
						:error="form.errors.starts_at"
						:description="t('groups.activities.create.fields.starts_at.server_time_hint')"
					>
						<div class="grid grid-cols-2 gap-3">
							<USelect
								v-model="startHour"
								size="lg"
								class="w-full"
								:items="hourItems"
								value-key="value"
								:placeholder="t('groups.activities.create.fields.start_time.hour_placeholder')"
								@update:model-value="(value) => startHour = value"
							/>

							<USelect
								:model-value="startMinute"
								size="lg"
								class="w-full"
								:items="minuteItems"
								value-key="value"
								:placeholder="t('groups.activities.create.fields.start_time.minute_placeholder')"
								@update:model-value="(value) => startMinute = value"
							/>
						</div>
					</UFormField>
				</div>

				<div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
					<UFormField
						:label="t('groups.activities.create.fields.is_public.label')"
						:description="t('groups.activities.create.fields.is_public.help')"
						:error="form.errors.is_public"
						orientation="horizontal"
						class="rounded-sm border border-default bg-muted/10 px-4 py-3"
					>
						<USwitch v-model="form.is_public" />
					</UFormField>

					<UFormField
						:label="t('groups.activities.create.fields.needs_application.label')"
						:description="t('groups.activities.create.fields.needs_application.help')"
						:error="form.errors.needs_application"
						orientation="horizontal"
						class="rounded-sm border border-default bg-muted/10 px-4 py-3"
					>
						<USwitch v-model="form.needs_application" />
					</UFormField>
				</div>
			</div>

			<div class="flex items-center gap-3 pt-2">
				<UButton
					type="submit"
					color="neutral"
					icon="i-lucide-plus"
					size="lg"
					:label="t('groups.activities.create.submit')"
					:disabled="!canSubmit"
					:loading="form.processing"
				/>
			</div>
		</form>
	</UCard>
</template>
