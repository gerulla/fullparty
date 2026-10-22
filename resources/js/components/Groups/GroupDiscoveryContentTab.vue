<script setup lang="ts">
import type { GroupDiscoveryContentStatusCount, GroupDiscoveryDetailRecord } from "@/Types/Groups";
import { formatRelativeTime } from "@/utils/formatRelativeTime";
import { computed } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	group: GroupDiscoveryDetailRecord
}>();

const { locale, t } = useI18n();

const totalRuns = computed(() => props.group.content_summary.total_runs);

const statusItems = computed(() => props.group.content_summary.status_breakdown.map((item) => ({
	...item,
	label: t(`groups.index.discovery.detail.content.statuses.${item.status}`),
	width: resolveStatusWidth(item),
})));

function resolveStatusWidth(item: GroupDiscoveryContentStatusCount) {
	if (totalRuns.value <= 0) {
		return "0%";
	}

	return `${Math.max((item.count / totalRuns.value) * 100, item.count > 0 ? 6 : 0)}%`;
}

function resolveStatusClasses(status: GroupDiscoveryContentStatusCount["status"]) {
	switch (status) {
		case "draft":
			return "bg-sky-300/80";
		case "scheduled":
			return "bg-violet-300/80";
		case "active":
			return "bg-amber-300/85";
		case "complete":
			return "bg-emerald-300/85";
		case "cancelled":
			return "bg-rose-300/80";
	}
}

function formatCountLabel(count: number, singular: string, plural: string) {
	return count === 1 ? singular : plural;
}

function formatRunTime(value: string | null) {
	return formatRelativeTime(
		value,
		locale.value,
		t("notifications.ui.just_now"),
		t("groups.index.table.no_activity"),
	);
}
</script>

<template>
	<div class="space-y-6">
		<section class="space-y-3">
			<div class="flex items-end justify-between gap-3">
				<div>
					<p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-dimmed">
						{{ t('groups.index.discovery.detail.content.summary') }}
					</p>
					<p class="mt-1 text-sm text-muted">
						{{ t('groups.index.discovery.detail.content.summary_hint') }}
					</p>
				</div>
				<div class="text-right">
					<p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-dimmed">
						{{ t('groups.index.discovery.detail.content.total_public_runs') }}
					</p>
					<p class="mt-1 text-2xl font-semibold text-highlighted">
						{{ group.content_summary.total_runs }}
					</p>
				</div>
			</div>

			<div class="overflow-hidden rounded-xl border border-default bg-muted/18 dark:border-elevation-7 dark:bg-elevation-4">
				<div class="flex h-3 w-full overflow-hidden bg-elevated/80 dark:bg-elevation-6">
					<div
						v-for="item in statusItems"
						:key="item.status"
						class="h-full transition-[width]"
						:class="resolveStatusClasses(item.status)"
						:style="{ width: item.width }"
					/>
				</div>

				<div class="flex flex-row flex-wrap gap-x-5 gap-y-3 px-4 py-4">
					<div
						v-for="item in statusItems"
						:key="item.status"
						class="flex min-w-32 items-center gap-3"
					>
						<span
							class="size-2.5 shrink-0 rounded-full"
							:class="resolveStatusClasses(item.status)"
						/>
						<div class="min-w-0">
							<p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-dimmed">
								{{ item.label }}
							</p>
							<p class="mt-1 text-sm text-toned">
								{{ item.count }}
							</p>
						</div>
					</div>
				</div>
			</div>
		</section>

		<div class="h-px bg-default/80 dark:bg-elevation-6" />

		<section class="space-y-3">
			<div class="flex items-end justify-between gap-3">
				<p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-dimmed">
					{{ t('groups.index.discovery.detail.content.library') }}
				</p>
				<p class="text-xs text-muted">
					{{ t('groups.index.discovery.detail.content.library_hint') }}
				</p>
			</div>

			<div v-if="group.content_items.length > 0" class="space-y-3">
				<div
					v-for="item in group.content_items"
					:key="item.key"
					class="flex items-center gap-4 rounded-xl border border-default bg-muted/18 dark:border-elevation-7 dark:bg-elevation-4 px-4 py-4"
				>
					<div class="relative h-20 w-32 shrink-0 overflow-hidden rounded-lg border border-default bg-elevated dark:border-elevation-7 dark:bg-elevation-5">
						<img
							v-if="item.activity_image_url"
							:src="item.activity_image_url"
							:alt="item.activity_name"
							class="h-full w-full object-cover"
						>
						<div
							v-else
							class="flex h-full w-full items-center justify-center bg-gradient-to-br from-neutral-800 to-neutral-950 text-dimmed"
						>
							<UIcon name="i-lucide-swords" class="size-6" />
						</div>
						<div class="pointer-events-none absolute inset-0 bg-gradient-to-r from-black/10 via-transparent to-black/30" />
					</div>

					<div class="min-w-0 flex-1">
						<div class="flex flex-wrap items-center gap-x-3 gap-y-1">
							<p class="text-sm font-semibold text-highlighted break-words [overflow-wrap:anywhere]">
								{{ item.activity_name }}
							</p>
							<span class="text-xs uppercase tracking-[0.12em] text-dimmed">
								{{ item.total_runs }} {{ formatCountLabel(item.total_runs, t('groups.index.discovery.detail.content.run_singular'), t('groups.index.discovery.detail.content.run_plural')) }}
							</span>
						</div>

						<div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm text-toned">
							<span>
								{{ t('groups.index.discovery.detail.content.completed') }}: {{ item.completed_runs }}
							</span>
							<span>
								{{ t('groups.index.discovery.detail.content.active') }}: {{ item.active_runs }}
							</span>
							<span v-if="item.last_run_at">
								{{ t('groups.index.discovery.detail.content.last_run') }}: {{ formatRunTime(item.last_run_at) }}
							</span>
							<span v-if="item.next_run_at">
								{{ t('groups.index.discovery.detail.content.next_run') }}: {{ formatRunTime(item.next_run_at) }}
							</span>
						</div>
					</div>
				</div>
			</div>

			<p v-else class="text-sm text-muted">
				{{ t('groups.index.discovery.detail.content.empty') }}
			</p>
		</section>
	</div>
</template>
