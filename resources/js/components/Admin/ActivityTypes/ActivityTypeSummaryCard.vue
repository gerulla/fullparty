<script setup lang="ts">
import { computed } from "vue";
import { useMediaQuery } from "@vueuse/core";
import { useI18n } from "vue-i18n";
import { localizedValue } from "@/utils/localizedValue";

const props = defineProps<{
	form: {
		slug: string
		draft_name: Record<string, string>
		draft_small_image_url?: string | null
		draft_banner_image_url?: string | null
		draft_layout_schema: { groups?: Array<{ size?: number }> }
		draft_slot_schema: Array<unknown>
		draft_application_schema: Array<unknown>
		draft_roster_summary_presets?: Array<unknown>
		draft_progress_schema: { milestones?: Array<unknown> }
		draft_difficulty?: string | null
		draft_default_min_item_level?: number | null
		draft_bench_size?: number | null
		draft_prog_points?: Array<unknown>
		draft_fflogs_zone_id?: number | null
	}
}>();

const { t, locale } = useI18n();
const desktop = useMediaQuery('(min-width: 1280px)');

const totalSlots = computed(() => (props.form.draft_layout_schema?.groups ?? []).reduce((total, group) => total + Number(group.size || 0), 0));
const difficultyLabel = computed(() => props.form.draft_difficulty
	? t(`admin.activity_types.difficulties.${props.form.draft_difficulty}`)
	: '—');

const summary = computed(() => ({
	groups: props.form.draft_layout_schema?.groups?.length ?? 0,
	slots: totalSlots.value,
	slot_fields: props.form.draft_slot_schema?.length ?? 0,
	application_questions: props.form.draft_application_schema?.length ?? 0,
	roster_summary_presets: props.form.draft_roster_summary_presets?.length ?? 0,
	progress_milestones: props.form.draft_progress_schema?.milestones?.length ?? 0,
	prog_points: props.form.draft_prog_points?.length ?? 0,
	difficulty: difficultyLabel.value,
	default_min_item_level: props.form.draft_default_min_item_level ?? '—',
	fflogs_zone_id: props.form.draft_fflogs_zone_id ?? '—',
	bench: props.form.draft_bench_size ?? 0,
}));
</script>

<template>
	<details :open="desktop" class="admin-panel group">
		<summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-xl p-4 focus-visible:outline-primary sm:p-5 [&::-webkit-details-marker]:hidden">
			<div class="min-w-0">
				<h2 class="text-lg font-semibold">{{ t('admin.activity_types.summary.title') }}</h2>
				<p class="text-sm text-muted">{{ t('admin.activity_types.summary.subtitle') }}</p>
			</div>
			<UIcon name="i-lucide-chevron-down" class="size-4 shrink-0 text-muted group-open:rotate-180" />
		</summary>

		<div class="flex flex-col gap-4 border-t border-default p-4 sm:p-5">
			<div
				v-if="form.draft_banner_image_url || form.draft_small_image_url"
				class="grid gap-3"
			>
				<div v-if="form.draft_banner_image_url" class="aspect-[3/1] overflow-hidden rounded-lg bg-elevation-2 dark:bg-elevation-3">
					<img
						:src="form.draft_banner_image_url"
						:alt="t('admin.activity_types.general.banner_image.preview_alt')"
						class="h-full w-full object-cover object-center"
					>
				</div>

				<div v-if="form.draft_small_image_url" class="aspect-[10/17] w-full max-w-28 overflow-hidden rounded-lg bg-elevation-2 dark:bg-elevation-3">
					<img
						:src="form.draft_small_image_url"
						:alt="t('admin.activity_types.general.small_image.preview_alt')"
						class="h-full w-full object-cover object-center"
					>
				</div>
			</div>

			<div class="admin-inset min-w-0 break-words p-3">
				<p class="text-sm text-muted">{{ t('admin.activity_types.summary.draft_name') }}</p>
				<p class="mt-1 font-semibold text-highlighted">
					{{ localizedValue(form.draft_name, locale) || t('admin.activity_types.summary.untitled') }}
				</p>
				<p class="mt-2 text-sm text-muted">
					{{ form.slug || t('admin.activity_types.summary.no_slug') }}
				</p>
			</div>

			<dl class="divide-y divide-default">
				<div v-for="(value, key) in summary" :key="key" class="flex items-baseline justify-between gap-3 py-2 first:pt-0 last:pb-0">
					<dt class="min-w-0 text-sm text-muted">{{ t(`admin.activity_types.summary.${key}`) }}</dt>
					<dd class="text-end text-sm font-semibold text-highlighted tabular-nums">{{ value }}</dd>
				</div>
			</dl>
		</div>
	</details>
</template>
