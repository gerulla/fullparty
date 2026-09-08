<script setup lang="ts">
import type { AuditLogFilters, AuditLogFilterOptions, AuditLogRowRecord } from "@/Types/Audit";
import AuditLogRow from "@/components/Audit/AuditLogRow.vue";
import AccessBadge from "@/components/Groups/AccessBadge.vue";
import PageHeader from "@/components/PageHeader.vue";
import { useAuditLogFeed } from "@/composables/useAuditLogFeed";
import { useTimeDisplayMode } from "@/composables/useTimeDisplayMode";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { route } from "ziggy-js";

const props = defineProps<{
	group: any
	auditLogs: AuditLogRowRecord[]
	nextCursor: string | null
	selectedFilters?: Partial<AuditLogFilters>
	filters: AuditLogFilterOptions
}>();

const { t } = useI18n();

const actionOptions = computed(() => [
	{ label: t('audit_log.filters.any_action'), value: '__all__' },
	...props.filters.actions.map((action) => ({
		value: action.value,
		label: t(action.label),
	})),
]);

const severityOptions = computed(() => [
	{ label: t('audit_log.filters.any_severity'), value: '__all__' },
	...props.filters.severities.map((severity) => ({
		value: severity.value,
		label: t(severity.label),
	})),
]);

const userOptions = computed(() => [
	{ label: t('audit_log.filters.any_user'), value: '__all__' },
	...props.filters.users,
]);

const { withDisplayTimeZone } = useTimeDisplayMode();
const activityOptions = computed(() => {
	const formatter = new Intl.DateTimeFormat('en-GB', withDisplayTimeZone({
		day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
	}));
	return [
		{ value: '__all__', label: t('audit_log.filters.any_activity') },
		...(props.filters.activities ?? []).map((activity) => {
			const title = activity.title || t('audit_log.filters.unnamed_activity', { id: activity.value });
			if (!activity.starts_at) return { value: activity.value, label: title };
			const parts = Object.fromEntries(formatter.formatToParts(new Date(activity.starts_at))
				.map(({ type, value }) => [type, value]));
			return {
				value: activity.value,
				label: `${parts.day}-${parts.month}-${parts.year} - ${title} - ${parts.hour}:${parts.minute}`,
			};
		}),
	];
});

const { filters, rows, hasMore, loading, failed, sentinel, loadMore, retry } = useAuditLogFeed(
	() => route('groups.dashboard.audit-log', { group: props.group.slug }), () => props,
);
const filtersOpen = ref(false);

const activeFilterCount = computed(() => [
	filters.value.search.trim(),
	filters.value.action !== '__all__',
	filters.value.severity !== '__all__',
	filters.value.user !== '__all__',
	filters.value.activity !== '__all__',
	filters.value.beforeDate,
	filters.value.afterDate,
].filter(Boolean).length);

</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('audit_log.group.title')"
			:subtitle="t('audit_log.group.subtitle')"
		>
			<AccessBadge
				:role="group.current_user_role"
				fallback-role="moderator"
			/>
		</PageHeader>

		<div class="mt-4 flex flex-col gap-6">
			<UCard class="dark:bg-elevated/25 xl:hidden">
				<UCollapsible
					v-model:open="filtersOpen"
					class="flex flex-col gap-4"
				>
					<div class="flex items-center justify-between gap-3">
						<div class="min-w-0">
							<p class="font-semibold text-toned">
								{{ t('audit_log.filters.title') }}
							</p>
						</div>

						<div class="flex shrink-0 items-center gap-2">
							<UBadge
								v-if="activeFilterCount > 0"
								color="primary"
								variant="subtle"
								:label="String(activeFilterCount)"
							/>
							<UButton
								color="neutral"
								variant="ghost"
								size="sm"
								:icon="filtersOpen ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
								:aria-label="t('audit_log.filters.title')"
								@click="filtersOpen = !filtersOpen"
							/>
						</div>
					</div>

					<template #content>
						<div class="grid grid-cols-1 gap-4 border-t border-default pt-4">
							<USelectMenu
								v-model="filters.activity"
								:items="activityOptions"
								value-key="value"
								icon="i-lucide-calendar-days"
								class="w-full min-w-0"
								:aria-label="t('audit_log.filters.activity_label')"
								:search-input="{ placeholder: t('audit_log.filters.search_activity') }"
								:ui="{ itemLabel: 'whitespace-normal break-words' }"
							/>
							<UInput
								v-model="filters.search"
								icon="i-lucide-search"
								class="w-full"
								:placeholder="t('audit_log.filters.search_placeholder')"
							/>
							<USelect
								v-model="filters.action"
								:items="actionOptions"
								value-key="value"
								class="w-full"
								:placeholder="t('audit_log.filters.action.label')"
							/>
							<USelect
								v-model="filters.severity"
								:items="severityOptions"
								value-key="value"
								class="w-full"
								:placeholder="t('audit_log.filters.severity.label')"
							/>
							<USelect
								v-model="filters.user"
								:items="userOptions"
								value-key="value"
								class="w-full"
								:placeholder="t('audit_log.filters.user.label')"
							/>
							<div class="space-y-1">
								<label class="text-xs font-medium text-muted">{{ t('audit_log.filters.after_date.label') }}</label>
								<UInput
									v-model="filters.afterDate"
									type="date"
									class="w-full"
									:placeholder="t('audit_log.filters.after_date.placeholder')"
								/>
							</div>
							<div class="space-y-1">
								<label class="text-xs font-medium text-muted">{{ t('audit_log.filters.before_date.label') }}</label>
								<UInput
									v-model="filters.beforeDate"
									type="date"
									class="w-full"
									:placeholder="t('audit_log.filters.before_date.placeholder')"
								/>
							</div>
						</div>
					</template>
				</UCollapsible>
			</UCard>

			<UCard class="hidden dark:bg-elevated/25 xl:block">
				<USelectMenu
					v-model="filters.activity"
					:items="activityOptions"
					value-key="value"
					icon="i-lucide-calendar-days"
					class="mb-4 w-full max-w-2xl min-w-0"
					:aria-label="t('audit_log.filters.activity_label')"
					:search-input="{ placeholder: t('audit_log.filters.search_activity') }"
					:ui="{ itemLabel: 'whitespace-normal break-words' }"
				/>
				<div class="grid grid-cols-1 gap-4 xl:grid-cols-[1.45fr_repeat(3,minmax(0,1fr))_minmax(0,0.8fr)_minmax(0,0.8fr)]">
					<UInput
						v-model="filters.search"
						icon="i-lucide-search"
						:placeholder="t('audit_log.filters.search_placeholder')"
					/>
					<USelect
						v-model="filters.action"
						:items="actionOptions"
						value-key="value"
						:placeholder="t('audit_log.filters.action.label')"
					/>
					<USelect
						v-model="filters.severity"
						:items="severityOptions"
						value-key="value"
						:placeholder="t('audit_log.filters.severity.label')"
					/>
					<USelect
						v-model="filters.user"
						:items="userOptions"
						value-key="value"
						:placeholder="t('audit_log.filters.user.label')"
					/>
					<div class="space-y-1">
						<label class="text-xs font-medium text-muted">{{ t('audit_log.filters.after_date.label') }}</label>
						<UInput
							v-model="filters.afterDate"
							type="date"
							:placeholder="t('audit_log.filters.after_date.placeholder')"
						/>
					</div>
					<div class="space-y-1">
						<label class="text-xs font-medium text-muted">{{ t('audit_log.filters.before_date.label') }}</label>
						<UInput
							v-model="filters.beforeDate"
							type="date"
							:placeholder="t('audit_log.filters.before_date.placeholder')"
						/>
					</div>
				</div>
			</UCard>

			<div class="flex flex-col gap-4">
				<AuditLogRow
					v-for="row in rows"
					:key="row.id"
					:row="row"
				/>

				<UCard v-if="rows.length === 0 && !loading && !failed" class="dark:bg-elevated/25">
					<div class="py-8 text-center text-sm text-muted">
						{{ t('audit_log.list.empty') }}
					</div>
				</UCard>

				<div v-if="loading" role="status" class="flex items-center justify-center gap-2 py-4 text-sm text-muted">
					<UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
					{{ t('audit_log.list.loading') }}
				</div>
				<div v-if="failed" role="alert" class="flex flex-wrap items-center justify-center gap-3 py-4 text-sm text-error">
					{{ t('audit_log.list.load_error') }}
					<UButton icon="i-lucide-refresh-cw" color="neutral" variant="ghost" :label="t('audit_log.list.retry')" @click="retry" />
				</div>
				<div
					v-if="hasMore && !loading && !failed"
					ref="sentinel"
					class="flex justify-center py-4"
				>
					<UButton
						color="neutral"
						variant="ghost"
						icon="i-lucide-loader-circle"
						:label="t('audit_log.list.loading_more')"
						@click="loadMore"
					/>
				</div>
			</div>
		</div>
	</div>
</template>

<style scoped>

</style>
