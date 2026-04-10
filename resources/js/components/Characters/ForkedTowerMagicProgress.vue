<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";

const { t } = useI18n();

const placeholderProgress = computed(() => ({
	clears: 0,
	bosses: [
		{
			key: 'demon_tablet',
			label: t('characters.card.forked_tower.bosses.tba'),
			progress: 0,
			kills: 0,
		},
		{
			key: 'dead_stars',
			label: t('characters.card.forked_tower.bosses.tba'),
			progress: 0,
			kills: 0,
		},
		{
			key: 'marble_dragon',
			label: t('characters.card.forked_tower.bosses.tba'),
			progress: 0,
			kills: 0,
		},
		{
			key: 'magitaur',
			label: t('characters.card.forked_tower.bosses.tba'),
			progress: 0,
			kills: 0,
		},
	]
}));
</script>

<template>
	<section class="space-y-3">
		<div class="flex items-center gap-2">
			<UIcon name="i-lucide-sparkles" size="18" class="text-muted" />
			<h3 class="text-sm font-semibold uppercase tracking-wide text-muted">
				{{ t('characters.card.sections.forked_tower_magic') }}
			</h3>
		</div>

		<div class="flex items-center justify-between rounded-sm border border-default bg-muted/20 px-3 py-2">
			<p class="text-sm font-semibold">{{ t('characters.card.forked_tower.clear_count') }}</p>
			<UBadge
				:label="t('characters.card.forked_tower.clears', { count: placeholderProgress.clears })"
				color="primary"
				variant="subtle"
				size="md"
			/>
		</div>

		<div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
			<div
				v-for="boss in placeholderProgress.bosses"
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
					v-model="boss.progress"
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
