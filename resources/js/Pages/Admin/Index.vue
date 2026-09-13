<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/PageHeader.vue'
import { useAdminNavigation } from '@/composables/useAdminNavigation'

const { t } = useI18n()
const { sections } = useAdminNavigation()
</script>

<template>
    <Head :title="t('navigation.admin_panel.title')" />
    <div class="space-y-6">
        <PageHeader :title="t('navigation.admin_panel.title')" :subtitle="t('navigation.admin_panel.description')" />
        <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
            <section v-for="section in sections" :key="section.label" class="border border-default bg-default/40">
                <h2 class="flex items-center gap-2 border-b border-default px-4 py-3 text-sm font-semibold">
                    <UIcon :name="section.icon" class="size-4 text-primary" />
                    {{ section.label }}
                </h2>
                <div class="divide-y divide-default">
                    <Link v-for="item in section.links" :key="item.to" :href="item.to"
                        class="flex items-center gap-3 px-4 py-4 text-sm transition hover:bg-elevated focus-visible:outline-2 focus-visible:outline-primary">
                        <UIcon :name="item.icon" class="size-4 shrink-0 text-muted" />
                        <span class="min-w-0 flex-1">{{ item.label }}</span>
                        <UIcon name="i-lucide-chevron-right" class="size-4 shrink-0 text-muted" />
                    </Link>
                </div>
            </section>
        </div>
    </div>
</template>
