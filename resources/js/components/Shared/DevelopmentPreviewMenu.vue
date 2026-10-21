<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import type { DevelopmentPreviewControls } from '../../Types/DevelopmentPreview'
import { useDevelopmentPreviewMenu } from '@/composables/useDevelopmentPreviewMenu'

const props = defineProps<DevelopmentPreviewControls>()
const { open, controls, close } = useDevelopmentPreviewMenu()
onMounted(() => { controls.value = props })
onBeforeUnmount(() => {
    if (controls.value === props) {
        controls.value = null
        close()
    }
})
</script>

<template>
    <Teleport v-if="open" defer to="#v2-preview-content">
        <slot :close="close" />
    </Teleport>
</template>
