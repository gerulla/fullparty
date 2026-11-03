<script setup lang="ts">
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { GroupNavigationContext } from '@/Types/GroupNavigation'

const props = defineProps<{ group: GroupNavigationContext }>()
const { t, locale } = useI18n()
const title = computed(() => t('groups.dashboard.navigation.content_customization'))
const breadcrumbs = computed(() => [
    { label: t('navigation.sidebar.dashboard'), icon: 'i-lucide-house', to: route('dashboard', { locale: locale.value }, false), ui: { linkLabel: 'sr-only' } },
    { label: t('navigation.sidebar.groups'), to: route('groups.index', { locale: locale.value }, false) },
    { label: props.group.name, to: route('groups.dashboard', { group: props.group.slug, locale: locale.value }, false), ui: { linkLabel: 'max-w-40 truncate sm:max-w-72' } },
    { label: title.value },
])
</script>

<template>
    <Head :title="title" />
    <div class="w-full">
        <h1 class="sr-only">{{ title }}</h1>
        <UBreadcrumb :items="breadcrumbs" color="brand"
            :ui="{ list: 'flex-wrap', link: 'text-xs', linkLeadingIcon: 'size-4', separatorIcon: 'size-3' }" />
    </div>
</template>
