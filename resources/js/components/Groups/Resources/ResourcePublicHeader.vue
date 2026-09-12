<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { ResourceLibrary, ResourceReaderPage } from '@/Types/GroupResources'

const props = defineProps<{ group: ResourceReaderPage['group']; library: ResourceLibrary }>()
const customization = computed(() => props.library.customization)
const banner = computed(() => customization.value.banner_image_id ? `/resource-assets/${customization.value.banner_image_id}` : props.group.banner_image_url)
const logo = computed(() => customization.value.logo_image_id ? `/resource-assets/${customization.value.logo_image_id}` : props.group.profile_picture_url)
const homeUrl = computed(() => route('public-resources.index', { group: props.group.slug }))
</script>

<template>
    <header class="border-b border-default bg-default">
        <img v-if="banner" :src="banner" alt="" class="h-36 w-full object-cover sm:h-48 lg:h-56" :style="{ objectPosition: `${customization.banner_focal_x ?? 50}% ${customization.banner_focal_y ?? 50}%` }" />
        <div class="mx-auto flex max-w-7xl flex-wrap items-start gap-4 px-5 py-6 sm:gap-5 sm:px-8">
            <Link :href="homeUrl" class="shrink-0"><UAvatar :src="logo || undefined" :alt="group.name" size="3xl" class="rounded-none" :ui="{ image: 'rounded-none' }" /></Link>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-3"><Link :href="homeUrl" class="break-words text-2xl font-semibold tracking-tight text-highlighted hover:text-primary">{{ group.name }}</Link><UBadge v-if="group.datacenter" color="neutral" variant="subtle" size="sm">{{ group.datacenter }}</UBadge></div>
                <p v-if="group.description" class="mt-2 max-w-3xl whitespace-pre-line break-words text-sm leading-relaxed text-muted">{{ group.description }}</p>
                <nav v-if="customization.links?.length" class="mt-4 flex flex-wrap gap-x-5 gap-y-2"><a v-for="(link, index) in customization.links" :key="index" :href="link.url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-sm text-primary hover:underline">{{ link.label }}<UIcon name="i-lucide-arrow-up-right" class="size-3.5" /></a></nav>
            </div>
            <div class="ml-auto shrink-0"><slot /></div>
        </div>
    </header>
</template>
