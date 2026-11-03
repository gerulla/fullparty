<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { GroupSettingsGroup } from '@/Types/GroupSettings'
import GroupSettingsPanel from '@/components/Groups/Settings/GroupSettingsPanel.vue'

const props = defineProps<{ group: GroupSettingsGroup }>()
const { t, locale } = useI18n()
const breadcrumbs = computed(() => [
    { label: t('navigation.sidebar.dashboard'), icon: 'i-lucide-house', to: route('dashboard', { locale: locale.value }, false), ui: { linkLabel: 'sr-only' } },
    { label: t('navigation.sidebar.groups'), to: route('groups.index', { locale: locale.value }, false) },
    { label: props.group.name, to: route('groups.dashboard', { group: props.group.slug, locale: locale.value }, false), ui: { linkLabel: 'max-w-40 truncate sm:max-w-72' } },
    { label: t('groups.index.navigation.settings') },
])
</script>

<template>
    <div class="w-full">
        <h1 class="sr-only">{{ t('groups.settings.title') }}</h1>
        <UBreadcrumb :items="breadcrumbs" color="brand"
            :ui="{ list: 'flex-wrap', link: 'text-xs', linkLeadingIcon: 'size-4', separatorIcon: 'size-3' }" />

        <GroupSettingsPanel :key="group.id" :group="group" />
    </div>
</template>
