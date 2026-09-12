import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import type { ResourceReaderPage, ResourceReaderSummary } from '@/Types/GroupResources'
import { readerCollectionPath, readerCollectionTree } from '@/utils/resourceReader'
import { localizedValue } from '@/utils/localizedValue'

export function useResourceReader(getPage: () => ResourceReaderPage, publicView: boolean) {
    const { locale, t } = useI18n()
    const loading = ref(false)
    const draft = reactive({ q: '', activity: 'all' })
    watch(() => getPage().filters, filters => {
        draft.q = filters.q ?? ''
        draft.activity = filters.activity_type_id ? String(filters.activity_type_id) : 'all'
    }, { immediate: true })
    const prefix = publicView ? 'public-resources' : 'groups.dashboard.resources'
    const parameters = () => ({ group: getPage().group.slug, ...(publicView ? {} : { locale: locale.value }) })
    const navigation = {
        home: computed(() => route(`${prefix}.index`, parameters())),
        collection: (slug: string) => route(`${prefix}.collections.show`, { ...parameters(), collectionSlug: slug }),
        history: (resource: ResourceReaderSummary) => route(`${prefix}.history`, { ...parameters(), slug: resource.slug }),
        resource: (resource: ResourceReaderSummary) => resource.is_home
            ? route(`${prefix}.index`, parameters())
            : resource.source_type === 'holster' ? route(`${prefix}.holsters.show`, { ...parameters(), holster: resource.holster_id })
            : route(`${prefix}.show`, { ...parameters(), slug: resource.slug }),
    }
    const selectedCollection = computed(() => getPage().collections.find(item => item.id === getPage().reader.selected_collection_id))
    const collectionPath = computed(() => readerCollectionPath(getPage().collections, selectedCollection.value?.id ?? getPage().resource?.collection_id ?? null))
    const tree = computed(() => readerCollectionTree(getPage().collections))
    const filtered = computed(() => !!(getPage().filters.q || getPage().filters.tag || getPage().filters.activity_type_id))
    const article = computed(() => !filtered.value && !selectedCollection.value && getPage().resource && !getPage().resource?.is_home ? getPage().resource : null)
    const showHome = computed(() => !filtered.value && !selectedCollection.value && getPage().resources.current_page === 1 && getPage().resource?.is_home)
    const childCollections = computed(() => selectedCollection.value ? getPage().collections.filter(item => item.parent_id === selectedCollection.value!.id) : [])
    const sections = computed(() => {
        const resources = getPage().resources.data.filter(item => !showHome.value || !item.is_home)
        if (filtered.value || selectedCollection.value) return [{ collection: null, resources }]
        return [
            { collection: null, resources: resources.filter(item => item.collection_id === null) },
            ...tree.value.map(collection => ({ collection, resources: resources.filter(item => item.collection_id === collection.id) })),
        ].filter(section => section.resources.length > 0)
    })
    const activityItems = computed(() => [{ value: 'all', label: t('groups.resources.reader.all_activities') }, ...getPage().reader.activities.map(item => ({ value: String(item.id), label: localizedValue(item.name, locale.value) }))])
    function visit(page = 1, tag = getPage().filters.tag) {
        const url = selectedCollection.value ? navigation.collection(selectedCollection.value.slug) : navigation.home.value
        router.get(url, {
            ...(draft.q.trim() ? { q: draft.q.trim() } : {}),
            ...(draft.activity !== 'all' ? { activity_type_id: Number(draft.activity) } : {}),
            ...(tag ? { tag } : {}), ...(page > 1 ? { page } : {}),
        }, { preserveState: true, onStart: () => { loading.value = true }, onFinish: () => { loading.value = false } })
    }
    function clear() { draft.q = ''; draft.activity = 'all'; visit(1, null) }
    function activity(value: string) { draft.activity = value; visit() }
    return { navigation, draft, loading, selectedCollection, collectionPath, tree, filtered, article, showHome, childCollections, sections, activityItems, visit, clear, activity }
}
