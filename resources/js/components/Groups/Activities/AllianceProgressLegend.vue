<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { allianceProgressTones } from '@/utils/allianceProgress'

defineProps<{ loading: boolean; failed: boolean; fflogsUnavailable: boolean }>()
defineEmits<{ retry: [] }>()
const { t } = useI18n()
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-muted" aria-live="polite">
        <span v-for="(tone, status) in allianceProgressTones" :key="status" class="inline-flex items-center gap-1.5">
            <span class="size-2.5 shrink-0" :class="tone.swatch" aria-hidden="true" />
            {{ t(`groups.activities.overview.board.progression.${status}`) }}
        </span>
        <span v-if="loading" class="inline-flex items-center gap-1.5">
            <UIcon name="i-lucide-loader-circle" class="size-3.5 animate-spin" />
            {{ t('groups.activities.overview.board.progression.loading') }}
        </span>
        <span v-if="failed || fflogsUnavailable" class="text-warning">
            {{ t(`groups.activities.overview.board.progression.${failed ? 'failed' : 'fflogs_unavailable'}`) }}
        </span>
        <UButton v-if="!loading && (failed || fflogsUnavailable)" size="xs" variant="link" color="neutral" :label="t('groups.activities.overview.board.progression.retry')" @click="$emit('retry')" />
    </div>
</template>
