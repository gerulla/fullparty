<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { GroupLeaderboardRankingController, GroupLeaderboardCountEntry, GroupLeaderboardLimit, GroupLeaderboardPeriod } from '@/Types/GroupLeaderboard'
import { createDateTimeFormatter } from '@/utils/dateTimeFormat'

defineProps<{ board: GroupLeaderboardRankingController }>()
const { t, locale } = useI18n()
const numberFormatter = computed(() => new Intl.NumberFormat(locale.value));
const formatNumber = (value: number) => numberFormatter.value.format(value);
const formatDate = (date: string | null) => {
    if (!date) {
        return t("groups.leaderboard.common.no_date");
    }

    return createDateTimeFormatter(locale.value, {
        day: "numeric",
        month: "short",
        year: "numeric",
    }).format(new Date(date));
};

const characterWorld = (entry: GroupLeaderboardCountEntry) => [
    entry.character.world,
    entry.character.datacenter,
].filter(Boolean).join(" / ");

const periodTabs = computed(() => [
    { label: t('groups.leaderboard.periods.all_time'), value: 'all_time' },
    { label: t('groups.leaderboard.periods.past_6_months'), value: 'past_6_months' },
    { label: t('groups.leaderboard.periods.past_30_days'), value: 'past_30_days' },
]);
const limitTabs = computed(() => [
    { label: t('groups.leaderboard.limits.top_10'), value: '10' },
    { label: t('groups.leaderboard.limits.top_100'), value: '100' },
    { label: t('groups.leaderboard.limits.all'), value: 'all' },
]);
const rankTone = (rank: number) => {
    if (rank === 1) {
        return "text-warning";
    }

    if (rank === 2) {
        return "text-info";
    }

    if (rank === 3) {
        return "text-success";
    }

    return "text-muted";
};

</script>

<template>
    <UCard class="dark:bg-elevated/25" :ui="{ body: 'p-0 sm:p-0' }">
        <template #header>
            <div>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold text-md">
                            {{ t(`groups.leaderboard.boards.${board.category}.title`) }}
                        </p>
                        <p class="text-sm text-muted">
                            {{ t(`groups.leaderboard.boards.${board.category}.subtitle`) }}
                        </p>
                    </div>
                    <UTabs
                        :model-value="board.limit" @update:model-value="board.setLimit($event as GroupLeaderboardLimit)"
                        :items="limitTabs"
                        :content="false"
                        variant="pill"
                        size="xs"
                        class="shrink-0"
                    />
                </div>
                <UTabs
                    :model-value="board.period" @update:model-value="board.setPeriod($event as GroupLeaderboardPeriod)"
                    :items="periodTabs"
                    :content="false"
                    variant="link"
                    size="sm"
                    class="mt-3 w-full"
                />
            </div>
        </template>

        <div v-if="board.loading" class="divide-y divide-default" role="status" aria-busy="true" :aria-label="t('groups.leaderboard.loading.label')">
            <div
                v-for="row in 10"
                :key="row"
                class="grid grid-cols-[2.5rem_minmax(0,1fr)_4rem] items-center gap-3 px-4 py-3"
            >
                <USkeleton class="h-6 w-8 motion-reduce:animate-none" />
                <div class="flex items-center gap-3">
                    <USkeleton class="size-9 shrink-0 motion-reduce:animate-none" />
                    <div class="w-full space-y-2">
                        <USkeleton class="h-4 w-36 max-w-full motion-reduce:animate-none" />
                        <USkeleton class="h-3 w-24 max-w-full motion-reduce:animate-none" />
                    </div>
                </div>
                <USkeleton class="h-7 w-14 motion-reduce:animate-none" />
            </div>
        </div>
        <UAlert v-else-if="board.error" class="m-4" color="error" variant="soft"
            :title="t('groups.leaderboard.loading.error_title')">
            <template #actions>
                <UButton :label="t('groups.leaderboard.loading.retry')" color="error" variant="soft" @click="board.retry()" />
            </template>
        </UAlert>
        <div
            v-else-if="board.entries.length > 0"
            class="max-h-[38rem] divide-y divide-default overflow-y-auto overscroll-contain [scrollbar-gutter:stable]"
        >
            <div
                v-for="entry in board.entries"
                :key="entry.character.id ?? entry.character.name"
                class="grid grid-cols-[2.5rem_minmax(0,1fr)_auto] items-center gap-3 px-4 py-3"
            >
                <p class="text-lg font-semibold" :class="rankTone(entry.rank)">
                    #{{ entry.rank }}
                </p>
                <div class="flex min-w-0 items-center gap-3">
                    <img
                        v-if="entry.character.avatar_url"
                        :src="entry.character.avatar_url"
                        :alt="entry.character.name"
                        class="size-9 rounded-sm object-cover"
                    >
                    <div v-else class="flex size-9 shrink-0 items-center justify-center rounded-sm bg-primary/10 text-xs font-semibold text-primary">
                        {{ entry.character.name.slice(0, 2) }}
                    </div>
                    <div class="min-w-0">
                        <p class="truncate font-medium text-toned">
                            {{ entry.character.name }}
                        </p>
                        <p class="truncate text-xs text-muted">
                            {{ characterWorld(entry) || formatDate(entry.latest_activity_at) }}
                        </p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="font-semibold text-toned">
                        {{ formatNumber(entry.count) }}
                    </p>
                    <p class="text-xs text-muted">
                        {{ t("groups.leaderboard.common.runs") }}
                    </p>
                </div>
            </div>
        </div>
        <div v-else class="p-4 text-sm text-muted">
            {{ t(`groups.leaderboard.empty.${board.category}`) }}
        </div>
    </UCard>
</template>
