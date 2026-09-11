<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import ResourceReaderArticle from '@/components/Groups/Resources/ResourceReaderArticle.vue'
import type { ResourceReaderDocument } from '@/Types/GroupResources'

defineOptions({ layout: [] })
defineProps<{ group: { name: string; slug: string; profile_picture_url?: string | null }; resource?: ResourceReaderDocument }>()
</script>

<template>
    <Head :title="resource ? `${resource.title} - ${group.name}` : group.name" />
    <UApp>
        <main class="mx-auto min-h-screen max-w-6xl px-5 py-8 sm:px-8">
            <header class="mb-8 flex items-center gap-3 border-b border-default pb-5">
                <UAvatar v-if="group.profile_picture_url" :src="group.profile_picture_url" :alt="group.name" size="md" />
                <a :href="`/${group.slug}`" class="break-words text-xl font-semibold">{{ group.name }}</a>
            </header>
            <ResourceReaderArticle v-if="resource" :resource="resource" />
        </main>
    </UApp>
</template>
