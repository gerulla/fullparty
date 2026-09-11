<script setup lang="ts">
import type { ResourceReaderDocument } from '@/Types/GroupResources'
import RichTextReader from '@/components/Shared/RichText/RichTextReader.vue'

defineProps<{ resource: ResourceReaderDocument }>()
</script>

<template>
    <article class="min-w-0 max-w-4xl space-y-5">
        <header>
            <h1 class="break-words text-3xl font-semibold">{{ resource.title }}</h1>
            <p v-if="resource.description" class="mt-3 break-words text-muted">{{ resource.description }}</p>
        </header>
        <img v-if="resource.metadata_image_id" :src="`/resource-assets/${resource.metadata_image_id}`" alt="" class="max-h-96 w-full object-contain" />
        <RichTextReader :document="resource.body" />
    </article>
</template>
