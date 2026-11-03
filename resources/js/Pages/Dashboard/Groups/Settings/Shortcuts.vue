<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { GroupQuickCreateShortcut, GroupSettingsGroup } from '@/Types/GroupSettings'
import PageHeader from '@/components/PageHeader.vue'
import GroupShortcutSettings from '@/components/Groups/Settings/GroupShortcutSettings.vue'

defineProps<{
    group: Pick<GroupSettingsGroup, 'id' | 'name' | 'slug'>
    shortcuts: GroupQuickCreateShortcut[]
}>()
const { t } = useI18n()
</script>

<template>
    <div class="w-full">
        <PageHeader :title="t('groups.shortcuts.title')" :subtitle="t('groups.shortcuts.subtitle')" />
        <GroupShortcutSettings class="mt-4" :group="{ ...group, quick_create_shortcuts: shortcuts }" />
    </div>
</template>
