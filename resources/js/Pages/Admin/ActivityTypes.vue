<script setup lang="ts">
import PageHeader from "@/components/PageHeader.vue";
import { router, usePage } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import { useI18n } from "vue-i18n";
import { localizedValue } from "@/utils/localizedValue";
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { route } from "ziggy-js";

type ActivityTypeIndexMeta = {
	current_page: number
	last_page: number
	per_page: number
	total: number
	from: number | null
	to: number | null
}

const props = defineProps<{
	activityTypes: {
		data: Array<any>
		meta: ActivityTypeIndexMeta
	}
	filters: {
		search: string | null
	}
	schemaReference: {
		supportedFieldTypes: string[]
		supportedOptionSources: string[]
	}
}>();

const { t, locale } = useI18n();
const page = usePage();
const toast = useToast();
const search = ref(props.filters.search ?? '');
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

const activityTypeItems = computed(() => props.activityTypes.data);
const activityTypeMeta = computed(() => props.activityTypes.meta);
const hasSearch = computed(() => search.value.trim().length > 0);
const shouldPaginate = computed(() => activityTypeMeta.value.total > activityTypeMeta.value.per_page);
const emptyStateText = computed(() => hasSearch.value
	? t('admin.activity_types.empty_filtered')
	: t('admin.activity_types.empty'));
const resultSummary = computed(() => {
	if (activityTypeMeta.value.total === 0) {
		return t('admin.activity_types.pagination_empty');
	}

	return t('admin.activity_types.pagination_summary', {
		from: activityTypeMeta.value.from,
		to: activityTypeMeta.value.to,
		total: activityTypeMeta.value.total,
	});
});

const visitIndex = (pageNumber = 1) => {
	const params: Record<string, string | number> = {};
	const normalizedSearch = search.value.trim();

	if (normalizedSearch !== '') {
		params.search = normalizedSearch;
	}

	if (pageNumber > 1) {
		params.page = pageNumber;
	}

	router.get(route('admin.activity-types.index'), params, {
		preserveState: true,
		replace: true,
		only: ['activityTypes', 'filters'],
	});
};

watch(
	() => page.props.flash?.success,
	(success) => {
		if (!success) {
			return;
		}

		if (success.includes('activity_type_created')) {
			toast.add({
				title: t('general.success'),
				description: t('admin.activity_types.toasts.created'),
				color: 'success',
				icon: 'i-lucide-check',
			});
		}

		if (success.includes('activity_type_updated')) {
			toast.add({
				title: t('general.success'),
				description: t('admin.activity_types.toasts.updated'),
				color: 'success',
				icon: 'i-lucide-check',
			});
		}

		if (success.includes('activity_type_published')) {
			toast.add({
				title: t('general.success'),
				description: t('admin.activity_types.toasts.published'),
				color: 'success',
				icon: 'i-lucide-upload',
			});
		}

		if (success.includes('activity_type_cloned')) {
			toast.add({
				title: t('general.success'),
				description: t('admin.activity_types.toasts.cloned'),
				color: 'success',
				icon: 'i-lucide-copy',
			});
		}
	},
	{ immediate: true }
);

watch(
	() => props.filters.search,
	(value) => {
		const normalizedValue = value ?? '';

		if (normalizedValue !== search.value) {
			search.value = normalizedValue;
		}
	}
);

watch(search, () => {
	if (searchTimeout) {
		clearTimeout(searchTimeout);
	}

	searchTimeout = setTimeout(() => visitIndex(1), 300);
});

onBeforeUnmount(() => {
	if (searchTimeout) {
		clearTimeout(searchTimeout);
	}
});

const destroyActivityType = (activityTypeId: number) => {
	if (!window.confirm(t('admin.activity_types.delete_confirm'))) {
		return;
	}

	router.delete(route('admin.activity-types.destroy', activityTypeId));
};

const goToCreatePage = () => {
	router.get(route('admin.activity-types.create'));
};

const goToEditPage = (activityTypeId: number) => {
	router.get(route('admin.activity-types.edit', activityTypeId));
};

const publishActivityType = (activityTypeId: number) => {
	router.post(route('admin.activity-types.publish', activityTypeId), {});
};

const cloneActivityType = (activityTypeId: number) => {
	router.post(route('admin.activity-types.clone', activityTypeId), {});
};

const clearSearch = () => {
	search.value = '';
};
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('admin.activity_types.title')"
			:subtitle="t('admin.activity_types.subtitle')"
		>
			<UButton
				color="neutral"
				class="w-full cursor-pointer rounded-none"
				icon="i-lucide-plus"
				:label="t('admin.activity_types.create')"
				@click.stop="goToCreatePage"
			/>
		</PageHeader>

		<UCard class="mt-6 dark:bg-elevated/25">
			<div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
				<div class="flex min-w-0 flex-1 flex-col gap-1">
					<UInput
						v-model="search"
						icon="i-lucide-search"
						:placeholder="t('admin.activity_types.search_placeholder')"
						class="w-full md:max-w-xl"
					/>
					<p class="text-sm text-muted">{{ resultSummary }}</p>
				</div>

				<UButton
					v-if="hasSearch"
					color="neutral"
					variant="soft"
					icon="i-lucide-x"
					:label="t('admin.activity_types.clear_search')"
					@click="clearSearch"
				/>
			</div>
		</UCard>

		<div class="mt-4 flex flex-col gap-4">
			<UCard
				v-for="activityType in activityTypeItems"
				:key="activityType.id"
				class="dark:bg-elevated/25"
			>
				<div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
					<div class="flex flex-col gap-2">
						<div class="flex flex-wrap items-center gap-3">
							<h2 class="text-lg font-semibold text-highlighted">
								{{ localizedValue(activityType.draft_name, locale) || activityType.slug }}
							</h2>
							<UBadge
								:color="activityType.is_active ? 'success' : 'neutral'"
								variant="subtle"
								:label="activityType.is_active ? t('admin.activity_types.status_active') : t('admin.activity_types.status_inactive')"
							/>
							<UBadge
								v-if="activityType.current_published_version"
								color="primary"
								variant="subtle"
								:label="t('admin.activity_types.version_badge', { version: activityType.current_published_version.version })"
							/>
						</div>

						<p class="text-sm text-muted">
							{{ activityType.slug }}
						</p>

						<p class="text-sm text-toned">
							{{ localizedValue(activityType.draft_description, locale) || t('admin.activity_types.no_description') }}
						</p>
					</div>

					<div class="flex items-center gap-2">
						<UButton
							color="primary"
							variant="soft"
							icon="i-lucide-upload"
							:label="t('admin.activity_types.publish')"
							@click="publishActivityType(activityType.id)"
						/>

						<UButton
							color="neutral"
							variant="soft"
							icon="i-lucide-copy"
							:label="t('admin.activity_types.clone')"
							@click="cloneActivityType(activityType.id)"
						/>

						<UButton
							color="neutral"
							variant="soft"
							icon="i-lucide-pencil"
							:label="t('general.edit')"
							@click="goToEditPage(activityType.id)"
						/>

						<UButton
							color="error"
							variant="soft"
							icon="i-lucide-trash-2"
							:label="t('general.delete')"
							@click="destroyActivityType(activityType.id)"
						/>
					</div>
				</div>
			</UCard>

			<UCard v-if="activityTypeItems.length === 0" class="dark:bg-elevated/25">
				<div class="py-8 text-center text-sm text-muted">
					{{ emptyStateText }}
				</div>
			</UCard>

			<div v-if="shouldPaginate" class="flex justify-end">
				<UPagination
					:page="activityTypeMeta.current_page"
					:items-per-page="activityTypeMeta.per_page"
					:total="activityTypeMeta.total"
					@update:page="visitIndex"
				/>
			</div>
		</div>
	</div>
</template>
