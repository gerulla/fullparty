<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { GroupHomeQuickLinkSection } from '@/Types/GroupNavigation'

defineProps<{ sections: GroupHomeQuickLinkSection[] }>()
const { t } = useI18n()
</script>

<template>
    <section :aria-label="t('groups.dashboard.quick_links.title')" class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))] gap-4">
        <div v-for="section in sections" :key="section.key" class="min-w-0 rounded-lg border border-default bg-elevated/50 px-4 py-2">
            <h2 class="flex items-center gap-3 border-b border-default py-2 text-sm font-semibold text-highlighted">
                <UIcon :name="section.icon" class="size-5 shrink-0 text-muted" aria-hidden="true" />
                {{ section.label }}
            </h2>
            <div class="divide-y divide-default">
                <Link v-for="link in section.links" :key="link.key" :href="link.href"
                    class="group flex items-center gap-3 rounded-md py-3 text-default transition-colors hover:text-brand focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                    <UIcon :name="link.icon" class="size-4 shrink-0 text-muted group-hover:text-brand" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium">{{ link.label }}</span>
                        <span v-if="link.description" class="mt-0.5 block text-xs leading-5 text-muted">{{ link.description }}</span>
                    </span>
                    <UIcon name="i-lucide-chevron-right" class="size-4 shrink-0 text-muted group-hover:text-brand" aria-hidden="true" />
                </Link>
            </div>
        </div>
    </section>
</template>
