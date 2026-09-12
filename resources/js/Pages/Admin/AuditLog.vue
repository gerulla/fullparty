<script setup lang="ts">
import type { AuditLogFilters, AuditLogFilterOptions, AuditLogRowRecord } from "@/Types/Audit";
import AuditLogRow from "@/components/Audit/AuditLogRow.vue";
import PageHeader from "@/components/PageHeader.vue";
import { useAuditLogFeed } from "@/composables/useAuditLogFeed";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { route } from "ziggy-js";

const props = defineProps<{
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
	...props.filters.users.map((user) => ({
		value: user.value,
		label: user.label,
	})),
]);

const groupOptions = computed(() => [
	{ label: t('audit_log.filters.any_group'), value: '__all__' },
	...(props.filters.groups ?? []),
]);

const { filters, rows, hasMore, loading, failed, sentinel, loadMore, retry } = useAuditLogFeed(
	() => route('admin.audit-log'), () => props,
);
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('audit_log.admin.title')"
			:subtitle="t('audit_log.admin.subtitle')"
		>
			<UBadge
				size="lg"
				variant="subtle"
				class="min-w-44 justify-center py-2"
				color="error"
				icon="i-lucide-shield-alert"
				:label="t('audit_log.admin.access')"
			/>
		</PageHeader>

		<div class="mt-4 flex flex-col gap-6">
			<UCard class="dark:bg-elevated/25">
				<div class="grid grid-cols-1 gap-4 xl:grid-cols-[1.45fr_repeat(4,minmax(0,1fr))_minmax(0,0.8fr)_minmax(0,0.8fr)]">
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
					<USelect
						v-model="filters.group"
						:items="groupOptions"
						value-key="value"
						:placeholder="t('audit_log.filters.group.label')"
					/>
					<div class="space-y-1">
						<label class="text-xs font-medium text-muted">{{ t('audit_log.filters.after_date.label') }}</label>
						<UInput v-model="filters.afterDate" type="date" :placeholder="t('audit_log.filters.after_date.placeholder')" />
					</div>
					<div class="space-y-1">
						<label class="text-xs font-medium text-muted">{{ t('audit_log.filters.before_date.label') }}</label>
						<UInput v-model="filters.beforeDate" type="date" :placeholder="t('audit_log.filters.before_date.placeholder')" />
					</div>
				</div>
			</UCard>

			<div class="flex flex-col gap-4">
				<AuditLogRow
					v-for="row in rows"
					:key="row.id"
					:row="row"
					show-scope
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
