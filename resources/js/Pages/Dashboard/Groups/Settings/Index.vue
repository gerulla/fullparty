<script setup lang="ts">
import type { GroupSettingsGroup } from '@/Types/GroupSettings'
import GroupSettingsPanel from '@/components/Groups/Settings/GroupSettingsPanel.vue'

defineProps<{ group: GroupSettingsGroup }>()
</script>

<template>
    <div class="w-full">
        <GroupSettingsPanel :key="group.id" :group="group" />
    </div>
</template>
