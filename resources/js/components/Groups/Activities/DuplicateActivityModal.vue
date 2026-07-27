<script setup lang="ts">
import { parseDate } from "@internationalized/date";
import type { DateValue } from "@internationalized/date";
import { computed, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { activityTextLimits } from "@/utils/activityTextLimits";

type DuplicateActivityPayload = {
	title: string
	starts_at: string
	status: 'draft' | 'scheduled'
	copy_bench: boolean
	copy_fill_ins: boolean
}

const props = defineProps<{
	open: boolean
	sourceTitle: string
	sourceStartsAt: string | null
	mainAssignmentCount: number
	benchAssignmentCount: number
	fillInAssignmentCount: number
	pending: boolean
	errors: Record<string, string | undefined>
}>();

const emit = defineEmits<{
	'update:open': [open: boolean]
	submit: [payload: DuplicateActivityPayload]
}>();

const { locale, t } = useI18n();
const selectedDate = ref<DateValue>();
const dateCalendarOpen = ref(false);
const form = reactive({
	title: '',
	start_date: '',
	start_time: '',
	create_as_planned: false,
	copy_bench: true,
	copy_fill_ins: false,
});

const minimumDate = computed(() => new Date().toISOString().slice(0, 10));
const minimumCalendarDate = computed(() => parseDate(minimumDate.value));
const canSubmit = computed(() => Boolean(form.title.trim() && form.start_date && form.start_time));
const targetStatus = computed<'draft' | 'scheduled'>(() => (
	form.create_as_planned ? 'scheduled' : 'draft'
));
const formattedDate = computed(() => selectedDate.value
	? new Intl.DateTimeFormat(locale.value, {
		day: '2-digit',
		month: '2-digit',
		year: 'numeric',
	}).format(new Date(`${selectedDate.value.toString()}T00:00:00`))
	: t('groups.activities.management.duplicate.date'));

const suggestedStart = (): Date => {
	const source = props.sourceStartsAt ? new Date(props.sourceStartsAt) : null;
	const candidate = source && !Number.isNaN(source.getTime())
		? new Date(source)
		: new Date();

	if (!source || Number.isNaN(source.getTime())) {
		candidate.setUTCDate(candidate.getUTCDate() + 1);
		candidate.setUTCHours(20, 0, 0, 0);
	} else {
		candidate.setUTCDate(candidate.getUTCDate() + 1);
	}

	while (candidate.getTime() <= Date.now()) {
		candidate.setUTCDate(candidate.getUTCDate() + 1);
	}

	return candidate;
};

watch(() => props.open, (open) => {
	if (!open) {
		return;
	}

	const suggested = suggestedStart().toISOString();
	form.title = props.sourceTitle;
	form.start_date = suggested.slice(0, 10);
	selectedDate.value = parseDate(form.start_date);
	form.start_time = suggested.slice(11, 16);
	form.create_as_planned = false;
	form.copy_bench = props.benchAssignmentCount > 0;
	form.copy_fill_ins = false;
});

const updateDate = (value: DateValue | undefined) => {
	if (!value) {
		return;
	}

	selectedDate.value = value;
	form.start_date = value.toString();
	dateCalendarOpen.value = false;
};

const submit = () => {
	if (!canSubmit.value || props.pending) {
		return;
	}

	emit('submit', {
		title: form.title.trim(),
		starts_at: `${form.start_date}T${form.start_time}`,
		status: targetStatus.value,
		copy_bench: form.copy_bench,
		copy_fill_ins: form.copy_fill_ins,
	});
};
</script>

<template>
	<UModal
		:open="open"
		:title="t('groups.activities.management.duplicate.title')"
		:description="t('groups.activities.management.duplicate.description')"
		:dismissible="!pending"
		:ui="{ content: 'sm:max-w-2xl rounded-sm' }"
		@update:open="emit('update:open', $event)"
	>
		<template #body>
			<form class="flex flex-col gap-6" @submit.prevent="submit">
				<UFormField
					:label="t('groups.activities.management.duplicate.name')"
					:error="errors.title"
					required
				>
					<UInput
						v-model="form.title"
						size="lg"
						class="w-full"
						:maxlength="activityTextLimits.title"
					/>
				</UFormField>

				<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
					<UFormField
						:label="t('groups.activities.management.duplicate.date')"
						:description="t('groups.activities.management.duplicate.server_time_hint')"
						:error="errors.starts_at"
						:ui="{ description: 'invisible' }"
						required
					>
						<UPopover v-model:open="dateCalendarOpen">
							<UButton
								color="neutral"
								variant="outline"
								icon="i-lucide-calendar"
								size="lg"
								class="w-full justify-start font-normal"
								:label="formattedDate"
							/>

							<template #content>
								<div class="border border-default bg-neutral-950 p-3">
									<UCalendar
										:model-value="selectedDate"
										:min-value="minimumCalendarDate"
										:week-starts-on="1"
										:year-controls="false"
										:prevent-deselect="true"
										color="primary"
										class="min-w-72"
										@update:model-value="updateDate"
									/>
								</div>
							</template>
						</UPopover>
					</UFormField>

					<UFormField
						:label="t('groups.activities.management.duplicate.time')"
						:description="t('groups.activities.management.duplicate.server_time_hint')"
						:error="errors.starts_at"
						required
					>
						<UInput
							v-model="form.start_time"
							type="time"
							size="lg"
							class="w-full"
							step="900"
						/>
					</UFormField>
				</div>

				<div class="divide-y divide-default border-y border-default">
					<div class="flex items-start justify-between gap-5 py-4">
						<div class="min-w-0">
							<div class="flex flex-wrap items-center gap-2">
								<p class="font-medium text-toned">
									{{ t('groups.activities.management.duplicate.create_as_planned') }}
								</p>
								<UBadge
									color="neutral"
									variant="soft"
									:label="t(`groups.activities.statuses.${targetStatus}`)"
								/>
							</div>
							<p class="mt-1 text-sm text-muted">
								{{ t(`groups.activities.management.duplicate.${form.create_as_planned ? 'planned_help' : 'draft_help'}`) }}
							</p>
						</div>
						<USwitch v-model="form.create_as_planned" />
					</div>

					<div class="flex items-start justify-between gap-5 py-4">
						<div class="min-w-0">
							<p class="font-medium text-toned">
								{{ t('groups.activities.management.duplicate.copy_bench') }}
							</p>
							<p class="mt-1 text-sm text-muted">
								{{ t('groups.activities.management.duplicate.assignment_count', { count: benchAssignmentCount }) }}
							</p>
						</div>
						<USwitch
							v-model="form.copy_bench"
							:disabled="benchAssignmentCount === 0"
						/>
					</div>

					<div class="flex items-start justify-between gap-5 py-4">
						<div class="min-w-0">
							<p class="font-medium text-toned">
								{{ t('groups.activities.management.duplicate.copy_fill_ins') }}
							</p>
							<p class="mt-1 text-sm text-muted">
								{{ t('groups.activities.management.duplicate.assignment_count', { count: fillInAssignmentCount }) }}
							</p>
						</div>
						<USwitch
							v-model="form.copy_fill_ins"
							:disabled="fillInAssignmentCount === 0"
						/>
					</div>
				</div>

				<UAlert
					color="neutral"
					variant="subtle"
					icon="i-lucide-copy-check"
					:title="t('groups.activities.management.duplicate.roster_summary', { count: mainAssignmentCount })"
					:description="t('groups.activities.management.duplicate.reset_notice')"
				/>
			</form>
		</template>

		<template #footer>
			<div class="flex w-full justify-end gap-2">
				<UButton
					color="neutral"
					variant="outline"
					:label="t('general.cancel')"
					:disabled="pending"
					@click="emit('update:open', false)"
				/>
				<UButton
					color="primary"
					icon="i-lucide-copy-plus"
					:label="t('groups.activities.management.duplicate.action')"
					:loading="pending"
					:disabled="!canSubmit"
					@click="submit"
				/>
			</div>
		</template>
	</UModal>
</template>
