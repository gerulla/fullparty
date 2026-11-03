import type { AuditLogFeedPage, AuditLogFilterOptions, AuditLogFilters } from '@/Types/Audit';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

export function useAuditLogFeed(
    endpoint: () => string,
    initial: () => AuditLogFeedPage | undefined = () => undefined,
    defaults: Partial<AuditLogFilters> = {},
) {
    const defaultFilters: AuditLogFilters = {
        search: '', action: '__all__', severity: '__all__', user: '__all__',
        group: '__all__', activity: '__all__', beforeDate: '', afterDate: '',
        category: '__all__', timezone: '', ...defaults,
    };
    const lazy = initial() === undefined;
    const filterOptions = ref<AuditLogFilterOptions | undefined>(initial()?.filters);
    const filters = ref<AuditLogFilters>({ ...defaultFilters, ...initial()?.selectedFilters });
    const rows = ref([...(initial()?.auditLogs ?? [])]);
    const nextCursor = ref(initial()?.nextCursor ?? null);
    const loading = ref(lazy);
    const failed = ref(false);
    const sentinel = ref<HTMLElement | null>(null);
    const hasMore = computed(() => nextCursor.value !== null);
    let controller: AbortController | null = null;
    let observer: IntersectionObserver | null = null;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let requestId = 0;
    let syncingProps = false;

    async function fetchPage(append: boolean) {
        const id = ++requestId;
        controller?.abort();
        controller = new AbortController();
        loading.value = true;
        failed.value = false;
        const params = Object.fromEntries(Object.entries(filters.value)
            .filter(([, value]) => value && value !== '__all__'));
        if (append && nextCursor.value) params.cursor = nextCursor.value;
        if (lazy && !filterOptions.value) params.include_options = '1';

        try {
            const { data } = await axios.get<AuditLogFeedPage>(endpoint(), {
                params, signal: controller.signal, headers: { Accept: 'application/json' },
            });
            if (id !== requestId) return;
            const existingIds = new Set(append ? rows.value.map((row) => row.id) : []);
            rows.value = [...(append ? rows.value : []), ...data.auditLogs.filter((row) => !existingIds.has(row.id))];
            nextCursor.value = data.nextCursor;
            if (data.filters) filterOptions.value = data.filters;
        } catch (error) {
            if (id === requestId && !axios.isCancel(error)) failed.value = true;
        } finally {
            if (id === requestId) loading.value = false;
        }
    }

    function loadMore() {
        if (!loading.value && hasMore.value) void fetchPage(true);
    }

    function retry() {
        if (!loading.value) void fetchPage(rows.value.length > 0);
    }

    watch(filters, () => {
        if (syncingProps) return;
        ++requestId;
        controller?.abort();
        clearTimeout(timer);
        rows.value = [];
        nextCursor.value = null;
        failed.value = false;
        loading.value = true;
        timer = setTimeout(() => void fetchPage(false), 250);
    }, { deep: true, flush: 'sync' });

    watch(() => initial()?.auditLogs, (logs) => {
        if (!logs) return;
        ++requestId;
        controller?.abort();
        clearTimeout(timer);
        syncingProps = true;
        filters.value = { ...defaultFilters, ...initial()?.selectedFilters };
        syncingProps = false;
        rows.value = [...logs];
        nextCursor.value = initial()?.nextCursor ?? null;
        filterOptions.value = initial()?.filters;
        loading.value = false;
        failed.value = false;
    });

    watch(sentinel, (element) => {
        observer?.disconnect();
        if (!element) return;
        observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting) && !failed.value) loadMore();
        }, { rootMargin: '200px' });
        observer.observe(element);
    }, { flush: 'post' });

    onMounted(() => {
        if (lazy) void fetchPage(false);
    });
    onBeforeUnmount(() => {
        ++requestId;
        clearTimeout(timer);
        controller?.abort();
        observer?.disconnect();
    });

    return { filters, filterOptions, rows, loading, failed, hasMore, sentinel, loadMore, retry };
}
