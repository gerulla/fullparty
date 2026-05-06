<script setup lang="ts">
import { useI18n } from "vue-i18n";

type StatItem = {
	label: string
	count: number
	role?: string | null
	icon_url?: string | null
	flat_icon_url?: string | null
	transparent_icon_url?: string | null
}

const props = defineProps<{
	stats: {
		class: {
			group: StatItem[]
			overall: StatItem[]
		}
		phantom_job: {
			group: StatItem[]
			overall: StatItem[]
		}
	} | null
	emptyMessage?: string
}>();

const { t } = useI18n();

const podiumOrder = [0,1,2];

const podiumIconClass = (index: number) => {
	return 'h-8 w-8';
};
</script>

<template>
	<div class="space-y-4 border border-default bg-default/60 p-4">
		<!-- User stats header: summarizes historical play patterns for this applicant -->
		<p class="text-[11px] font-medium uppercase tracking-[0.12em] text-muted">
			{{ t('groups.activities.management.queue.modal.user_stats') }}
		</p>

		<div v-if="stats" class="gap-4">
			<!-- Most played class: compare with this group vs overall history -->
			<div class="space-y-3">
				<p class="text-sm font-medium text-toned">
					{{ t('groups.activities.management.queue.modal.most_played_class') }}
				</p>

				<div class="grid gap-4 text-sm md:grid-cols-2">
					<div class="space-y-3">
						<p class="text-muted">{{ t('groups.activities.management.queue.modal.with_group') }}</p>

						<div v-if="stats.class.group.length > 0" class="flex items-end justify-start gap-3">
							<div
								v-for="displayIndex in podiumOrder"
								:key="`class-group-${displayIndex}`"
								class="flex min-w-0 flex-col items-center gap-2"
							>
								<template v-if="stats.class.group[displayIndex]">
									<UTooltip :text="stats.class.group[displayIndex].label">
											<img
												v-if="stats.class.group[displayIndex].flat_icon_url || stats.class.group[displayIndex].icon_url"
												:src="stats.class.group[displayIndex].flat_icon_url || stats.class.group[displayIndex].icon_url || undefined"
												:alt="stats.class.group[displayIndex].label"
												class="shrink-0 object-contain"
												:class="podiumIconClass(displayIndex)"
											>
									</UTooltip>

									<span class="text-xs font-medium text-toned">
										{{ stats.class.group[displayIndex].count }}
									</span>
								</template>

							</div>
						</div>

						<span v-else class="text-muted">—</span>
					</div>

					<div class="space-y-3">
						<p class="text-muted">{{ t('groups.activities.management.queue.modal.overall') }}</p>

						<div v-if="stats.class.overall.length > 0" class="flex items-end justify-start gap-3">
							<div
								v-for="displayIndex in podiumOrder"
								:key="`class-overall-${displayIndex}`"
								class="flex min-w-0 flex-col items-center gap-2"
							>
								<template v-if="stats.class.overall[displayIndex]">
									<UTooltip :text="stats.class.overall[displayIndex].label">
											<img
												v-if="stats.class.overall[displayIndex].flat_icon_url || stats.class.overall[displayIndex].icon_url"
												:src="stats.class.overall[displayIndex].flat_icon_url || stats.class.overall[displayIndex].icon_url || undefined"
												:alt="stats.class.overall[displayIndex].label"
												class="shrink-0 object-contain"
												:class="podiumIconClass(displayIndex)"
											>
									</UTooltip>

									<span class="text-xs font-medium text-toned">
										{{ stats.class.overall[displayIndex].count }}
									</span>
								</template>

							</div>
						</div>

						<span v-else class="text-muted">—</span>
					</div>
				</div>
			</div>

			<!-- Most played phantom job: compare with this group vs overall history -->
			<div class="space-y-3 mt-4">
				<p class="text-sm font-medium text-toned">
					{{ t('groups.activities.management.queue.modal.most_played_phantom_job') }}
				</p>

				<div class="grid gap-4 text-sm md:grid-cols-2">
					<div class="space-y-3">
						<p class="text-muted">{{ t('groups.activities.management.queue.modal.with_group') }}</p>

						<div v-if="stats.phantom_job.group.length > 0" class="flex items-end justify-start gap-3">
							<div
								v-for="displayIndex in podiumOrder"
								:key="`phantom-group-${displayIndex}`"
								class="flex min-w-0 flex-col items-center gap-2"
							>
								<template v-if="stats.phantom_job.group[displayIndex]">
									<UTooltip :text="stats.phantom_job.group[displayIndex].label">
											<img
												v-if="stats.phantom_job.group[displayIndex].transparent_icon_url || stats.phantom_job.group[displayIndex].icon_url"
												:src="stats.phantom_job.group[displayIndex].transparent_icon_url || stats.phantom_job.group[displayIndex].icon_url || undefined"
												:alt="stats.phantom_job.group[displayIndex].label"
												class="shrink-0 object-contain"
												:class="podiumIconClass(displayIndex)"
											>
									</UTooltip>

									<span class="text-xs font-medium text-toned">
										{{ stats.phantom_job.group[displayIndex].count }}
									</span>
								</template>

							</div>
						</div>

						<span v-else class="text-muted">—</span>
					</div>

					<div class="space-y-3">
						<p class="text-muted">{{ t('groups.activities.management.queue.modal.overall') }}</p>

						<div v-if="stats.phantom_job.overall.length > 0" class="flex items-end justify-start gap-3">
							<div
								v-for="displayIndex in podiumOrder"
								:key="`phantom-overall-${displayIndex}`"
								class="flex min-w-0 flex-col items-center gap-2"
							>
								<template v-if="stats.phantom_job.overall[displayIndex]">
									<UTooltip :text="stats.phantom_job.overall[displayIndex].label">
											<img
												v-if="stats.phantom_job.overall[displayIndex].transparent_icon_url || stats.phantom_job.overall[displayIndex].icon_url"
												:src="stats.phantom_job.overall[displayIndex].transparent_icon_url || stats.phantom_job.overall[displayIndex].icon_url || undefined"
												:alt="stats.phantom_job.overall[displayIndex].label"
												class="shrink-0 object-contain"
												:class="podiumIconClass(displayIndex)"
											>
									</UTooltip>

									<span class="text-xs font-medium text-toned">
										{{ stats.phantom_job.overall[displayIndex].count }}
									</span>
								</template>

							</div>
						</div>

						<span v-else class="text-muted">—</span>
					</div>
				</div>
			</div>
		</div>

		<p v-else class="text-sm text-muted">
			{{ props.emptyMessage || t('groups.activities.management.queue.modal.no_user_stats') }}
		</p>
	</div>
</template>
