<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ActivityStatus } from '@/Types/ActivityCore';
import { getActivityStatusMeta } from '@/utils/activityStatusMeta';
import { RUN_PARTICIPATION_META } from '@/utils/runParticipationMeta';

const { t } = useI18n();
withDefaults(defineProps<{ dots?: boolean }>(), { dots: false });
const activityStatuses: ActivityStatus[] = ['draft', 'scheduled', 'upcoming', 'assigned', 'ongoing', 'complete', 'cancelled'];
const items = computed(() => [
    ...activityStatuses.map((status) => ({
        key: status,
        label: t(`groups.activities.statuses.${status}`),
        icon: getActivityStatusMeta(status).icon,
        color: getActivityStatusMeta(status).color,
    })),
    ...(['pending', 'approved', 'on_bench'] as const).map((status) => ({
        key: status,
        label: t(`runs.participation.statuses.${status}`),
        ...RUN_PARTICIPATION_META[status],
    })),
]);
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-5 gap-y-3 border-t border-default/70 pt-4 pb-2 text-xs" :aria-label="t('groups.activities.legend.title')">
        <span class="font-medium text-toned">{{ t('groups.activities.legend.title') }}</span>
        <span v-for="item in items" :key="item.key" class="inline-flex items-center gap-1.5 text-muted">
            <span v-if="dots" class="size-2 shrink-0 rounded-full" aria-hidden="true"
                :style="{ backgroundColor: item.color === 'neutral' ? 'var(--ui-text-highlighted)' : `var(--ui-${item.color})` }" />
            <UIcon v-else :name="item.icon" class="size-4 shrink-0" :style="{ color: item.color === 'neutral' ? 'var(--ui-text)' : `var(--ui-${item.color})` }" />
            {{ item.label }}
        </span>
    </div>
</template>
