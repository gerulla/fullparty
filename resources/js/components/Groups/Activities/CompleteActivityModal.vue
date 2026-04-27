<script setup lang="ts">
import axios from "axios";
import { computed, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { route } from "ziggy-js";
import { localizedValue } from "@/utils/localizedValue";

type LocalizedText = Record<string, string | null | undefined> | null | undefined;

type ProgressPoint = {
	key: string
	label: LocalizedText
};

type ProgressMilestone = {
	id: number
	milestone_key: string
	milestone_label: LocalizedText
	kills: number
	best_progress_percent: number | null
};

type PreviewMilestone = {
	milestone_key: string
	kills: number
	best_progress_percent: number | null
};

const props = defineProps<{
	open: boolean
	groupSlug: string
	activityId: number
	isSubmitting: boolean
	canUseFflogsCompletion: boolean
	progPoints: ProgressPoint[]
	progressMilestones: ProgressMilestone[]
	errors?: Record<string, string[] | undefined>
}>();

const emit = defineEmits<{
	'update:open': [value: boolean]
	confirm: [payload: {
		progress_entry_mode: 'manual' | 'fflogs' | null
		progress_link_url: string | null
		progress_notes: string | null
		furthest_progress_key: string | null
		milestones: Array<{
			milestone_key: string
			kills: number
			best_progress_percent: number | null
		}>
	}]
}>();

const { t, locale } = useI18n();

const step = ref(1);
const isFetchingFflogsPreview = ref(false);
const fflogsPreviewError = ref<string | null>(null);
const fflogsPreviewMeta = ref<{ report_code?: string | null, report_title?: string | null } | null>(null);

const state = reactive({
	progressEntryMode: 'manual' as 'manual' | 'fflogs',
	progressLinkUrl: '',
	progressNotes: '',
	furthestProgressKey: '',
	milestones: {} as Record<string, { kills: string, best_progress_percent: string }>,
});

const hasProgressMilestones = computed(() => props.progressMilestones.length > 0);
const maxSteps = computed(() => {
	if (!hasProgressMilestones.value) {
		return 2;
	}

	return state.progressEntryMode === 'fflogs' ? 5 : 4;
});

const progPointItems = computed(() => props.progPoints.map((progPoint) => ({
	label: localizedValue(progPoint.label, locale.value) || progPoint.key,
	value: progPoint.key,
})));

const progressSummary = computed(() => props.progressMilestones.map((milestone) => ({
	key: milestone.milestone_key,
	label: localizedValue(milestone.milestone_label, locale.value) || milestone.milestone_key,
	kills: Number(state.milestones[milestone.milestone_key]?.kills || 0),
	bestProgressPercent: state.milestones[milestone.milestone_key]?.best_progress_percent === ''
		? null
		: Number(state.milestones[milestone.milestone_key]?.best_progress_percent ?? 0),
})));

const stepLabel = computed(() => {
	if (!hasProgressMilestones.value) {
		return step.value === 1
			? t('groups.activities.management.complete_activity_modal.steps.warning')
			: t('groups.activities.management.complete_activity_modal.steps.confirm');
	}

	if (step.value === 1) {
		return t('groups.activities.management.complete_activity_modal.steps.warning');
	}

	if (step.value === 2) {
		return t('groups.activities.management.complete_activity_modal.steps.method');
	}

	if (step.value === 3) {
		return state.progressEntryMode === 'fflogs'
			? t('groups.activities.management.complete_activity_modal.steps.fflogs_link')
			: t('groups.activities.management.complete_activity_modal.steps.manual');
	}

	if (step.value === 4 && state.progressEntryMode === 'fflogs') {
		return t('groups.activities.management.complete_activity_modal.steps.fflogs_review');
	}

	return t('groups.activities.management.complete_activity_modal.steps.confirm');
});

const canGoNext = computed(() => {
	if (!hasProgressMilestones.value) {
		return step.value < maxSteps.value;
	}

	if (step.value === 1 || step.value === 2) {
		return true;
	}

	if (step.value === 3 && state.progressEntryMode === 'fflogs') {
		return state.progressLinkUrl.trim().length > 0 && !isFetchingFflogsPreview.value;
	}

	return step.value < maxSteps.value;
});

const canConfirm = computed(() => step.value === maxSteps.value);

const resetMilestones = () => {
	state.milestones = Object.fromEntries(props.progressMilestones.map((milestone) => [
		milestone.milestone_key,
		{
			kills: milestone.kills > 0 ? String(milestone.kills) : '',
			best_progress_percent: milestone.best_progress_percent !== null ? String(milestone.best_progress_percent) : '',
		},
	]));
};

const resetState = () => {
	step.value = 1;
	state.progressEntryMode = props.canUseFflogsCompletion ? 'fflogs' : 'manual';
	state.progressLinkUrl = '';
	state.progressNotes = '';
	state.furthestProgressKey = '';
	resetMilestones();
	fflogsPreviewError.value = null;
	fflogsPreviewMeta.value = null;
};

watch(() => props.open, (isOpen) => {
	if (isOpen) {
		resetState();
	}
}, { immediate: true });

const close = () => {
	emit('update:open', false);
};

const errorFor = (key: string) => props.errors?.[key]?.[0] ?? null;

const applyPreviewMilestones = (milestones: PreviewMilestone[]) => {
	for (const milestone of milestones) {
		state.milestones[milestone.milestone_key] = {
			kills: milestone.kills > 0 ? String(milestone.kills) : '',
			best_progress_percent: milestone.best_progress_percent !== null ? String(milestone.best_progress_percent) : '',
		};
	}
};

const fetchFflogsPreview = async () => {
	if (!state.progressLinkUrl.trim() || isFetchingFflogsPreview.value) {
		return false;
	}

	isFetchingFflogsPreview.value = true;
	fflogsPreviewError.value = null;
	fflogsPreviewMeta.value = null;

	try {
		const response = await axios.post(route('groups.dashboard.activities.fflogs-completion-preview', {
			group: props.groupSlug,
			activity: props.activityId,
		}), {
			progress_link_url: state.progressLinkUrl.trim(),
		});

		const preview = response.data?.preview ?? null;

		if (!preview) {
			fflogsPreviewError.value = t('groups.activities.management.complete_activity_modal.fflogs_preview_error');
			return false;
		}

		applyPreviewMilestones(preview.milestones ?? []);

		if (preview.suggested_furthest_progress_key) {
			state.furthestProgressKey = preview.suggested_furthest_progress_key;
		}

		fflogsPreviewMeta.value = {
			report_code: preview.report_code ?? null,
			report_title: preview.report_title ?? null,
		};

		return true;
	} catch (error: any) {
		console.error(error);
		fflogsPreviewError.value = error?.response?.data?.message ?? t('groups.activities.management.complete_activity_modal.fflogs_preview_error');
		return false;
	} finally {
		isFetchingFflogsPreview.value = false;
	}
};

const next = () => {
	if (!canGoNext.value) {
		return;
	}

	if (hasProgressMilestones.value && step.value === 3 && state.progressEntryMode === 'fflogs') {
		void (async () => {
			const fetched = await fetchFflogsPreview();

			if (fetched) {
				step.value = 4;
			}
		})();

		return;
	}

	step.value = Math.min(maxSteps.value, step.value + 1);
};

const back = () => {
	step.value = Math.max(1, step.value - 1);
};

const submit = () => {
	emit('confirm', {
		progress_entry_mode: hasProgressMilestones.value ? state.progressEntryMode : null,
		progress_link_url: hasProgressMilestones.value && state.progressEntryMode === 'fflogs' && state.progressLinkUrl.trim().length > 0
			? state.progressLinkUrl.trim()
			: null,
		progress_notes: state.progressNotes.trim().length > 0 ? state.progressNotes.trim() : null,
		furthest_progress_key: state.furthestProgressKey || null,
		milestones: props.progressMilestones.map((milestone) => ({
			milestone_key: milestone.milestone_key,
			kills: Number(state.milestones[milestone.milestone_key]?.kills || 0),
			best_progress_percent: state.milestones[milestone.milestone_key]?.best_progress_percent === ''
				? null
				: Number(state.milestones[milestone.milestone_key]?.best_progress_percent ?? 0),
		})),
	});
};
</script>

<template>
	<UModal
		:open="open"
		:title="t('groups.activities.management.complete_activity_modal.title')"
		:description="t('groups.activities.management.complete_activity_modal.description')"
		@update:open="emit('update:open', $event)"
	>
		<template #header>
			<div class="w-full flex flex-col items-stretch gap-2">
				<div class="flex items-center justify-between gap-3">
					<p class="text-xs uppercase text-muted">
						{{ t('groups.activities.management.complete_activity_modal.progress', { current: step, total: maxSteps }) }}
					</p>
					<p class="text-xs uppercase text-muted">
						{{ stepLabel }}
					</p>
				</div>
				<UProgress v-model="step" :max="maxSteps" />
			</div>
		</template>

		<template #body>
			<div class="flex flex-col gap-5">
				<div v-if="step === 1" class="flex flex-col gap-4">
					<UAlert
						color="warning"
						variant="soft"
						icon="i-lucide-triangle-alert"
						:title="t('groups.activities.management.complete_activity_modal.warning_title')"
					/>

					<div class="rounded-sm border border-default bg-muted/30 px-4 py-4 text-sm text-muted">
						<div class="space-y-3 leading-6">
							<p>{{ t('groups.activities.management.complete_activity_modal.warning_body') }}</p>
							<p>{{ t('groups.activities.management.complete_activity_modal.warning_points.overview_only') }}</p>
							<p v-if="hasProgressMilestones">{{ t('groups.activities.management.complete_activity_modal.warning_points.progress') }}</p>
						</div>
					</div>
				</div>

				<div v-else-if="step === 2 && hasProgressMilestones" class="flex flex-col gap-4">
					<p class="text-sm text-muted">
						{{ t('groups.activities.management.complete_activity_modal.method_help') }}
					</p>

					<div class="grid gap-3 md:grid-cols-2">
						<button
							v-if="canUseFflogsCompletion"
							type="button"
							class="flex flex-col items-start gap-2 rounded-sm border p-4 text-left transition"
							:class="state.progressEntryMode === 'fflogs'
								? 'border-primary bg-primary/8'
								: 'border-default bg-background hover:border-primary/30'"
							@click="state.progressEntryMode = 'fflogs'"
						>
							<div class="flex items-center gap-2">
								<UIcon name="i-lucide-scroll-text" class="size-4" />
								<p class="font-medium text-toned">
									{{ t('groups.activities.management.complete_activity_modal.methods.fflogs') }}
								</p>
							</div>
							<p class="text-sm text-muted">
								{{ t('groups.activities.management.complete_activity_modal.methods.fflogs_help') }}
							</p>
						</button>

						<button
							type="button"
							class="flex flex-col items-start gap-2 rounded-sm border p-4 text-left transition"
							:class="state.progressEntryMode === 'manual'
								? 'border-primary bg-primary/8'
								: 'border-default bg-background hover:border-primary/30'"
							@click="state.progressEntryMode = 'manual'"
						>
							<div class="flex items-center gap-2">
								<UIcon name="i-lucide-pencil-ruler" class="size-4" />
								<p class="font-medium text-toned">
									{{ t('groups.activities.management.complete_activity_modal.methods.manual') }}
								</p>
							</div>
							<p class="text-sm text-muted">
								{{ t('groups.activities.management.complete_activity_modal.methods.manual_help') }}
							</p>
						</button>
					</div>
				</div>

				<div
					v-else-if="step === 3 && hasProgressMilestones && state.progressEntryMode === 'fflogs'"
					class="flex flex-col gap-5"
				>
					<div class="rounded-sm border border-default bg-muted/30 px-4 py-4 text-sm text-muted">
						<p>{{ t('groups.activities.management.complete_activity_modal.fflogs_step_help') }}</p>
						<p class="mt-2">{{ t('groups.activities.management.complete_activity_modal.fflogs_step_hint') }}</p>
					</div>

					<UFormField
						:label="t('groups.activities.management.complete_activity_modal.fflogs_link')"
						:error="fflogsPreviewError || errorFor('progress_link_url')"
					>
						<UInput
							v-model="state.progressLinkUrl"
							class="w-full"
							:placeholder="t('groups.activities.management.complete_activity_modal.fflogs_link_placeholder')"
						/>
					</UFormField>
				</div>

				<div
					v-else-if="hasProgressMilestones && ((step === 3 && state.progressEntryMode === 'manual') || (step === 4 && state.progressEntryMode === 'fflogs'))"
					class="flex flex-col gap-5"
				>
					<UAlert
						v-if="state.progressEntryMode === 'fflogs' && fflogsPreviewMeta"
						color="success"
						variant="soft"
						icon="i-lucide-badge-check"
						:title="t('groups.activities.management.complete_activity_modal.fflogs_preview_success')"
						:description="fflogsPreviewMeta.report_title || fflogsPreviewMeta.report_code || ''"
					/>

					<div
						v-else-if="state.progressEntryMode === 'manual'"
						class="rounded-sm border border-default bg-muted/30 px-4 py-4 text-sm text-muted"
					>
						{{ t('groups.activities.management.complete_activity_modal.manual_step_help') }}
					</div>

					<UFormField
						v-if="progPointItems.length > 0"
						:label="t('groups.activities.management.complete_activity_modal.furthest_progress')"
						:error="errorFor('furthest_progress_key')"
					>
						<USelect
							v-model="state.furthestProgressKey"
							class="w-full"
							value-key="value"
							:items="progPointItems"
							:placeholder="t('groups.activities.management.complete_activity_modal.furthest_progress_placeholder')"
						/>
					</UFormField>

					<div class="flex flex-col gap-3">
						<div class="flex items-center justify-between gap-3">
							<p class="text-sm font-medium text-toned">
								{{ t('groups.activities.management.complete_activity_modal.milestones') }}
							</p>
							<p class="text-xs text-muted">
								{{ state.progressEntryMode === 'fflogs'
									? t('groups.activities.management.complete_activity_modal.milestones_autofilled_hint')
									: t('groups.activities.management.complete_activity_modal.milestones_hint') }}
							</p>
						</div>

						<div class="flex flex-col gap-3">
							<div
								v-for="milestone in progressMilestones"
								:key="milestone.id"
								class="grid gap-3 rounded-sm border border-default bg-background/60 px-4 py-4 md:grid-cols-[minmax(0,1fr)_9rem_11rem]"
							>
								<div class="min-w-0">
									<p class="font-medium text-toned">
										{{ localizedValue(milestone.milestone_label, locale) || milestone.milestone_key }}
									</p>
									<p class="mt-1 text-xs text-muted">
										{{ milestone.milestone_key }}
									</p>
								</div>

								<UFormField :label="t('groups.activities.management.complete_activity_modal.kills')">
									<UInput
										v-model="state.milestones[milestone.milestone_key].kills"
										type="number"
										min="0"
										class="w-full"
									/>
								</UFormField>

								<UFormField :label="t('groups.activities.management.complete_activity_modal.best_progress_percent')">
									<UInput
										v-model="state.milestones[milestone.milestone_key].best_progress_percent"
										type="number"
										min="0"
										max="100"
										step="0.01"
										class="w-full"
									/>
								</UFormField>
							</div>
						</div>
					</div>

					<UFormField
						:label="t('groups.activities.management.complete_activity_modal.notes')"
						:error="errorFor('progress_notes')"
					>
						<UTextarea
							v-model="state.progressNotes"
							:rows="3"
							class="w-full"
							:placeholder="t('groups.activities.management.complete_activity_modal.notes_placeholder')"
						/>
					</UFormField>
				</div>

				<div v-else class="flex flex-col gap-4">
					<UAlert
						color="neutral"
						variant="soft"
						icon="i-lucide-list-checks"
						:title="t('groups.activities.management.complete_activity_modal.confirm_title')"
						:description="t('groups.activities.management.complete_activity_modal.confirm_body')"
					/>

					<div v-if="hasProgressMilestones" class="rounded-sm border border-default bg-background/60 px-4 py-4">
						<div class="grid gap-4 md:grid-cols-2">
							<div>
								<p class="text-xs uppercase text-muted">{{ t('groups.activities.management.complete_activity_modal.method_label') }}</p>
								<p class="mt-1 font-medium text-toned">
									{{ t(`groups.activities.management.complete_activity_modal.methods.${state.progressEntryMode}`) }}
								</p>
							</div>

							<div>
								<p class="text-xs uppercase text-muted">{{ t('groups.activities.management.complete_activity_modal.furthest_progress') }}</p>
								<p class="mt-1 font-medium text-toned">
									{{ progPointItems.find((item) => item.value === state.furthestProgressKey)?.label || t('groups.activities.management.complete_activity_modal.none_selected') }}
								</p>
							</div>
						</div>

						<div v-if="state.progressEntryMode === 'fflogs' && state.progressLinkUrl.trim()" class="mt-4">
							<p class="text-xs uppercase text-muted">{{ t('groups.activities.management.complete_activity_modal.fflogs_link') }}</p>
							<p class="mt-1 break-all text-sm text-toned">
								{{ state.progressLinkUrl }}
							</p>
						</div>
					</div>

					<div v-if="hasProgressMilestones" class="flex flex-col gap-2">
						<div
							v-for="item in progressSummary"
							:key="item.key"
							class="flex items-center justify-between rounded-sm border border-default bg-background/60 px-4 py-3"
						>
							<div class="min-w-0">
								<p class="font-medium text-toned">
									{{ item.label }}
								</p>
								<p class="text-xs text-muted">
									{{ item.key }}
								</p>
							</div>

							<div class="text-right text-sm text-toned">
								<p>{{ t('groups.activities.management.complete_activity_modal.kills') }}: {{ item.kills }}</p>
								<p>{{ t('groups.activities.management.complete_activity_modal.best_progress_percent') }}: {{ item.bestProgressPercent ?? 0 }}%</p>
							</div>
						</div>
					</div>

					<div v-if="state.progressNotes.trim()" class="rounded-sm border border-default bg-background/60 px-4 py-4">
						<p class="text-xs uppercase text-muted">{{ t('groups.activities.management.complete_activity_modal.notes') }}</p>
						<p class="mt-2 whitespace-pre-wrap text-sm text-toned">
							{{ state.progressNotes }}
						</p>
					</div>
				</div>
			</div>
		</template>

		<template #footer>
			<div class="flex w-full items-center justify-between gap-3">
				<UButton
					color="neutral"
					variant="ghost"
					:label="step === 1 ? t('general.cancel') : t('general.back')"
					@click="step === 1 ? close() : back()"
				/>

				<UButton
					v-if="!canConfirm"
					color="primary"
					icon="i-lucide-arrow-right"
					trailing
					:label="t('general.continue')"
					:loading="step === 3 && state.progressEntryMode === 'fflogs' ? isFetchingFflogsPreview : false"
					:disabled="!canGoNext"
					@click="next"
				/>
				<UButton
					v-else
					color="success"
					icon="i-lucide-flag"
					:label="t('groups.activities.management.complete_activity_modal.confirm')"
					:loading="isSubmitting"
					@click="submit"
				/>
			</div>
		</template>
	</UModal>
</template>
