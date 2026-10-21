<script setup lang="ts">
import { computed } from "vue"
import { useI18n } from "vue-i18n"
import type { ActivityCompletionSummary } from "@/Types/ActivityProgression"
import { useDateTimeFormat } from "@/composables/useDateTimeFormat"

const props = defineProps<{
	completedProgression: ActivityCompletionSummary
}>()

const { t } = useI18n()
const { formatDateTime } = useDateTimeFormat()

const completedAtLabel = computed(() => {
	if (!props.completedProgression.completedAt) {
		return t("groups.activities.management.overview.progression.not_recorded")
	}

	return formatDateTime(props.completedProgression.completedAt, {
		year: "numeric",
		month: "2-digit",
		day: "2-digit",
		hour: "2-digit",
		minute: "2-digit",
	})
})

const milestoneProgressWidth = (progress: number | null) => {
	if (progress === null || Number.isNaN(progress)) {
		return "0%"
	}

	return `${Math.min(100, Math.max(0, progress))}%`
}
</script>

<template>
	<div class="flex flex-col gap-4 border border-default bg-muted dark:bg-elevated/50 p-4">
		<div class="flex flex-col gap-1">
			<h3 class="font-semibold text-sm uppercase tracking-wide text-toned">
				{{ t("groups.activities.management.overview.progression.title") }}
			</h3>
			<p class="text-sm text-muted">
				{{ t("groups.activities.management.overview.progression.subtitle") }}
			</p>
		</div>

		<div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
			<div class="flex flex-col gap-1">
				<span class="text-xs uppercase tracking-wide text-muted">{{ t("groups.activities.management.overview.progression.completed_at") }}</span>
				<span class="text-sm font-medium text-toned">{{ completedAtLabel }}</span>
			</div>

			<div class="flex flex-col gap-1">
				<span class="text-xs uppercase tracking-wide text-muted">{{ t("groups.activities.management.overview.progression.source") }}</span>
				<span class="text-sm font-medium text-toned">{{ completedProgression.sourceLabel }}</span>
			</div>

			<div class="flex flex-col gap-1">
				<span class="text-xs uppercase tracking-wide text-muted">{{ t("groups.activities.management.overview.progression.furthest_point") }}</span>
				<span class="text-sm font-medium text-toned">
					{{ completedProgression.furthestPointLabel || t("groups.activities.management.overview.progression.not_recorded") }}
				</span>
			</div>

			<div class="flex flex-col gap-1">
				<span class="text-xs uppercase tracking-wide text-muted">{{ t("groups.activities.management.overview.progression.best_progress") }}</span>
				<span class="text-sm font-medium text-toned">
					{{ completedProgression.bestProgressPercent !== null ? `${completedProgression.bestProgressPercent}%` : t("groups.activities.management.overview.progression.not_recorded") }}
				</span>
			</div>
		</div>

		<div
			v-if="completedProgression.progressLinkUrl || completedProgression.notes"
			class="grid gap-3 md:grid-cols-2"
		>
			<div v-if="completedProgression.progressLinkUrl" class="flex flex-col gap-1">
				<span class="text-xs uppercase tracking-wide text-muted">{{ t("groups.activities.management.overview.progression.evidence") }}</span>
				<a
					:href="completedProgression.progressLinkUrl"
					target="_blank"
					rel="noopener noreferrer"
					class="text-sm font-medium text-primary hover:underline"
				>
					{{ t("groups.activities.management.overview.progression.view_fflogs") }}
				</a>
			</div>

			<div v-if="completedProgression.notes" class="flex flex-col gap-1">
				<span class="text-xs uppercase tracking-wide text-muted">{{ t("groups.activities.management.overview.progression.notes") }}</span>
				<p class="break-words [overflow-wrap:anywhere] text-sm whitespace-pre-wrap text-toned">
					{{ completedProgression.notes }}
				</p>
			</div>
		</div>

		<div v-if="completedProgression.milestones.length > 0" class="flex flex-col gap-2">
			<span class="text-xs uppercase tracking-wide text-muted">{{ t("groups.activities.management.overview.progression.milestones") }}</span>

			<div class="grid gap-2">
				<div
					v-for="milestone in completedProgression.milestones"
					:key="milestone.key"
					class="relative overflow-hidden rounded-sm border border-default bg-muted/70"
				>
					<div
						class="absolute inset-y-0 left-0 bg-success/20 transition-[width] duration-300 ease-out"
						:style="{ width: milestoneProgressWidth(milestone.bestProgressPercent) }"
					/>
					<div class="relative flex flex-col gap-2 px-3 py-2 md:flex-row md:items-center md:justify-between">
						<span class="text-sm font-medium text-toned">{{ milestone.label }}</span>
						<div class="flex flex-wrap items-center gap-3 text-sm text-muted">
							<span>{{ t("groups.activities.management.complete_activity_modal.kills") }}: {{ milestone.kills }}</span>
							<span>{{ t("groups.activities.management.complete_activity_modal.best_progress_percent") }}: {{ milestone.bestProgressPercent !== null ? `${milestone.bestProgressPercent}%` : t("groups.activities.management.overview.progression.not_recorded") }}</span>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>
