<script setup lang="ts">
import { onBeforeUnmount, ref, shallowRef } from 'vue'

defineOptions({ inheritAttrs: false })
const cell = ref<HTMLElement | null>(null)
const bounds = shallowRef<DOMRect | null>(null)

function expand() {
    if (bounds.value || !cell.value) return
    const fields = cell.value.querySelectorAll<HTMLElement>('[data-slot-detail]')
    if (!Array.from(fields).some(field => field.scrollWidth > field.clientWidth)) return

    // Reserve the resting height before taking the card out of the grid's sizing pass.
    bounds.value = cell.value.getBoundingClientRect()
    window.addEventListener('scroll', collapse, true)
    window.addEventListener('resize', collapse)
    document.addEventListener('pointerdown', dismissOutside, true)
}

function collapse() {
    bounds.value = null
    window.removeEventListener('scroll', collapse, true)
    window.removeEventListener('resize', collapse)
    document.removeEventListener('pointerdown', dismissOutside, true)
}

function dismissOutside(event: PointerEvent) {
    if (event.target instanceof Node && !cell.value?.contains(event.target)) collapse()
}

function tap(event: MouseEvent) {
    const pointerType = (event as PointerEvent).pointerType
    const touch = pointerType === 'touch' || pointerType === 'pen' || (!pointerType && window.matchMedia('(hover: none)').matches)
    if (!touch || !(event.target instanceof Element)) return
    if (event.target.closest('a,button,input,select,textarea,[role="button"],[role="link"]')) return
    if (bounds.value) collapse()
    else expand()
}

onBeforeUnmount(() => { if (bounds.value) collapse() })
</script>

<template>
    <div ref="cell" class="grid min-w-0" :style="bounds && { minHeight: bounds.height + 'px' }">
        <div class="grid min-w-0 rounded-md"
            :class="{ 'fixed z-30 bg-elevation-1 shadow-lg dark:bg-elevation-3': bounds }"
            :style="bounds && { top: bounds.top + 'px', left: bounds.left + 'px', width: bounds.width + 'px', minHeight: bounds.height + 'px' }"
            @pointerenter="event => { if (event.pointerType === 'mouse') expand() }"
            @pointerleave="event => { if (event.pointerType === 'mouse' && !cell?.matches(':focus-within')) collapse() }"
            @focusin="expand" @focusout="event => { if (!cell?.contains(event.relatedTarget as Node)) collapse() }"
            @keydown.esc="collapse" @click="tap">
            <div v-bind="$attrs" :data-text-expanded="bounds ? '' : undefined"
                class="group/roster-slot relative min-w-0 rounded-md border transition-colors">
                <slot />
            </div>
        </div>
    </div>
</template>
