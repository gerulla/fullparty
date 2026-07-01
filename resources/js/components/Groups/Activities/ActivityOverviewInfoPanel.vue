<script setup lang="ts">
import { ref } from "vue";
import { useI18n } from "vue-i18n";

defineProps<{
	activityTypeName: string
	serverStartsAtLabel: string
	localStartsAtLabel: string
	relativeStartsAtLabel: string
	localTimeZone: string
	durationLabel: string
	datacenter: string | null
	organizerLabel: string
	organizerCharacter: {
		name: string
		avatar_url: string | null
	} | null
	organizerAvatarUrl: string | null
	groupName: string
	assignedMainSlotCount: number
	mainSlotCount: number
	benchSlotCount: number
	difficultyLabel: string
	runStyleLabel: string
	intensityLabel: string
	minimumItemLevelLabel: string
	beginnerFriendlyLabel: string
	description: string | null
	notes: string | null
	targetProgPointLabel: string
	detailMode: "application" | "self_assignment"
	pendingApplicationCount?: number
	assignmentModeLabel?: string
}>();

const { t } = useI18n();
const detailsOpen = ref(false);
</script>

<template>
	<section class="border border-default bg-muted/20 dark:bg-elevated/25">
		<div class="grid gap-px md:grid-cols-3">
			<div class="bg-background px-4 py-4">
				<p class="text-xs uppercase tracking-[0.22em] text-muted">
					{{ t("groups.activities.management.type") }}
				</p>
				<p class="mt-2 break-words [overflow-wrap:anywhere] font-semibold text-toned">
					{{ activityTypeName }}
				</p>
				<div class="mt-3 border-t border-default/70 pt-3">
					<p class="text-xs uppercase tracking-[0.22em] text-muted">
						{{ t("groups.activities.overview.meta.roster") }}
					</p>
					<p class="mt-2 font-semibold text-toned">
						{{ t("groups.activities.overview.meta.filled_slots", { assigned: assignedMainSlotCount, total: mainSlotCount }) }}
					</p>
					<p class="mt-1 text-sm text-muted">
						{{ t("groups.activities.overview.meta.bench_slots", { count: benchSlotCount }) }}
					</p>
				</div>
			</div>

			<div class="bg-background px-4 py-4">
				<div>
					<p class="text-xs uppercase tracking-[0.22em] text-muted">
						{{ t("groups.activities.create.summary.starts_at_st") }}
					</p>
					<p class="mt-2 break-words [overflow-wrap:anywhere] font-semibold text-toned">
						{{ serverStartsAtLabel }}
					</p>
				</div>

				<div class="mt-3 border-t border-default/70 pt-3">
					<p class="text-xs uppercase tracking-[0.22em] text-muted">
						{{ t("groups.activities.create.summary.starts_at_local", { timezone: localTimeZone }) }}
					</p>
					<p class="mt-2 break-words [overflow-wrap:anywhere] font-semibold text-toned">
						{{ localStartsAtLabel }}
					</p>
					<p class="mt-1 text-sm text-muted">
						{{ relativeStartsAtLabel }}
					</p>
				</div>
			</div>

			<div class="bg-background px-4 py-4">
				<div>
					<p class="text-xs uppercase tracking-[0.22em] text-muted">
						{{ t("groups.activities.create.summary.datacenter") }}
					</p>
					<p class="mt-2 font-semibold text-toned">
						{{ datacenter || "—" }}
					</p>
				</div>

				<div class="mt-3 border-t border-default/70 pt-3">
					<p class="text-xs uppercase tracking-[0.22em] text-muted">
						{{ t("groups.activities.management.organizer") }}
					</p>
					<div class="mt-2">
						<UUser
							v-if="organizerCharacter"
							size="sm"
							:name="organizerCharacter.name"
							:avatar="organizerCharacter.avatar_url
								? {
									src: organizerCharacter.avatar_url,
									alt: organizerCharacter.name,
								}
								: undefined"
							:description="groupName"
						/>
						<div v-else class="flex items-center gap-3">
							<UAvatar
								v-if="organizerAvatarUrl"
								size="sm"
								:src="organizerAvatarUrl"
								:alt="organizerLabel"
							/>
							<div class="min-w-0">
								<p class="break-words [overflow-wrap:anywhere] font-semibold text-toned">
									{{ organizerLabel }}
								</p>
								<p class="mt-1 break-words [overflow-wrap:anywhere] text-sm text-muted">
									{{ groupName }}
								</p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<UCollapsible v-model:open="detailsOpen">
			<template #content>
				<div class="grid gap-px border-t border-default md:grid-cols-2 xl:grid-cols-5">
					<div class="bg-background px-4 py-4">
						<p class="text-xs uppercase tracking-[0.22em] text-muted">
							{{ t("groups.activities.create.summary.difficulty") }}
						</p>
						<p class="mt-2 font-semibold text-toned">
							{{ difficultyLabel }}
						</p>
					</div>

					<div class="bg-background px-4 py-4">
						<p class="text-xs uppercase tracking-[0.22em] text-muted">
							{{ t("groups.activities.create.summary.run_style") }}
						</p>
						<p class="mt-2 font-semibold text-toned">
							{{ runStyleLabel }}
						</p>
					</div>

					<div class="bg-background px-4 py-4">
						<p class="text-xs uppercase tracking-[0.22em] text-muted">
							{{ t("groups.activities.create.summary.intensity") }}
						</p>
						<p class="mt-2 font-semibold text-toned">
							{{ intensityLabel }}
						</p>
					</div>

					<div class="bg-background px-4 py-4">
						<p class="text-xs uppercase tracking-[0.22em] text-muted">
							{{ t("groups.activities.create.summary.min_item_level") }}
						</p>
						<p class="mt-2 font-semibold text-toned">
							{{ minimumItemLevelLabel }}
						</p>
						<p class="mt-1 text-sm text-muted">
							{{ t("groups.activities.create.summary.beginner_friendly") }}: {{ beginnerFriendlyLabel }}
						</p>
					</div>
				</div>

				<div class="grid gap-px border-t border-default md:grid-cols-2 xl:grid-cols-5">
					<div class="bg-background px-4 py-4">
						<p class="text-xs uppercase tracking-[0.22em] text-muted">
							{{ t("groups.activities.overview.details.description") }}
						</p>
						<p class="mt-2 break-words [overflow-wrap:anywhere] whitespace-pre-wrap text-sm text-toned">
							{{ description || t("groups.activities.overview.details.no_description") }}
						</p>
					</div>

					<div class="bg-background px-4 py-4">
						<p class="text-xs uppercase tracking-[0.22em] text-muted">
							{{ t("groups.activities.create.summary.notes") }}
						</p>
						<p class="mt-2 break-words [overflow-wrap:anywhere] whitespace-pre-wrap text-sm text-muted">
							{{ notes || t("groups.activities.create.summary.no_notes") }}
						</p>
					</div>

					<div class="bg-background px-4 py-4">
						<p class="text-xs uppercase tracking-[0.22em] text-muted">
							{{ t("groups.activities.overview.details.target_prog_point") }}
						</p>
						<p class="mt-2 font-semibold text-toned">
							{{ targetProgPointLabel }}
						</p>
					</div>

					<div class="bg-background px-4 py-4">
						<p class="text-xs uppercase tracking-[0.22em] text-muted">
							{{ t("groups.activities.create.summary.duration") }}
						</p>
						<p class="mt-2 font-semibold text-toned">
							{{ durationLabel }}
						</p>
					</div>

					<div
						v-if="detailMode === 'self_assignment'"
						class="bg-background px-4 py-4"
					>
						<p class="text-xs uppercase tracking-[0.22em] text-muted">
							{{ t("groups.activities.create.summary.assignment") }}
						</p>
						<p class="mt-2 font-semibold text-toned">
							{{ assignmentModeLabel }}
						</p>
					</div>

					<div v-else class="bg-background px-4 py-4">
						<p class="text-xs uppercase tracking-[0.22em] text-muted">
							{{ t("groups.activities.overview.details.pending_applications") }}
						</p>
						<p class="mt-2 font-semibold text-toned">
							{{ t("groups.activities.overview.meta.pending_applications", { count: pendingApplicationCount ?? 0 }) }}
						</p>
					</div>
				</div>
			</template>
		</UCollapsible>

		<div class="flex justify-end border-t border-default bg-background/70 px-3 py-2">
			<UButton
				color="neutral"
				variant="ghost"
				size="sm"
				:label="detailsOpen
					? t('groups.activities.overview.hide_details')
					: t('groups.activities.overview.show_details')"
				trailing-icon="i-lucide-chevron-down"
				:ui="{ trailingIcon: 'transition-transform duration-200' + (detailsOpen ? ' rotate-180' : '') }"
				@click="detailsOpen = !detailsOpen"
			/>
		</div>
	</section>
</template>
