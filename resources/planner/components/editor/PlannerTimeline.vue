<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const rulerMarks = Array.from({ length: 13 }, (_, index) => index * 5)

const tracks = computed(() => [
	{
		label: t('planner.editor.timeline.boss'),
		icon: 'i-lucide-skull',
		events: [
			{ label: t('planner.editor.timeline.cast'), left: '8%', width: '22%' },
			{ label: t('planner.editor.timeline.resolve'), left: '52%', width: '14%' },
		],
	},
	{
		label: t('planner.editor.timeline.players'),
		icon: 'i-lucide-users-round',
		events: [
			{ label: t('planner.editor.timeline.movement'), left: '21%', width: '27%' },
			{ label: t('planner.editor.timeline.spread'), left: '68%', width: '19%' },
		],
	},
])
</script>

<template>
	<section class="flex min-h-0 flex-col border-t border-default bg-elevated">
		<header class="flex h-10 shrink-0 items-center justify-between border-b border-default px-3">
			<div class="flex items-center gap-2">
				<UIcon name="i-lucide-list-video" class="size-4 text-primary" />
				<p class="text-xs font-semibold uppercase text-muted">{{ t('planner.editor.timeline.title') }}</p>
				<UBadge :label="t('planner.editor.timeline.mechanic')" color="neutral" variant="subtle" />
			</div>
			<UButton
				:label="t('planner.editor.timeline.add_mechanic')"
				icon="i-lucide-plus"
				color="neutral"
				variant="ghost"
				size="xs"
				disabled
			/>
		</header>

		<div class="grid min-h-0 flex-1 grid-cols-[10rem_minmax(0,1fr)] overflow-hidden">
			<div class="border-r border-default">
				<div class="h-7 border-b border-default" />
				<div
					v-for="track in tracks"
					:key="track.label"
					class="flex h-12 items-center gap-2 border-b border-default px-3 text-xs text-muted"
				>
					<UIcon :name="track.icon" class="size-4" />
					<span>{{ track.label }}</span>
				</div>
			</div>

			<div class="relative overflow-hidden">
				<div class="grid h-7 grid-cols-[repeat(13,minmax(0,1fr))] border-b border-default">
					<span
						v-for="mark in rulerMarks"
						:key="mark"
						class="border-l border-default px-1 text-[10px] text-dimmed"
					>
						{{ mark }}s
					</span>
				</div>

				<div
					v-for="track in tracks"
					:key="track.label"
					class="relative h-12 border-b border-default bg-default/40"
				>
					<div
						v-for="event in track.events"
						:key="event.label"
						class="absolute top-2 h-8 overflow-hidden border border-primary/60 bg-primary/15 px-2 py-1 text-xs text-primary"
						:style="{ left: event.left, width: event.width }"
					>
						{{ event.label }}
					</div>
				</div>

				<div class="pointer-events-none absolute inset-y-0 left-[38%] w-px bg-error">
					<span class="absolute -left-1 top-0 size-2 rotate-45 bg-error" />
				</div>
			</div>
		</div>
	</section>
</template>
