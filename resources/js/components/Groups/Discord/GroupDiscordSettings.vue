<script setup lang="ts">
import type { GroupDiscordSettingsData } from '../../../Types/GroupDiscord'
import GroupDiscordConnectionSetup from './GroupDiscordConnectionSetup.vue'
import GroupDiscordBotSettings from './GroupDiscordBotSettings.vue'
import GroupDiscordServerPanel from './GroupDiscordServerPanel.vue'
import { useI18n } from 'vue-i18n'

defineProps<GroupDiscordSettingsData & { reload: () => Promise<void>; preview?: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
</script>

<template>
    <div class="@container space-y-4">
        <UAlert v-if="preview" color="info" variant="soft" icon="i-lucide-flask-conical"
            :description="t('groups.discord.preview.notice')" class="rounded-lg" />
        <GroupDiscordConnectionSetup v-if="!integration" :group="group" :invite-url="inviteUrl" />
        <div v-else class="space-y-4">
            <GroupDiscordServerPanel :group="group" :integration="integration" :snapshot="snapshot"
                :membership-coverage="membershipCoverage" :preview="preview" @changed="emit('changed')" />
            <GroupDiscordBotSettings v-bind="$props" @changed="emit('changed')" />
        </div>
    </div>
</template>
