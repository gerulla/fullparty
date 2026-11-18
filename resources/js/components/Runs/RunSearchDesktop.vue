<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue'
import { onClickOutside, useEventListener, useResizeObserver } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import FinderNavigation from '@/components/Navigation/FinderNavigation.vue'
import type { RunSearchField, RunSearchSegment } from './runSearchFields'

const props = defineProps<{
    segments: RunSearchSegment[]
    compactSegments: RunSearchSegment[]
    active: RunSearchField | null
    submitted: boolean
    pinned?: boolean
    loading?: boolean
}>()
const emit = defineEmits<{ open: [field: RunSearchField]; close: []; search: [] }>()
defineSlots<{ default(props: { field: RunSearchField }): unknown }>()
const { t } = useI18n()
const root = ref<HTMLElement | null>(null)
const pickerContent = ref<HTMLElement | null>(null)
const pickerHeight = ref<number>()
const panelId = useId()
const placement = ref<Record<string, string>>({})
const fieldRefs = new Map<RunSearchField, HTMLButtonElement>()
const expanded = computed(() => props.pinned || Boolean(props.active))
const activeIndex = computed(() => props.segments.findIndex(segment => segment.field === props.active))
let restoringFocus = false

function setFieldRef(field: RunSearchField, element: unknown) {
    if (element instanceof HTMLButtonElement) fieldRefs.set(field, element)
    else fieldRefs.delete(field)
}

function measure() {
    if (!root.value) return
    const anchor = root.value.getBoundingClientRect()
    const header = root.value.closest('[data-run-search-header]')
    const bounds = header?.getBoundingClientRect()
    const row = root.value.closest('[data-run-search-row]')?.getBoundingClientRect()
    const left = bounds?.left ?? 0
    const width = bounds?.width ?? document.documentElement.clientWidth
    const top = props.pinned ? 0 : (row?.bottom ?? anchor.bottom) - anchor.top + 8
    const barBottom = props.pinned ? anchor.height : top + 64
    placement.value = {
        '--search-center': `${left + width / 2 - anchor.left}px`,
        '--search-top': `${top}px`,
        '--search-width': `${Math.min(850, width - 32)}px`,
        '--search-panel-space': `${Math.max(0, window.innerHeight - anchor.top - barBottom - 24)}px`,
        '--search-shade-start': `${barBottom + 12}px`,
        '--search-shade-left': `${left - anchor.left}px`,
        '--search-shade-width': `${width}px`,
    }
}
function activate(field: RunSearchField) {
    if (!props.active) measure()
    emit('open', field)
}
function focusField(event: FocusEvent, field: RunSearchField) {
    if (!restoringFocus && (event.currentTarget as HTMLElement).matches(':focus-visible')) activate(field)
}
function escape() {
    if (!props.active) return
    const previous = props.active
    restoringFocus = true
    emit('close')
    nextTick(() => {
        fieldRefs.get(previous)?.focus({ preventScroll: true })
        restoringFocus = false
    })
}
function focusOut(event: FocusEvent) {
    if (event.relatedTarget instanceof Node && !root.value?.contains(event.relatedTarget)) emit('close')
}
function scrollAway(event: Event) {
    if (!props.active) return
    if (event instanceof WheelEvent && event.ctrlKey) return
    if (event.target instanceof Element && root.value?.contains(event.target) && !event.target.closest('[data-search-shade]')) return
    emit('close')
}
function search() {
    if (expanded.value) emit('search')
    else activate('activity')
}
function retirePickerContent(element: Element) {
    if (props.active && element.contains(document.activeElement)) {
        fieldRefs.get(props.active)?.focus({ preventScroll: true })
    }
    element.setAttribute('inert', '')
}
useResizeObserver(pickerContent, ([entry]) => {
    if (entry?.target === pickerContent.value) pickerHeight.value = entry.contentRect.height
})
watch(() => props.active, (field, previous) => {
    if (!field) pickerHeight.value = undefined
    // Selecting an option may unmount the focused picker as the next step opens.
    if (field && previous && !root.value?.contains(document.activeElement)) fieldRefs.get(field)?.focus({ preventScroll: true })
}, { flush: 'post' })
onClickOutside(root, () => { if (props.active) emit('close') })
useResizeObserver(root, () => { if (props.active) measure() })
useEventListener('resize', measure)
useEventListener('wheel', scrollAway, { capture: true, passive: true })
// Moving focus between pickers can scroll the document. Dismiss on a user's
// scroll gesture outside the search, while allowing the browser to reveal a field.
useEventListener('touchmove', scrollAway, { capture: true, passive: true })
</script>

<template>
    <div ref="root" class="run-search-anchor relative w-full min-w-0" :class="pinned ? 'h-16' : 'h-11'" :style="placement"
        :data-expanded="expanded" :data-editing="Boolean(active)" @focusout="focusOut" @keydown.esc.stop.prevent="escape">
        <Transition name="search-fade" @before-leave="element => element.setAttribute('inert', '')"
            @before-enter="element => element.removeAttribute('inert')">
            <FinderNavigation v-if="active && !pinned" class="floating-finders" @navigate="emit('close')" />
        </Transition>
        <Transition name="search-fade">
            <div v-if="active && !pinned" aria-hidden="true" data-search-shade class="search-shade absolute bg-black/25 dark:bg-black/50"
                @pointerdown.prevent="emit('close')" />
        </Transition>
        <div class="search-layer z-10" :class="pinned ? 'relative' : 'floating absolute'">
            <div class="search-bar relative isolate grid h-full items-center rounded-full bg-elevation-1 text-default shadow-md dark:bg-elevation-4 dark:shadow-black/45"
                :data-field="active" :data-result="submitted && !expanded" role="search" :aria-label="t('navigation.sidebar.find_runs')">
                <button v-for="(segment, index) in (expanded ? segments : compactSegments)" :key="segment.field" type="button"
                    :ref="element => setFieldRef(segment.field, element)"
                    :aria-expanded="active === segment.field" :aria-controls="active === segment.field ? panelId : undefined"
                    :aria-label="segment.label + ', ' + segment.summary" :title="segment.summary"
                    class="search-field relative flex h-full min-w-0 flex-col justify-center rounded-full text-start focus-visible:outline-2 focus-visible:-outline-offset-4 focus-visible:outline-brand-600 dark:focus-visible:outline-brand-400"
                    :class="{ 'with-divider': index < 2 && (!active || (index !== activeIndex && index + 1 !== activeIndex)) }"
                    @focus="focusField($event, segment.field)" @click="activate(segment.field)">
                    <span class="field-label relative z-10 truncate text-xs font-semibold text-highlighted" aria-hidden="true">{{ segment.label }}</span>
                    <span class="field-summary relative z-10 flex w-full min-w-0 items-center gap-2">
                        <template v-if="submitted && !expanded && segment.field === 'activity'">
                            <img v-if="segment.image" :src="segment.image" alt="" class="size-6 shrink-0 rounded-md object-cover">
                            <UIcon v-else name="i-lucide-calendar-days" class="size-5 shrink-0 text-brand-700 dark:text-brand-400" />
                        </template>
                        <span class="truncate">{{ segment.summary }}</span>
                    </span>
                </button>
                <button type="button" class="search-action relative z-10 flex shrink-0 items-center justify-center justify-self-end rounded-full bg-brand-700 font-semibold text-white hover:bg-brand-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700 dark:bg-brand-400 dark:text-neutral-950 dark:hover:bg-brand-300 dark:focus-visible:outline-brand-300"
                    :aria-label="expanded ? t('runs.search.submit') : t('navigation.sidebar.find_runs')" :disabled="loading" @click="search">
                    <UIcon :name="loading ? 'i-lucide-loader-circle' : 'i-lucide-search'" class="size-4 shrink-0" :class="{ 'animate-spin': loading }" />
                    <span class="action-label truncate text-sm">{{ t('runs.search.submit') }}</span>
                </button>
            </div>
            <Transition name="search-panel" @before-leave="element => element.setAttribute('inert', '')" @before-enter="element => element.removeAttribute('inert')">
                <section v-if="active" :id="panelId" :aria-label="segments[activeIndex].label" :data-field="active"
                    :style="{ height: pickerHeight === undefined ? undefined : pickerHeight + 'px' }"
                    class="search-picker absolute overflow-hidden rounded-3xl bg-elevation-1 text-default shadow-[0_2px_8px_rgb(0_0_0/0.08),0_12px_32px_rgb(0_0_0/0.14)] dark:bg-elevation-5 dark:shadow-xl dark:shadow-black/55 [--ui-primary:var(--color-brand-700)] dark:[--ui-primary:var(--color-brand-400)]">
                    <Transition name="picker-content" @before-leave="retirePickerContent">
                        <div :key="active" ref="pickerContent" :data-field="active" class="picker-content overflow-y-auto overscroll-contain">
                            <slot :field="active" />
                        </div>
                    </Transition>
                </section>
            </Transition>
        </div>
    </div>
</template>

<style scoped>
.floating-finders {
    position: relative;
    top: 50%;
    width: max-content;
    margin-inline: auto;
    transform: translateY(-50%);
}
.search-layer {
    width: 100%;
    height: 100%;
    container-type: inline-size;
}
.search-layer.floating {
    left: 50%;
    top: 0;
    transform: translateX(-50%);
    transition: width 440ms cubic-bezier(.2,.8,.2,1), left 440ms cubic-bezier(.2,.8,.2,1),
        top 440ms cubic-bezier(.2,.8,.2,1), height 440ms cubic-bezier(.2,.8,.2,1);
}
[data-editing=true] .search-layer.floating {
    width: var(--search-width);
    left: var(--search-center);
    top: var(--search-top);
    height: 4rem;
}
.search-shade {
    top: var(--search-shade-start);
    left: var(--search-shade-left);
    width: var(--search-shade-width);
    height: 100dvh;
}
.search-bar {
    --first: 32%;
    --second: 25%;
    --overlap: .35rem;
    --pill-left: 0px;
    --pill-width: calc(var(--first) + var(--overlap));
    grid-template-columns: var(--first) var(--second) minmax(0,1fr) auto;
    transition: background-color 160ms;
}
.search-bar[data-result=true] {
    grid-template-columns: minmax(0, 1.6fr) fit-content(28%) minmax(0, 1fr) auto;
}
.search-bar[data-result=true] .search-field { padding-inline: .65rem; }
[data-expanded=true] .search-bar {
    --first: 34%;
    --second: 30%;
}
[data-editing=true] .search-bar {
    background: color-mix(in srgb, var(--ui-bg-elevation-2), var(--ui-bg-elevation-3));
}
.dark [data-editing=true] .search-bar { background: var(--ui-bg-elevation-3); }
.search-bar[data-field=when] {
    --pill-left: calc(var(--first) - var(--overlap));
    --pill-width: calc(var(--second) + 2 * var(--overlap));
}
.search-bar[data-field=group] {
    --pill-left: calc(var(--first) + var(--second) - var(--overlap));
    --pill-width: calc(100% - var(--pill-left));
}
.search-bar::before {
    content: '';
    position: absolute;
    z-index: 1;
    pointer-events: none;
    inset-block: 0;
    left: var(--pill-left);
    width: var(--pill-width);
    border-radius: inherit;
    background: var(--ui-bg-elevation-1);
    box-shadow: 0 2px 4px #0001, 0 4px 12px #0002;
    opacity: 0;
    transition: left 300ms cubic-bezier(.2,.82,.22,1), width 300ms cubic-bezier(.2,.82,.22,1), opacity 140ms;
}
.dark .search-bar::before {
    background: var(--ui-bg-elevation-6);
    box-shadow: 0 2px 5px rgb(0 0 0 / 30%), 0 5px 14px rgb(0 0 0 / 24%);
}
[data-editing=true] .search-bar::before { opacity: 1; }
.search-field { padding-inline: min(1rem, 1.2vw); cursor: pointer; }
[data-expanded=true] .search-field { padding-inline: clamp(1rem, 2vw, 2rem); }
.search-field:focus-visible { z-index: 2; }
.search-field.with-divider::after {
    content: '';
    position: absolute;
    right: 0;
    height: 45%;
    width: 1px;
    background: var(--ui-border);
}
@media (hover: hover) {
    .search-field:hover::before {
        content: ''; position: absolute; inset: 0; border-radius: inherit;
        background: color-mix(in srgb, var(--ui-text) 6%, transparent);
    }
}
.field-label { max-height: 0; opacity: 0; transition: max-height 250ms, opacity 150ms; }
.field-summary { font-size: .75rem; font-weight: 550; }
[data-expanded=true] .field-label { max-height: 1.3rem; opacity: 1; }
[data-expanded=true] .field-summary { color: var(--ui-text-muted); font-size: .875rem; font-weight: 400; }
.search-action { margin-inline-end: .35rem; padding: 0 .5rem; height: 2rem; min-width: 2rem; cursor: pointer; transition: padding 250ms, height 250ms; }
.action-label { max-width: 0; opacity: 0; transition: max-width 260ms, margin 260ms, opacity 150ms; }
[data-expanded=true] .search-action { margin-inline-end: .5rem; height: 2.9rem; padding-inline: 1rem; }
[data-expanded=true] .action-label { max-width: 9rem; opacity: 1; margin-inline-start: .45rem; }
.search-picker {
    --narrow-picker: min(25rem, 100cqw);
    --resize: 300ms cubic-bezier(.2,.8,.2,1);
    top: calc(100% + .65rem);
    left: 0;
    width: var(--narrow-picker);
    max-height: var(--search-panel-space);
    transition: left var(--resize), width var(--resize), height var(--resize), opacity 160ms, transform 230ms ease-out;
}
.search-picker[data-field=when], .picker-content[data-field=when] { width: 100cqw; }
.search-picker[data-field=group] { left: calc(100% - var(--narrow-picker)); }
/* Lay out both sets of controls at their own width while the outer surface moves. */
.picker-content { width: var(--narrow-picker); max-height: var(--search-panel-space); }
.picker-content-leave-active { position: absolute; inset: 0 auto auto 0; transition: opacity 90ms ease-out; }
.picker-content-enter-active { transition: opacity 150ms ease-out 90ms; }
.picker-content-leave-to, .picker-content-enter-from { opacity: 0; }
.search-fade-enter-active, .search-fade-leave-active { transition: opacity 160ms; }
.search-panel-leave-active { transition: opacity 110ms ease-out; }
.search-fade-enter-from, .search-fade-leave-to, .search-panel-enter-from, .search-panel-leave-to { opacity: 0; }
.search-panel-enter-from { transform: translateY(-.5rem); }
@media (prefers-reduced-motion: reduce) {
    .run-search-anchor *, .search-bar::before { transition: none !important; }
}
</style>
