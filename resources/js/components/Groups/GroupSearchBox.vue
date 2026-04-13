<script setup lang="ts">
import GroupIndexTable from "@/components/Groups/GroupIndexTable.vue";
import { route } from "ziggy-js";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

type PaginatedGroups = {
	data: Array<any>
	meta: {
		current_page: number
		last_page: number
		per_page: number
		total: number
	}
}

const emit = defineEmits<{
	searchStarted: [value: boolean]
}>();

const { t } = useI18n();

const query = ref('');
const isLoading = ref(false);
const results = ref<PaginatedGroups>({
	data: [],
	meta: {
		current_page: 1,
		last_page: 1,
		per_page: 10,
		total: 0,
	},
});

let searchTimeout: ReturnType<typeof setTimeout> | null = null;
let activeRequestId = 0;

const hasQuery = computed(() => query.value.trim().length > 0);
const searchSubtitle = computed(() => {
	if (isLoading.value) {
		return t('groups.index.search.loading');
	}

	return t('groups.index.search.subtitle', {
		count: results.value.meta.total,
	});
});

const clearSearch = () => {
	activeRequestId += 1;
	query.value = '';
	results.value = {
		data: [],
		meta: {
			current_page: 1,
			last_page: 1,
			per_page: 10,
			total: 0,
		},
	};
};

const fetchResults = async (page = 1) => {
	const normalizedQuery = query.value.trim();

	if (!normalizedQuery) {
		activeRequestId += 1;
		results.value = {
			data: [],
			meta: {
				current_page: 1,
				last_page: 1,
				per_page: 10,
				total: 0,
			},
		};

		return;
	}

	isLoading.value = true;
	const requestId = ++activeRequestId;

	try {
		const response = await window.fetch(route('groups.search', {
			query: normalizedQuery,
			page,
		}), {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				'Accept': 'application/json',
			},
		});

		if (!response.ok) {
			throw new Error('Failed to fetch group search results.');
		}

		const payload = await response.json();

		if (requestId === activeRequestId) {
			results.value = payload;
		}
	} finally {
		if (requestId === activeRequestId) {
			isLoading.value = false;
		}
	}
};

watch(hasQuery, (value) => {
	emit('searchStarted', value);
}, { immediate: true });

watch(query, () => {
	if (searchTimeout) {
		clearTimeout(searchTimeout);
	}

	searchTimeout = setTimeout(() => {
		void fetchResults(1);
	}, 250);
});
</script>

<template>
	<div class="w-full flex flex-col gap-6">
		<UInput
			v-model="query"
			:placeholder="t('groups.index.search.placeholder')"
			icon="i-lucide-search"
			size="xl"
			class="w-full"
			:loading="isLoading"
			:ui="{
				base: 'w-full',
				trailing: 'pe-1',
			}"
		>
			<template v-if="hasQuery && !isLoading" #trailing>
				<UButton
					color="neutral"
					variant="ghost"
					size="sm"
					icon="i-lucide-x"
					:aria-label="t('groups.index.search.clear')"
					@click="clearSearch"
				/>
			</template>
		</UInput>

		<GroupIndexTable
			v-if="hasQuery"
			:title="t('groups.index.search.title')"
			:subtitle="searchSubtitle"
			:groups="results.data"
			paginated
			server-side-pagination
			:page-size="results.meta.per_page"
			:current-page="results.meta.current_page"
			:total="results.meta.total"
			@page-change="fetchResults"
		/>
	</div>
</template>
