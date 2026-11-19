<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import AdminPageHeader from '@/components/Admin/AdminPageHeader.vue'
import { useAdminNavigation } from '@/composables/useAdminNavigation'

const { t } = useI18n()
const { sections } = useAdminNavigation()
const directories = computed(() => sections.value.filter(section => section.links.length > 1))
const directLinks = computed(() => sections.value.filter(section => section.links.length === 1).map(section => section.links[0]))
</script>

<template>
    <Head :title="t('navigation.admin_panel.title')" />
    <div class="mx-auto max-w-7xl space-y-6 lg:space-y-8">
        <AdminPageHeader :title="t('navigation.admin_panel.title')" :subtitle="t('navigation.admin_panel.description')" />
        <div class="grid gap-3 sm:grid-cols-3">
            <Link v-for="item in directLinks" :key="item.to" :href="item.to"
                class="admin-panel group flex items-center gap-3 p-4 transition-shadow hover:shadow-md focus-visible:outline-2 focus-visible:outline-primary lg:p-5">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary">
                    <UIcon :name="item.icon" class="size-5" />
                </span>
                <span class="min-w-0 flex-1 text-sm font-semibold text-highlighted">{{ item.label }}</span>
                <UIcon name="i-lucide-arrow-up-right" class="size-4 shrink-0 text-muted group-hover:text-primary" />
            </Link>
        </div>
        <div class="grid items-start gap-4 md:grid-cols-2 lg:grid-cols-3">
            <section v-for="section in directories" :key="section.label" class="admin-panel p-3 lg:p-4">
                <h2 class="mx-1 mb-2 flex items-center gap-2.5 border-b border-muted px-1 pt-1 pb-3 text-base font-semibold text-highlighted">
                    <UIcon :name="section.icon" class="size-5 shrink-0 text-primary" />
                    {{ section.label }}
                </h2>
                <div class="space-y-1">
                    <Link v-for="item in section.links" :key="item.to" :href="item.to"
                        class="flex items-center gap-3 rounded-lg px-2.5 py-3 text-sm transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-primary">
                        <UIcon :name="item.icon" class="size-4 shrink-0 text-muted" />
                        <span class="min-w-0 flex-1">{{ item.label }}</span>
                        <UIcon name="i-lucide-chevron-right" class="size-4 shrink-0 text-muted" />
                    </Link>
                </div>
            </section>
        </div>
    </div>
</template>
