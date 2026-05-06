<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { route } from "ziggy-js";

type EncounterProgress = {
	name: string
	kills: number
	progress: number
}

type FflogsProgressResponse = {
	title: string
	zone_id: number
	encounters: EncounterProgress[]
	encounter_count: number
	total_kills: number
} | null

const props = withDefaults(defineProps<{
	open: boolean
	groupSlug: string
	activityId: number
	applicationId: number
	characterId: number | null
	characterName: string | null
	world: string | null
	fflogsZoneId: number | null
	shouldFetch?: boolean
}>(), {
	shouldFetch: true,
});

const { t } = useI18n();
const progress = ref<FflogsProgressResponse>(null);
const isLoading = ref(false);
const hasLoaded = ref(false);
const error = ref<string | null>(null);
const isPending = computed(() => (
	props.open
	&& Boolean(props.fflogsZoneId)
	&& !hasLoaded.value
	&& !error.value
));

const bestEncounterProgress = computed(() => {
	if (!progress.value?.encounters?.length) {
		return 0;
	}

	return Math.max(...progress.value.encounters.map((encounter) => encounter.progress));
});

const fetchProgress = async () => {
	if (!props.applicationId || !props.fflogsZoneId || !props.shouldFetch || isLoading.value || hasLoaded.value) {
		return;
	}

	isLoading.value = true;
	error.value = null;

	try {
		const response = await window.axios.get(route('groups.dashboard.activities.application-fflogs-progress', {
			group: props.groupSlug,
			activity: props.activityId,
			application: props.applicationId,
		}));

		progress.value = response.data?.progress ?? null;
		hasLoaded.value = true;
	} catch (requestError) {
		console.error(requestError);
		error.value = t('groups.activities.management.queue.modal.fflogs_error');
	} finally {
		isLoading.value = false;
	}
};

const resetState = () => {
	progress.value = null;
	isLoading.value = false;
	hasLoaded.value = false;
	error.value = null;
};

const getEncounterProgressColor = (progress: number) => {
	let color = 'neutral'
	if(progress > 15 && progress <= 50) color = 'primary';
	if(progress > 50 && progress <= 75) color = 'info';
	if(progress > 75 && progress <= 90) color = 'warning'
	if(progress > 90 && progress < 100) color = 'error'
	if(progress >= 100) color = 'success'
	return color;
}

watch(() => [
	props.applicationId,
	props.fflogsZoneId,
	props.characterId,
	props.characterName,
	props.world,
], () => {
	resetState();
});

watch(() => [props.open, props.shouldFetch, props.applicationId], ([isOpen, shouldFetch]) => {
	if (isOpen && shouldFetch) {
		void fetchProgress();
	}
}, { immediate: true });
</script>

<template>
	<div class="space-y-4 border border-default bg-default/60 p-4">
		<!-- FF Logs progress header: character context and zone-backed summary title -->
		<div class="flex flex-wrap items-start justify-between gap-3">
			<div class="min-w-0">
				<p class="text-[11px] font-medium uppercase tracking-[0.12em] text-muted">
					{{ t('groups.activities.management.queue.modal.fflogs_title') }}
				</p>
			</div>
		</div>

		<!-- FF Logs loading state: only shown the first time the modal requests progress -->
		<div v-if="isPending" class="space-y-3">
			<USkeleton class="h-10 w-full" />
			<USkeleton class="h-16 w-full" />
			<USkeleton class="h-16 w-full" />
		</div>

		<!-- FF Logs error and empty states: keep them compact so they don't dominate the modal -->
		<div v-else-if="error" class="text-sm text-error">
			{{ error }}
		</div>

		<div v-else-if="!fflogsZoneId" class="text-sm text-muted">
			{{ t('groups.activities.management.queue.modal.fflogs_not_configured') }}
		</div>

		<div v-else-if="!progress || progress.encounters.length === 0" class="text-sm text-muted">
			{{ t('groups.activities.management.queue.modal.fflogs_empty') }}
		</div>

		<template v-else>
			<!-- FF Logs encounter list: generic per-encounter rows instead of hardcoded boss cards -->
			<div class="space-y-3">
				<div
					v-for="encounter in progress.encounters"
					:key="encounter.name"
					class="space-y-3"
				>
					<div class="flex items-start justify-between gap-3">
						<div class="min-w-0">
							<p class="font-medium text-toned">
								{{ encounter.name }}
							</p>
						</div>

						<div class="flex shrink-0 items-center gap-2">
							<UBadge
								color="neutral"
								variant="outline"
								:label="`${encounter.kills} kills`"
							/>
							<UBadge
								:color="getEncounterProgressColor(encounter.progress)"
								variant="soft"
								:label="`${encounter.progress}%`"
							/>
						</div>
					</div>
				</div>
			</div>
		</template>
	</div>
</template>
