<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ReportGroupContext } from '@/Types/Reports'
import ReportProfileCard from './ReportProfileCard.vue'
defineProps<{ group: ReportGroupContext }>()
const { t } = useI18n()
</script>

<template>
    <UCard :ui="{ body: 'space-y-4' }">
        <h2 class="font-semibold">{{ t('reports.admin.group_context') }}</h2>
        <img v-if="group.banner_image_url" :src="group.banner_image_url" alt="" class="h-28 w-full object-cover" />
        <div class="flex items-center gap-3 min-w-0"><UAvatar :src="group.profile_picture_url ?? undefined" :alt="group.name" size="lg" /><div class="min-w-0"><p class="font-semibold break-words">{{ group.name }}</p><p class="text-xs text-muted">#{{ group.id }} · {{ group.slug }}</p></div></div>
        <div class="flex flex-wrap gap-2 text-xs text-muted"><span v-if="group.datacenter">{{ group.datacenter }} ·</span><span>{{ t('reports.admin.members', { count: group.member_count }) }}</span><UBadge color="neutral" variant="subtle" size="sm">{{ t('reports.admin.' + (group.is_visible ? 'listed' : 'unlisted')) }}</UBadge></div>
        <p v-if="group.description" class="text-sm whitespace-pre-wrap break-words">{{ group.description }}</p>
        <UButton :href="group.url" target="_blank" color="neutral" variant="outline" size="sm" trailing-icon="i-lucide-external-link">{{ t('reports.admin.open_group') }}</UButton>
        <div class="border-t border-default pt-4"><ReportProfileCard :profile="group.owner" :label="t('reports.admin.group_owner')" /></div>
    </UCard>
</template>
