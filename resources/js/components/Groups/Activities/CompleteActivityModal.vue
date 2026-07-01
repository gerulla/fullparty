<script setup lang="ts">
import type { ActivityCompletionPreviewMilestone, ActivityProgressMilestone, ActivityProgressPoint } from "@/Types/ActivityManagement";
import axios from "axios";
import { computed, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { route } from "ziggy-js";
import { localizedValue } from "@/utils/localizedValue";
import { activityTextLimits } from "@/utils/activityTextLimits";

const props = defineProps<{
	open: boolean
	groupSlug: string
	activityId: number
	isSubmitting: boolean
	canUseFflogsCompletion: boolean
	progPoints: ActivityProgressPoint[]
	progressMilestones: ActivityProgressMilestone[]
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

type MilestoneNumberInput = string | number | null | undefined;
type MilestoneInputState = {
	kills: MilestoneNumberInput
	best_progress_percent: MilestoneNumberInput
};

const { t, locale } = useI18n();

const step = ref(1);
const isFetchingFflogsPreview = ref(false);
const fflogsPreviewError = ref<string | null>(null);
const fflogsPreviewMeta = ref<{ report_code?: string | null, report_title?: string | null } | null>(null);
const milestoneInputErrors = ref<Record<string, string>>({});
const missingProgressConfirmationVisible = ref(false);

const state = reactive({
	progressEntryMode: 'manual' as 'manual' | 'fflogs',
	progressLinkUrl: '',
	progressNotes: '',
	furthestProgressKey: '',
	milestones: {} as Record<string, MilestoneInputState>,
});

const hasProgressMilestones = computed(() => props.progressMilestones.length > 0);
const maxSteps = computed(() => {
	if (!hasProgressMilestones.value) {
		return 1;
	}

	return state.progressEntryMode === 'fflogs' ? 5 : 4;
});

const progPointItems = computed(() => props.progPoints.map((progPoint) => ({
	label: localizedValue(progPoint.label, locale.value) || progPoint.key,
	value: progPoint.key,
})));

const milestoneInputState = (milestone: ActivityProgressMilestone): MilestoneInputState => ({
	kills: milestone.kills > 0 ? String(milestone.kills) : '',
	best_progress_percent: milestone.best_progress_percent !== null ? String(milestone.best_progress_percent) : '',
});

const ensureMilestoneInputState = (milestoneKey: string): MilestoneInputState => {
	if (!state.milestones[milestoneKey]) {
		state.milestones[milestoneKey] = {
			kills: '',
			best_progress_percent: '',
		};
	}

	return state.milestones[milestoneKey];
};

const seedMissingMilestones = () => {
	for (const milestone of props.progressMilestones) {
		if (!state.milestones[milestone.milestone_key]) {
			state.milestones[milestone.milestone_key] = milestoneInputState(milestone);
		}
	}
};

const milestoneInputValue = (milestoneKey: string, field: keyof MilestoneInputState): MilestoneNumberInput => (
	state.milestones[milestoneKey]?.[field] ?? ''
);

const isEmptyMilestoneInput = (value: MilestoneNumberInput): boolean => (
	value === '' || value === null || value === undefined || String(value).trim() === ''
);

const shouldAutoCompleteProgress = (value: MilestoneNumberInput): boolean => {
	const inputValue = value === null || value === undefined ? '' : String(value).trim();

	return /^\d+$/.test(inputValue) && Number(inputValue) > 0;
};

const updateMilestoneValue = (milestoneKey: string, field: keyof MilestoneInputState, value: MilestoneNumberInput) => {
	const milestone = ensureMilestoneInputState(milestoneKey);

	milestone[field] = value;
	missingProgressConfirmationVisible.value = false;
	delete milestoneInputErrors.value[`${milestoneKey}.${field}`];

	if (field === 'kills' && shouldAutoCompleteProgress(value)) {
		milestone.best_progress_percent = '100';
		delete milestoneInputErrors.value[`${milestoneKey}.best_progress_percent`];
	}
};

const updateMilestoneFromInputEvent = (milestoneKey: string, field: keyof MilestoneInputState, event: Event) => {
	const input = event.target instanceof HTMLInputElement ? event.target : null;

	updateMilestoneValue(milestoneKey, field, input?.value ?? '');
};

const normalizeKillsInput = (value: MilestoneNumberInput): number => {
	const numericValue = Number(value || 0);

	return Number.isFinite(numericValue)
		? Math.max(0, Math.trunc(numericValue))
		: 0;
};

const normalizeProgressPercentInput = (value: MilestoneNumberInput): number | null => {
	if (value === '' || value === null || value === undefined) {
		return null;
	}

	const numericValue = Number(value);

	return Number.isFinite(numericValue)
		? Math.min(100, Math.max(0, numericValue))
		: null;
};

const milestoneFieldError = (milestoneKey: string, field: keyof MilestoneInputState) => (
	milestoneInputErrors.value[`${milestoneKey}.${field}`] ?? null
);

const parseKillsInput = (value: MilestoneNumberInput, milestoneKey: string): number | null => {
	const inputValue = value === null || value === undefined ? '' : String(value).trim();

	if (inputValue === '') {
		return 0;
	}

	if (!/^\d+$/.test(inputValue)) {
		milestoneInputErrors.value[`${milestoneKey}.kills`] = t('groups.activities.management.complete_activity_modal.errors.kills_integer');

		return null;
	}

	return Math.max(0, Number(inputValue));
};

const parseProgressPercentInput = (value: MilestoneNumberInput, milestoneKey: string): number | null | undefined => {
	const inputValue = value === null || value === undefined ? '' : String(value).trim();

	if (inputValue === '') {
		return null;
	}

	if (!/^(?:\d+|\d+\.\d+|\.\d+)$/.test(inputValue)) {
		milestoneInputErrors.value[`${milestoneKey}.best_progress_percent`] = t('groups.activities.management.complete_activity_modal.errors.percent_numeric');

		return undefined;
	}

	const numericValue = Number(inputValue);

	if (numericValue < 0 || numericValue > 100) {
		milestoneInputErrors.value[`${milestoneKey}.best_progress_percent`] = t('groups.activities.management.complete_activity_modal.errors.percent_range');

		return undefined;
	}

	return numericValue;
};

const validateMilestoneInputs = () => {
	milestoneInputErrors.value = {};
	let isValid = true;

	for (const milestone of props.progressMilestones) {
		const values = ensureMilestoneInputState(milestone.milestone_key);
		const kills = parseKillsInput(values.kills, milestone.milestone_key);
		const bestProgressPercent = parseProgressPercentInput(values.best_progress_percent, milestone.milestone_key);

		if (kills === null || bestProgressPercent === undefined) {
			isValid = false;
		}
	}

	return isValid;
};

const progressSummary = computed(() => props.progressMilestones.map((milestone) => ({
	key: milestone.milestone_key,
	label: localizedValue(milestone.milestone_label, locale.value) || milestone.milestone_key,
	kills: normalizeKillsInput(milestoneInputValue(milestone.milestone_key, 'kills')),
	bestProgressPercent: normalizeProgressPercentInput(milestoneInputValue(milestone.milestone_key, 'best_progress_percent')),
})));

const hasMissingProgressFields = computed(() => {
	if (progPointItems.value.length > 0 && !state.furthestProgressKey) {
		return true;
	}

	return props.progressMilestones.some((milestone) => {
		const values = state.milestones[milestone.milestone_key] ?? {
			kills: '',
			best_progress_percent: '',
		};

		return isEmptyMilestoneInput(values.kills) || isEmptyMilestoneInput(values.best_progress_percent);
	});
});

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
		milestoneInputState(milestone),
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
	milestoneInputErrors.value = {};
	missingProgressConfirmationVisible.value = false;
};

watch(() => props.open, (isOpen) => {
	if (isOpen) {
		resetState();
	}
}, { immediate: true });

watch(() => props.progressMilestones.map((milestone) => milestone.milestone_key), () => {
	if (props.open) {
		seedMissingMilestones();
	}
}, { immediate: true });

watch(() => state.furthestProgressKey, () => {
	missingProgressConfirmationVisible.value = false;
});

const close = () => {
	emit('update:open', false);
};

const errorFor = (key: string) => props.errors?.[key]?.[0] ?? null;

const applyPreviewMilestones = (milestones: ActivityCompletionPreviewMilestone[]) => {
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

	const isProgressResultStep = hasProgressMilestones.value
		&& ((step.value === 3 && state.progressEntryMode === 'manual') || (step.value === 4 && state.progressEntryMode === 'fflogs'));

	if (hasProgressMilestones.value && step.value === 3 && state.progressEntryMode === 'fflogs') {
		void (async () => {
			const fetched = await fetchFflogsPreview();

			if (fetched) {
				missingProgressConfirmationVisible.value = false;
				step.value = 4;
			}
		})();

		return;
	}

	if (isProgressResultStep) {
		if (!validateMilestoneInputs()) {
			return;
		}

		if (hasMissingProgressFields.value && !missingProgressConfirmationVisible.value) {
			missingProgressConfirmationVisible.value = true;

			return;
		}
	}

	missingProgressConfirmationVisible.value = false;
	step.value = Math.min(maxSteps.value, step.value + 1);
};

const back = () => {
	missingProgressConfirmationVisible.value = false;
	step.value = Math.max(1, step.value - 1);
};

const submit = () => {
	if (hasProgressMilestones.value && !validateMilestoneInputs()) {
		step.value = state.progressEntryMode === 'fflogs' ? 4 : 3;

		return;
	}

	emit('confirm', {
		progress_entry_mode: hasProgressMilestones.value ? state.progressEntryMode : null,
		progress_link_url: hasProgressMilestones.value && state.progressEntryMode === 'fflogs' && state.progressLinkUrl.trim().length > 0
			? state.progressLinkUrl.trim()
			: null,
		progress_notes: state.progressNotes.trim().length > 0 ? state.progressNotes.trim() : null,
		furthest_progress_key: state.furthestProgressKey || null,
		milestones: props.progressMilestones.map((milestone) => ({
			milestone_key: milestone.milestone_key,
			kills: normalizeKillsInput(milestoneInputValue(milestone.milestone_key, 'kills')),
			best_progress_percent: normalizeProgressPercentInput(milestoneInputValue(milestone.milestone_key, 'best_progress_percent')),
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
							:maxlength="activityTextLimits.progressLinkUrl"
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

					<UAlert
						v-if="missingProgressConfirmationVisible"
						color="warning"
						variant="soft"
						icon="i-lucide-triangle-alert"
						:title="t('groups.activities.management.complete_activity_modal.empty_progress_warning_title')"
						:description="t('groups.activities.management.complete_activity_modal.empty_progress_warning_body')"
					/>

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

								<UFormField
									:label="t('groups.activities.management.complete_activity_modal.kills')"
									:error="milestoneFieldError(milestone.milestone_key, 'kills')"
								>
									<UInput
										:model-value="milestoneInputValue(milestone.milestone_key, 'kills')"
										type="text"
										inputmode="numeric"
										class="w-full"
										@input="(event) => updateMilestoneFromInputEvent(milestone.milestone_key, 'kills', event)"
										@update:model-value="(value) => updateMilestoneValue(milestone.milestone_key, 'kills', value)"
									/>
								</UFormField>

								<UFormField
									:label="t('groups.activities.management.complete_activity_modal.best_progress_percent')"
									:error="milestoneFieldError(milestone.milestone_key, 'best_progress_percent')"
								>
									<UInput
										:model-value="milestoneInputValue(milestone.milestone_key, 'best_progress_percent')"
										type="text"
										inputmode="decimal"
										class="w-full"
										@input="(event) => updateMilestoneFromInputEvent(milestone.milestone_key, 'best_progress_percent', event)"
										@update:model-value="(value) => updateMilestoneValue(milestone.milestone_key, 'best_progress_percent', value)"
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
							:maxlength="activityTextLimits.progressNotes"
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
								<p>
									{{ t('groups.activities.management.complete_activity_modal.best_progress_percent') }}:
									{{ item.bestProgressPercent !== null ? `${item.bestProgressPercent}%` : t('groups.activities.management.overview.progression.not_recorded') }}
								</p>
							</div>
						</div>
					</div>

					<div v-if="state.progressNotes.trim()" class="rounded-sm border border-default bg-background/60 px-4 py-4">
						<p class="text-xs uppercase text-muted">{{ t('groups.activities.management.complete_activity_modal.notes') }}</p>
						<p class="mt-2 break-words [overflow-wrap:anywhere] whitespace-pre-wrap text-sm text-toned">
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
