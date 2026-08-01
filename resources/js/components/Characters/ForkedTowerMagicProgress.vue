<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps({
	progress: {
		type: Object,
		required: true,
	},
});

const { t } = useI18n();

const displayProgress = computed(() => ({
	clears: props.progress?.clears ?? 0,
	bosses: (props.progress?.bosses ?? []).map((boss) => ({
		...boss,
		label: t(`characters.card.forked_tower.bosses.${boss.key}`),
	})),
}));

const hasClearProof = computed(() => Number(displayProgress.value.clears) > 0);
</script>

<template>
	<section class="space-y-3">
		<div class="flex items-center gap-2">
			<UIcon name="i-lucide-sparkles" size="18" class="text-muted" />
			<h3 class="text-sm font-semibold uppercase tracking-wide text-muted">
				{{ t('characters.card.sections.forked_tower_magic') }}
			</h3>
		</div>

		<div class="flex items-center justify-between gap-3 rounded-sm border border-default bg-muted/20 px-3 py-2">
			<div class="flex min-w-0 flex-wrap items-center gap-2">
				<p class="text-sm font-semibold">{{ t('characters.card.forked_tower.clear_count') }}</p>
				<UBadge
					v-if="hasClearProof"
					:label="t('characters.card.forked_tower.verified_with_fflogs')"
					color="neutral"
					variant="subtle"
					size="sm"
				/>
			</div>
			<UBadge
				:label="t('characters.card.forked_tower.clears', { count: displayProgress.clears })"
				color="primary"
				variant="subtle"
				size="md"
			/>
		</div>

		<div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
			<div
				v-for="boss in displayProgress.bosses"
				:key="boss.key"
				class="space-y-2 rounded-sm border border-default bg-muted/20 px-3 py-3"
			>
				<div class="flex items-start justify-between gap-2">
					<p class="text-sm font-semibold leading-tight">{{ boss.label }}</p>
					<UBadge
						:label="`${boss.progress}%`"
						color="neutral"
						variant="subtle"
						size="sm"
					/>
				</div>

				<UProgress
					:model-value="boss.progress"
					:max="100"
					:ui="{ base: 'rounded-none', indicator: 'rounded-none' }"
				/>

				<div class="flex items-center justify-between text-sm text-muted">
					<span>{{ t('characters.card.forked_tower.kills') }}</span>
					<span class="text-base font-semibold text-toned">{{ boss.kills }}</span>
				</div>
			</div>
		</div>
	</section>
</template>
