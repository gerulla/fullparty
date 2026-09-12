<script setup lang="ts">
import type { MemberNoteSummary } from "@/Types/Groups";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { memberNoteIndicator, memberNoteSeverityDots } from "@/utils/memberNotePresentation";

const props = defineProps<{
	userId: number | null
	noteSummary: MemberNoteSummary
	color?: string
	variant?: string
	size?: string
	showSeverityIndicator?: boolean
}>();

const emit = defineEmits<{
	open: [userId: number]
}>();

const { t } = useI18n();

const canOpen = computed(() => props.noteSummary.can_view && props.userId !== null);
const totalCount = computed(() => props.noteSummary.current_group_count + props.noteSummary.shared_count);
const label = computed(() => totalCount.value > 0
	? `${t('general.notes')} (${totalCount.value})`
	: t('general.notes'));
const indicator = computed(() => props.showSeverityIndicator && totalCount.value > 0
    ? memberNoteIndicator(props.noteSummary.highest_severity) : null);
const severityDots = computed(() => props.showSeverityIndicator && totalCount.value > 0
    ? memberNoteSeverityDots(props.noteSummary.severities ?? [props.noteSummary.highest_severity ?? 'info']) : []);
const accessibleLabel = computed(() => severityDots.value.length > 0
    ? `${label.value}: ${severityDots.value.map(({ severity }) => t(`groups.members.notes.severities.${severity}`)).join(', ')}` : label.value);

const handleClick = () => {
	if (props.userId === null || !props.noteSummary.can_view) {
		return;
	}

	emit('open', props.userId);
};
</script>

<template>
	<span v-if="canOpen" class="relative inline-flex">
		<UButton
			:color="indicator?.color ?? color ?? 'secondary'"
			:variant="variant ?? 'subtle'"
			:size="size"
			icon="i-lucide-notebook-pen"
			:label="label"
			:aria-label="accessibleLabel"
			:title="severityDots.length ? accessibleLabel : undefined"
			class="w-full justify-center"
			@click="handleClick"
		/>
		<span v-if="severityDots.length" aria-hidden="true" class="pointer-events-none absolute right-0 top-0 flex -translate-y-1/2 gap-1">
			<UChip v-for="dot in severityDots" :key="dot.severity" standalone size="sm" :color="dot.color" />
		</span>
	</span>
</template>
