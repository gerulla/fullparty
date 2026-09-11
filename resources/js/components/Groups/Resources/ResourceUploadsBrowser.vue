<script setup lang="ts">
import { onMounted } from 'vue'
import type { ResourceImagesController } from '@/Types/ResourceImages'
import ResourceImageGrid from './ResourceImageGrid.vue'
import ResourceImageUploadModal from './ResourceImageUploadModal.vue'
const props = defineProps<{ images: ResourceImagesController }>()
onMounted(() => { void props.images.load() })
async function uploaded(url: string) {
    props.images.state.query = ''; props.images.state.type = 'all'
    await props.images.load()
    props.images.selected.value = props.images.state.items.find(item => item.url === url) ?? null
}
</script>

<template>
    <section class="flex min-h-0 min-w-0 flex-col">
        <ResourceImageGrid :images="images" :selected="images.selected.value?.uuid" @select="images.selected.value = $event">
            <template #actions><ResourceImageUploadModal :upload="images.upload" @uploaded="uploaded" /></template>
        </ResourceImageGrid>
    </section>
</template>
