<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/PageHeader.vue'
import type { ChangelogAdminList } from '@/Types/Changelog'

defineProps<{ entries: ChangelogAdminList }>()
const { t } = useI18n()
</script>

<template>
    <Head :title="t('changelog.title')" />
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <PageHeader :title="t('changelog.title')" :subtitle="t('changelog.admin_description')" />
            <UButton :to="route('admin.changelog.create')" icon="i-lucide-plus" :label="t('changelog.create')" />
        </div>
        <div class="divide-y divide-default border border-default">
            <p v-if="!entries.data.length" class="p-8 text-center text-muted">{{ t('changelog.empty') }}</p>
            <Link v-for="entry in entries.data" :key="entry.id" :href="route('admin.changelog.edit', { changelogEntry: entry.id })" class="flex items-center gap-4 p-4 transition hover:bg-elevated">
                <UIcon name="i-lucide-scroll-text" class="size-5 shrink-0 text-primary" />
                <span class="min-w-0 flex-1"><span class="block font-semibold">{{ entry.title }}</span><span class="text-sm text-muted">{{ entry.version_from ? t('changelog.range', { from: entry.version_from, to: entry.version_to }) : t('changelog.version', { version: entry.version_to }) }}</span></span>
                <UBadge :color="entry.is_published ? 'success' : 'neutral'" variant="subtle">{{ t(entry.is_published ? 'changelog.published' : entry.published_at ? 'changelog.unpublished' : 'changelog.draft') }}</UBadge>
                <UIcon name="i-lucide-chevron-right" class="size-4" />
            </Link>
        </div>
        <UPagination v-if="entries.total > entries.per_page" :page="entries.current_page" :total="entries.total" :items-per-page="entries.per_page" @update:page="router.get(route('admin.changelog.index'), { page: $event })" />
    </div>
</template>
