<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { GroupDiscordSettingsData } from '../../../Types/GroupDiscord'
import AccessBadge from '@/components/Groups/AccessBadge.vue'
import PageHeader from '@/components/PageHeader.vue'
import GroupDiscordSettings from '@/components/Groups/Discord/GroupDiscordSettings.vue'

import GroupDiscordPreviewControls from '@/components/Groups/Discord/GroupDiscordPreviewControls.vue'
import { useGroupDiscordPreview } from '@/composables/useGroupDiscordPreview'
import { groupDiscordPreview } from '@/utils/groupDiscordPreview'

const props = defineProps<GroupDiscordSettingsData>()
const { active: preview } = useGroupDiscordPreview()
const displayedData = computed(() => preview.value ? groupDiscordPreview(props.group) : props)
const { t } = useI18n()
const reload = () => new Promise<void>(resolve => {
    if (preview.value) { resolve(); return }
    router.reload({ only: ['group', 'integration', 'snapshot', 'membershipCoverage'], onFinish: () => resolve() })
})
</script>

<template>
    <div class="w-full">
        <GroupDiscordPreviewControls />
        <PageHeader :title="t('groups.discord.title')" :subtitle="t('groups.discord.subtitle', { group: group.name })">
            <AccessBadge :role="group.current_user_role" fallback-role="owner" />
        </PageHeader>
        <GroupDiscordSettings :key="preview ? 'preview' : 'live'" class="mt-4" v-bind="displayedData" :preview="preview" :reload="reload" />
    </div>
</template>
