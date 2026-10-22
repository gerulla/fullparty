<script setup lang="ts">
import type { GroupDiscoveryDetailRecord } from "@/Types/Groups";
import { computed } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	group: GroupDiscoveryDetailRecord
}>();

const { t } = useI18n();

const ownerMember = computed(() => props.group.team_members.find((member) => member.role === "owner") ?? null);
const adminMembers = computed(() => props.group.team_members.filter((member) => member.role === "admin"));
const moderatorMembers = computed(() => props.group.team_members.filter((member) => member.role === "moderator"));

function roleLabel(role: "owner" | "admin" | "moderator") {
	return t(`groups.common.roles.${role}`);
}
</script>

<template>
	<div class="space-y-6">
		<section class="space-y-3">
			<div class="flex items-end justify-between gap-3">
				<div>
					<p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-dimmed">
						{{ t('groups.index.discovery.detail.team.core_leadership') }}
					</p>
					<p class="mt-1 text-sm text-muted">
						{{ t('groups.index.discovery.detail.team.core_leadership_hint') }}
					</p>
				</div>
			</div>

			<div v-if="ownerMember || adminMembers.length > 0" class="space-y-3">
				<div
					v-if="ownerMember"
					class="rounded-xl border border-default bg-muted/18 dark:border-elevation-7 dark:bg-elevation-4 px-4 py-4"
				>
					<div class="flex flex-wrap items-start justify-between gap-4">
						<div class="min-w-0 flex-1">
						<UUser
							:name="ownerMember.name ?? '—'"
							size="xl"
							:avatar="ownerMember.avatar_url ? { src: ownerMember.avatar_url } : undefined"
						/>
					</div>

						<div class="shrink-0 text-left sm:text-right">
							<p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-dimmed">
								{{ t('groups.common.labels.role') }}
							</p>
							<p class="mt-1 text-sm font-medium text-highlighted">
								{{ roleLabel(ownerMember.role) }}
							</p>
						</div>
					</div>
				</div>

				<div
					v-for="member in adminMembers"
					:key="member.id"
					class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-default bg-muted/18 dark:border-elevation-7 dark:bg-elevation-4 px-4 py-4"
				>
					<div class="min-w-0 flex-1">
						<UUser
							:name="member.name ?? '—'"
							size="lg"
							:avatar="member.avatar_url ? { src: member.avatar_url } : undefined"
						/>
					</div>

					<div class="shrink-0 text-left sm:text-right">
						<p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-dimmed">
							{{ t('groups.common.labels.role') }}
						</p>
						<p class="mt-1 text-sm text-toned">
							{{ roleLabel(member.role) }}
						</p>
					</div>
				</div>
			</div>
		</section>

		<div class="h-px bg-default/80 dark:bg-elevation-6" />

		<section class="space-y-3">
			<div class="flex items-end justify-between gap-3">
				<div>
					<p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-dimmed">
						{{ t('groups.index.discovery.detail.team.moderation_team') }}
					</p>
					<p class="mt-1 text-sm text-muted">
						{{ t('groups.index.discovery.detail.team.moderation_hint') }}
					</p>
				</div>
				<p class="text-sm text-toned">
					{{ moderatorMembers.length }}
				</p>
			</div>

			<div v-if="moderatorMembers.length > 0" class="space-y-3">
				<div
					v-for="member in moderatorMembers"
					:key="member.id"
					class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-default bg-muted/18 dark:border-elevation-7 dark:bg-elevation-4 px-4 py-4"
				>
					<div class="min-w-0 flex-1">
						<UUser
							:name="member.name ?? '—'"
							size="lg"
							:avatar="member.avatar_url ? { src: member.avatar_url } : undefined"
						/>
					</div>

					<div class="shrink-0 text-left sm:text-right">
						<p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-dimmed">
							{{ t('groups.common.labels.role') }}
						</p>
						<p class="mt-1 text-sm text-toned">
							{{ roleLabel(member.role) }}
						</p>
					</div>
				</div>
			</div>

			<p v-else class="text-sm text-muted">
				{{ t('groups.index.discovery.detail.team.no_moderation') }}
			</p>
		</section>
	</div>
</template>
