import axios from 'axios'
import { inject, onBeforeUnmount, reactive, ref, type InjectionKey } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { ResourceImage, ResourceImageContext, ResourceImagePage, ResourceImageMetadata, ResourceImagesController } from '@/Types/ResourceImages'

export const resourceImageLibraryKey: InjectionKey<ResourceImageContext> = Symbol('resource-image-library')

export function useResourceImages(context?: ResourceImageContext): ResourceImagesController {
    const provided = context ?? inject(resourceImageLibraryKey)
    if (!provided) throw new Error('Resource image library is unavailable')
    const library = provided
    const { t } = useI18n()
    const state = reactive({ items: [] as ResourceImage[], query: '', type: 'all', page: 1, total: 0, perPage: 24, loading: false, busy: false, error: '' })
    const selected = ref<ResourceImage | null>(null)
    let request = 0
    let searchTimer: ReturnType<typeof setTimeout> | undefined
    const url = (action: string, uuid?: string) => route(`groups.dashboard.resources.images.${action}`, { group: library.groupSlug(), ...(uuid ? { image: uuid } : {}) })
    function report(error: unknown) {
        const errors = axios.isAxiosError(error) ? error.response?.data?.errors : null
        state.error = errors ? Object.values(errors).flat().join(' ') : t('groups.resources.workspace.request_failed')
    }
    async function load(page = 1) {
        clearTimeout(searchTimer)
        const current = ++request
        state.page = page; state.loading = true; state.error = ''
        try {
            const { data } = await axios.get<ResourceImagePage>(url('index'), { params: { q: state.query, type: state.type, page, per_page: state.perPage, resource_id: library.resourceId?.() ?? undefined, ...(library.libraryOnly ? { library_only: 1 } : {}) } })
            if (request !== current) return
            state.items = data.data; state.total = data.total; state.page = data.current_page
            if (selected.value) selected.value = data.data.find(item => item.uuid === selected.value?.uuid) ?? null
        } catch (error) { if (request === current) { state.items = []; selected.value = null; state.total = 0; report(error) } }
        finally { if (request === current) state.loading = false }
    }
    function search() {
        ++request
        state.loading = true
        clearTimeout(searchTimer)
        searchTimer = setTimeout(() => { void load() }, 250)
    }
    async function save(image: ResourceImage, values: ResourceImageMetadata) {
        if (state.busy) return false
        state.busy = true; state.error = ''
        try {
            const { data } = await axios.put<{ data: ResourceImage }>(url('update', image.uuid), values)
            selected.value = data.data
            state.items = state.items.map(item => item.uuid === image.uuid ? data.data : item)
            return true
        } catch (error) { report(error); return false }
        finally { state.busy = false }
    }
    async function remove() {
        if (!selected.value || state.busy) return false
        state.busy = true; state.error = ''
        try {
            await axios.delete(url('destroy', selected.value.uuid))
            selected.value = null; library.changed()
            await load(state.items.length === 1 ? Math.max(1, state.page - 1) : state.page)
            return true
        } catch (error) { report(error); return false }
        finally { state.busy = false }
    }
    async function upload(file: File, alt = '', caption = '') {
        if (state.busy) throw new Error('Resource image request in progress')
        state.busy = true
        try {
            const form = new FormData()
            form.append('image', file); form.append('alt_text', alt); form.append('caption', caption); form.append('library_upload', '1')
            const { data } = await axios.post<{ data: { url: string } }>(url('store'), form)
            library.changed()
            return data.data.url
        } finally { state.busy = false }
    }
    onBeforeUnmount(() => { ++request; clearTimeout(searchTimer) })
    return { state, selected, load, search, save, remove, upload }
}
