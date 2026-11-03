<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { computed } from 'vue'
import { route } from 'ziggy-js'
import type { GroupSettingsGroup } from '@/Types/GroupSettings'
import GroupDiscordSettings from '@/components/Groups/Discord/GroupDiscordSettings.vue'
import { useGroupDiscordSettingsData } from '@/composables/useGroupDiscordSettingsData'
import { useGroupDiscordPreview } from '@/composables/useGroupDiscordPreview'
import { groupDiscordPreview } from '@/utils/groupDiscordPreview'

const props = defineProps<{ group: Pick<GroupSettingsGroup, 'id' | 'name' | 'slug'> }>()
const { t, locale } = useI18n()
const { active: preview } = useGroupDiscordPreview()
const { data, loading, failed, reload } = useGroupDiscordSettingsData(() =>
    route('groups.dashboard.discord-integration', { group: props.group.slug, locale: locale.value }), () => !preview.value)
const displayedData = computed(() => preview.value ? groupDiscordPreview(props.group) : data.value)
</script>

<template>
    <div class="space-y-4" :aria-busy="loading">
        <UAlert v-if="failed && !preview" color="error" variant="soft" icon="i-lucide-circle-alert"
            :title="t('groups.discord.load_error')"
            :actions="[{ label: t('groups.discord.actions.refresh_snapshot'), icon: 'i-lucide-refresh-cw', onClick: reload }]" />
        <div v-if="loading && !displayedData" class="space-y-4">
            <USkeleton class="h-20 w-full rounded-lg" />
            <USkeleton class="h-64 w-full rounded-lg" />
            <USkeleton class="h-32 w-full rounded-lg" />
        </div>
        <GroupDiscordSettings v-if="displayedData" :key="preview ? 'preview' : 'live'" v-bind="displayedData"
            :preview="preview" :reload="reload" @changed="reload" />
    </div>
</template>
