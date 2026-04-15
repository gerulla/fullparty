<script setup lang="ts">
import AuditLogRow from "@/components/Audit/AuditLogRow.vue";
import PageHeader from "@/components/PageHeader.vue";
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps<{
	auditLogs: Array<any>
	filters: {
		actions: Array<{ value: string, label: string }>
		severities: Array<{ value: string, label: string }>
		users: Array<{ value: string, label: string }>
		groups: Array<{ value: string, label: string }>
	}
}>();

const { t } = useI18n();

type AuditLogRow = {
	id: number
	action: string
	severity: string
	scope: {
		type: string | null
		id: number | null
		label: string | null
	}
	actor: {
		id: number | null
		name: string
		avatar_url: string | null
		is_system: boolean
	}
	subject: {
		type: string | null
		id: number | null
		name: string
		avatar_url: string | null
		is_system: boolean
	}
	title: string
	changes: Array<{
		label: string
		old: string
		new: string
	}>
	details: Array<string>
	search_text: string
	created_at: string
}

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
		label: t(user.label),
	})),
]);

const groupOptions = computed(() => [
	{ label: t('audit_log.filters.any_group'), value: '__all__' },
	...props.filters.groups,
]);

const filters = ref({
	search: '',
	action: '__all__',
	severity: '__all__',
	user: '__all__',
	group: '__all__',
	beforeDate: '',
	afterDate: '',
});

const chunkSize = 20;
const visibleCount = ref(chunkSize);
const sentinel = useTemplateRef('sentinel');
let observer: IntersectionObserver | null = null;

const filteredRows = computed(() => {
	return props.auditLogs.filter((row) => {
		const searchTarget = row.search_text.toLowerCase();
		const search = filters.value.search.trim().toLowerCase();

		if (search && !searchTarget.includes(search)) {
			return false;
		}

		if (filters.value.action !== '__all__' && row.action !== filters.value.action) {
			return false;
		}

		if (filters.value.severity !== '__all__' && row.severity !== filters.value.severity) {
			return false;
		}

		const actorValue = row.actor.is_system ? '__system__' : String(row.actor.id);

		if (filters.value.user !== '__all__' && actorValue !== filters.value.user) {
			return false;
		}

		if (filters.value.group !== '__all__') {
			if (row.scope.type !== 'group' || String(row.scope.id) !== filters.value.group) {
				return false;
			}
		}

		if (filters.value.beforeDate && row.created_at.slice(0, 10) > filters.value.beforeDate) {
			return false;
		}

		if (filters.value.afterDate && row.created_at.slice(0, 10) < filters.value.afterDate) {
			return false;
		}

		return true;
	});
});

const visibleRows = computed(() => filteredRows.value.slice(0, visibleCount.value));

const loadMore = () => {
	if (visibleCount.value >= filteredRows.value.length) {
		return;
	}

	visibleCount.value = Math.min(visibleCount.value + chunkSize, filteredRows.value.length);
};

watch(filters, () => {
	visibleCount.value = chunkSize;
}, { deep: true });

onMounted(() => {
	observer = new IntersectionObserver((entries) => {
		if (entries[0]?.isIntersecting) {
			loadMore();
		}
	}, { rootMargin: '200px' });

	if (sentinel.value) {
		observer.observe(sentinel.value);
	}
});

onBeforeUnmount(() => {
	observer?.disconnect();
});
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
					v-for="row in visibleRows"
					:key="row.id"
					:row="row"
					show-scope
				/>

				<UCard v-if="filteredRows.length === 0" class="dark:bg-elevated/25">
					<div class="py-8 text-center text-sm text-muted">
						{{ t('audit_log.list.empty') }}
					</div>
				</UCard>

				<div
					v-if="visibleRows.length < filteredRows.length"
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
