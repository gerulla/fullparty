<script setup lang="ts">
import { computed, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import type { GearsetDisplay, GearsetSnapshot } from '@/Types/XivGear'
import ResourceGearsetDisplay from './ResourceGearsetDisplay.vue'

const props = defineProps<{ snapshots: GearsetSnapshot[]; display: GearsetDisplay }>()
const { t } = useI18n()
const index = ref(0)
const active = computed(() => Math.min(index.value, props.snapshots.length - 1))
const id = useId()
function navigate(event: KeyboardEvent) {
    const direction = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0
    if (!direction && !['Home', 'End'].includes(event.key)) return
    event.preventDefault()
    index.value = event.key === 'Home' ? 0 : event.key === 'End' ? props.snapshots.length - 1 : (active.value + direction + props.snapshots.length) % props.snapshots.length
    const buttons = (event.currentTarget as HTMLElement).querySelectorAll<HTMLButtonElement>('[role=tab]')
    buttons[index.value]?.focus()
}
</script>

<template>
    <div v-if="snapshots.length" class="my-5 min-w-0 clear-both">
        <div v-if="snapshots.length > 1" class="gear-tabs" role="tablist" :aria-label="t('xivgear.sets')" @keydown="navigate">
            <button v-for="(set, number) in snapshots" :id="`${id}-tab-${number}`" :key="number" type="button" role="tab" :aria-selected="active === number" :aria-controls="`${id}-panel`" :tabindex="active === number ? 0 : -1" @click="index = number">{{ set.name }}</button>
        </div>
        <div :id="`${id}-panel`" :role="snapshots.length > 1 ? 'tabpanel' : undefined" :aria-labelledby="snapshots.length > 1 ? `${id}-tab-${active}` : undefined">
            <ResourceGearsetDisplay :key="active" :snapshot="snapshots[active]" :display="display" class="!my-0" />
        </div>
    </div>
</template>
