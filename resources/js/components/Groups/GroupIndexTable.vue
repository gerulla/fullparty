<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

const props = withDefaults(defineProps<{
	title: string
	subtitle: string
	groups: Array<any>
	paginated?: boolean
	pageSize?: number
	serverSidePagination?: boolean
	currentPage?: number
	total?: number
}>(), {
	paginated: false,
	pageSize: 10,
	serverSidePagination: false,
	currentPage: 1,
	total: 0,
});

const emit = defineEmits<{
	pageChange: [page: number]
}>();

const { t } = useI18n();
const page = ref(1);
const resolvedPage = computed(() => props.serverSidePagination ? props.currentPage : page.value);
const resolvedTotal = computed(() => props.serverSidePagination ? props.total : props.groups.length);
const shouldPaginate = computed(() => props.paginated && resolvedTotal.value > props.pageSize);

const visibleGroups = computed(() => {
	if (!shouldPaginate.value || props.serverSidePagination) {
		return props.groups;
	}

	const start = (resolvedPage.value - 1) * props.pageSize;

	return props.groups.slice(start, start + props.pageSize);
});

const roleBadge = (role: string | null | undefined) => {
	if (!role) {
		return null;
	}

	return {
		owner: {
			label: t('groups.index.roles.owner'),
			color: 'warning',
			icon: 'i-lucide-crown'
		},
		moderator: {
			label: t('groups.index.roles.moderator'),
			color: 'primary',
			icon: 'i-lucide-shield'
		},
		member: {
			label: t('groups.index.roles.member'),
			color: 'neutral',
			icon: 'i-lucide-user'
		}
	}[role] ?? null;
};

const groupVisibilityIcon = (group: any) => {
	return group.is_public ? 'i-lucide-globe' : 'i-lucide-lock';
};

const activityText = (value: string | null) => {
	if (!value) {
		return t('groups.index.table.no_activity');
	}

	const date = new Date(value);
	const now = new Date();
	const diffInSeconds = Math.round((date.getTime() - now.getTime()) / 1000);
	const absoluteSeconds = Math.abs(diffInSeconds);
	const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

	if (absoluteSeconds < 60) {
		return formatter.format(diffInSeconds, 'second');
	}

	if (absoluteSeconds < 3600) {
		return formatter.format(Math.round(diffInSeconds / 60), 'minute');
	}

	if (absoluteSeconds < 86400) {
		return formatter.format(Math.round(diffInSeconds / 3600), 'hour');
	}

	if (absoluteSeconds < 604800) {
		return formatter.format(Math.round(diffInSeconds / 86400), 'day');
	}

	return formatter.format(Math.round(diffInSeconds / 604800), 'week');
};

const onPageChange = (nextPage: number) => {
	if (props.serverSidePagination) {
		emit('pageChange', nextPage);

		return;
	}

	page.value = nextPage;
};

const goToDashboard = (group: any) => {
	router.visit(route('groups.dashboard', group.slug));
};

watch(() => props.currentPage, (value) => {
	if (props.serverSidePagination) {
		page.value = value;
	}
}, { immediate: true });

const sectionCount = computed(() => resolvedTotal.value);
const emptyState = computed(() => t('groups.index.table.empty'));

const statItems = (group: any) => ([
	{
		icon: 'i-lucide-users',
		value: group.stats.member_count,
		label: t('general.members'),
	},
	{
		icon: 'i-lucide-calendar-range',
		value: group.stats.upcoming_run_count,
		label: t('groups.index.table.columns.upcoming_runs'),
	},
	{
		icon: 'i-lucide-activity',
		value: activityText(group.stats.last_activity_at),
		label: t('groups.index.table.columns.last_activity'),
	},
]);
</script>

<template>
	<UCard class="w-full dark:bg-elevated/25" :ui="{ body: 'p-0 sm:p-0' }">
		<template #header>
			<div class="flex flex-row items-start justify-between gap-4">
				<div class="flex flex-col gap-1">
					<p class="font-semibold text-md">{{ title }}</p>
					<p class="text-sm text-muted">{{ subtitle }}</p>
				</div>
				<UBadge :label="sectionCount" color="neutral" variant="subtle" />
			</div>
		</template>

		<div class="flex flex-col">
			<div class="flex flex-col">
				<div
					v-for="(group, index) in visibleGroups"
					:key="group.id"
					class="group-row"
					:class="index === 0 ? '' : 'border-t border-default'"
					@click.stop="goToDashboard(group)"
				>
					<div class="flex flex-col gap-4 px-4 py-5 lg:flex-row lg:items-center lg:justify-between hover:bg-muted/50 cursor-pointer">
						<div class="flex min-w-0 flex-row items-start gap-3">
							<div v-if="group.profile_picture_url" class="h-12 w-12 shrink-0 overflow-hidden rounded-sm border border-default bg-muted/30">
								<img
									:src="group.profile_picture_url"
									:alt="`${group.name} profile picture`"
									class="h-full w-full object-cover"
								>
							</div>
							<div v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-sm border border-default bg-muted/20">
								<UIcon name="i-lucide-users" size="18" class="text-muted" />
							</div>

							<div class="min-w-0">
								<div class="flex items-center gap-2">
									<p
										class="font-semibold"
									>
										{{ group.name }}
									</p>
									<UBadge
										v-if="roleBadge(group.current_user_role)"
										:label="roleBadge(group.current_user_role)?.label"
										:color="roleBadge(group.current_user_role)?.color"
										:icon="roleBadge(group.current_user_role)?.icon"
										variant="subtle"
										size="sm"
									/>
									<UIcon :name="groupVisibilityIcon(group)" size="14" class="text-muted" />
								</div>
								<p class="mt-1 line-clamp-2 text-sm text-muted">
									{{ group.description || t('groups.index.table.no_description') }}
								</p>
							</div>
						</div>

						<div class="grid grid-cols-1 gap-4 sm:grid-cols-3 lg:min-w-[28rem] lg:gap-8">
							<div
								v-for="stat in statItems(group)"
								:key="stat.label"
								class="flex flex-col items-center gap-2 "
							>
								<div class="flex items-center justify-center gap-1">
									<UIcon :name="stat.icon" size="15" class="mt-0.5 text-muted" />
									<p class="font-medium text-toned">{{ stat.value }}</p>
								</div>
								<p class="text-xs text-muted">{{ stat.label }}</p>
							</div>
						</div>
					</div>
				</div>

				<div v-if="visibleGroups.length === 0" class="px-4 py-8 text-sm text-muted">
					{{ emptyState }}
				</div>
			</div>

			<div v-if="shouldPaginate" class="flex justify-end border-t border-default pt-4 px-4">
				<UPagination
					:page="resolvedPage"
					:items-per-page="pageSize"
					:total="resolvedTotal"
					@update:page="onPageChange"
				/>
			</div>
		</div>
	</UCard>
</template>

<style scoped>
.group-row:first-child {
	border-top: 0;
}
</style>
