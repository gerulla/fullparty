import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import type { WorkspaceResource } from '@/Types/ResourceWorkspace'

export function useResourceBrowserPagination(
    resources: () => WorkspaceResource[],
    viewport: () => HTMLElement | null,
    table: () => HTMLTableElement | null,
) {
    const page = ref(1)
    const sizing = shallowRef<{ available: number; row: number; commandLine: number } | null>(null)
    const pages = computed(() => {
        const groups: WorkspaceResource[][] = []
        let group: WorkspaceResource[] = []
        let height = 0
        for (const resource of resources()) {
            const rowHeight = sizing.value ? Math.max(sizing.value.row, resource.embeds.length * sizing.value.commandLine + 1) : 1
            if (group.length && height + rowHeight > (sizing.value?.available ?? 1)) {
                groups.push(group)
                group = []
                height = 0
            }
            group.push(resource)
            height += rowHeight
        }
        if (group.length || !groups.length) groups.push(group)
        return groups
    })
    const pageCount = computed(() => pages.value.length)
    const rows = computed(() => pages.value[page.value - 1] ?? [])
    const range = computed(() => {
        const offset = pages.value.slice(0, page.value - 1).reduce((total, group) => total + group.length, 0)
        return { start: rows.value.length ? offset + 1 : 0, end: offset + rows.value.length, total: resources().length }
    })
    function showResource(id: string | null) {
        const index = pages.value.findIndex(group => group.some(resource => resource.id === id))
        if (index >= 0) page.value = index + 1
    }
    watch(pageCount, value => { page.value = Math.min(page.value, value) })

    let observer: ResizeObserver | undefined
    let frame = 0
    function measure() {
        frame = 0
        const container = viewport()
        const element = table()
        if (!container || !element || !container.clientHeight) return
        const style = getComputedStyle(element)
        const next = {
            // clientHeight excludes a horizontal scrollbar when the columns overflow.
            available: Math.max(0, container.clientHeight - (element.tHead?.getBoundingClientRect().height ?? 0) - 1),
            row: parseFloat(style.getPropertyValue('--resource-row-height')),
            commandLine: parseFloat(style.getPropertyValue('--resource-command-line-height')),
        }
        if (!next.row || !next.commandLine) return
        if (next.available === sizing.value?.available && next.row === sizing.value.row && next.commandLine === sizing.value.commandLine) return
        const anchor = rows.value[0]?.id
        sizing.value = next
        // Resizing should keep the resource at the top of the current page in view.
        if (anchor) showResource(anchor)
    }
    function schedule() { if (!frame) frame = requestAnimationFrame(measure) }
    onMounted(() => {
        observer = new ResizeObserver(schedule)
        for (const element of [viewport(), table()]) if (element) observer.observe(element)
        schedule()
    })
    onBeforeUnmount(() => { observer?.disconnect(); cancelAnimationFrame(frame) })

    return { page, pageCount, rows, range, showResource }
}
