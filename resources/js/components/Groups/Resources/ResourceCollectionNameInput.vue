<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ResourceCollectionActions } from '@/Types/ResourceCollections'

const props = defineProps<{ actions: ResourceCollectionActions }>()
const { t } = useI18n()
const input = ref<{ inputRef: HTMLInputElement }>()
let frame = 0
let focused = false
function focus() {
    const field = input.value?.inputRef
    field?.focus({ preventScroll: true })
    field?.select()
    field?.scrollIntoView({ block: 'nearest' })
    focused = true
}
defineExpose({ focus })
onMounted(async () => {
    await nextTick()
    // Let the menu release its focus scope and the workspace remove inert first.
    frame = requestAnimationFrame(() => {
        frame = requestAnimationFrame(() => {
            focus()
        })
    })
})
onBeforeUnmount(() => cancelAnimationFrame(frame))
function blur() { if (focused) void props.actions.save() }
function keydown(event: KeyboardEvent) {
    if (event.isComposing) return
    if (event.key === 'Enter') { event.preventDefault(); void props.actions.save() }
    if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); props.actions.cancel() }
}
</script>

<template>
    <UInput v-if="actions.state.editing" ref="input" v-model="actions.state.editing.name" size="xs" :maxlength="160" :aria-label="t('groups.resources.workspace.name')" :aria-invalid="!!actions.state.error" :disabled="actions.state.busy" class="min-w-0 flex-1" :ui="{ base: 'rounded-none' }" @keydown="keydown" @blur="blur" @contextmenu.stop />
</template>
