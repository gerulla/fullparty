<script setup lang="ts">
import type { GroupDashboardGroup } from '@/Types/Groups'
import { useI18n } from 'vue-i18n'
import GroupDiscoveryBadge from '@/components/Groups/GroupDiscoveryBadge.vue'
import { useGroupInfo } from '@/composables/useGroupInfo'

const props = defineProps<{ group: GroupDashboardGroup }>()
const { t } = useI18n()
const { languagesText, locationText, activityWindowText, descriptionText, primaryBadges, tagBadges, resolveBadgeLabel } =
    useGroupInfo(() => props.group)
</script>

<template>
	<div class="w-full max-w-[27rem] h-full border border-white/10 bg-neutral-950/92 p-5 shadow-[0_22px_70px_rgba(0,0,0,0.5)] backdrop-blur-md">
		<div class="flex items-start gap-4">
			<div class="shrink-0 overflow-hidden border border-white/10 bg-neutral-900/90">
				<img
					v-if="group.profile_picture_url"
					:src="group.profile_picture_url"
					:alt="group.name"
					class="size-20 object-cover sm:size-24"
				>
				<div
					v-else
					class="flex size-20 items-center justify-center sm:size-24"
				>
					<UIcon name="i-lucide-users-round" class="size-8 text-white/55 sm:size-10" />
				</div>
			</div>

			<div class="min-w-0 flex-1">
				<div class="mt-1 flex flex-wrap items-center gap-3 text-sm text-white/74">
					<span class="inline-flex items-center gap-1.5">
						<UIcon name="i-lucide-map-pinned" class="size-4" />
						<span class="break-words [overflow-wrap:anywhere]">{{ locationText }}</span>
					</span>
					<span
						v-if="languagesText"
						class="inline-flex items-center gap-1.5"
					>
						<UIcon name="i-lucide-languages" class="size-4" />
						<span class="break-words [overflow-wrap:anywhere]">{{ languagesText }}</span>
					</span>
				</div>
			</div>
		</div>

		<p class="mt-5 max-h-18 overflow-y-scroll text-sm leading-7 text-white/84 break-words [overflow-wrap:anywhere]">
			{{ descriptionText }}
		</p>

		<div v-if="primaryBadges.length > 0" class="mt-5 flex flex-wrap gap-2">
			<GroupDiscoveryBadge
				v-for="badge in primaryBadges"
				:key="badge.value"
				:color="badge.color"
				:label="resolveBadgeLabel(badge.value)"
			/>
		</div>

		<div v-if="tagBadges.length > 0" class="mt-3 flex flex-wrap gap-2">
			<GroupDiscoveryBadge
				v-for="tag in tagBadges"
				:key="tag.value"
				:color="tag.color"
				:label="tag.label"
			/>
		</div>

		<div class="mt-5 grid gap-3 border-t border-white/10 pt-4 text-sm text-white/74">
			<div class="flex items-start gap-2">
						<UIcon name="i-lucide-user-round" class="mt-0.5 size-4 shrink-0" />
						<div class="min-w-0">
							<p class="text-[11px] uppercase tracking-[0.18em] text-white/50">
								{{ t("groups.common.labels.owner") }}
							</p>
					<p class="mt-1 text-white break-words [overflow-wrap:anywhere]">
						{{ group.owner.name || t("groups.dashboard.labels.not_available") }}
					</p>
				</div>
			</div>

			<div
				v-if="group.discord_invite_url"
					class="flex min-w-0 items-start gap-2"
				>
					<UIcon name="i-lucide-link-2" class="mt-0.5 size-4 shrink-0" />
					<div class="min-w-0 flex-1">
						<p class="text-[11px] uppercase tracking-[0.18em] text-white/50">
							{{ t("groups.common.labels.discord") }}
						</p>
					<a
						:href="group.discord_invite_url"
						target="_blank"
						rel="noopener noreferrer"
						class="mt-1 block w-full overflow-hidden text-ellipsis whitespace-nowrap text-white underline decoration-white/20 underline-offset-2 transition-colors hover:text-brand-300 hover:decoration-brand-300/50"
					>
						{{ group.discord_invite_url }}
					</a>
				</div>
			</div>

			<div
				v-if="activityWindowText"
					class="flex items-start gap-2"
				>
					<UIcon name="i-lucide-clock-3" class="mt-0.5 size-4 shrink-0" />
					<div class="min-w-0">
						<p class="text-[11px] uppercase tracking-[0.18em] text-white/50">
							{{ t("groups.common.labels.activity_window") }}
						</p>
					<p class="mt-1 break-words [overflow-wrap:anywhere] text-white">
						{{ activityWindowText }}
					</p>
				</div>
			</div>
		</div>
	</div>
</template>
