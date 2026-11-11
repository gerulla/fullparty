<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import { useGroupLeaderboardRankings } from '@/composables/useGroupLeaderboardRankings'
import PageHeader from '@/components/PageHeader.vue'
import GroupLeaderboardContent from '@/components/Groups/Leaderboard/GroupLeaderboardContent.vue'
import GroupLeaderboardSkeleton from '@/components/Groups/Leaderboard/GroupLeaderboardSkeleton.vue'
import type { GroupLeaderboardCacheMeta, GroupLeaderboardGroup, GroupLeaderboardPayload } from '@/Types/GroupLeaderboard'
import { createDateTimeFormatter } from '@/utils/dateTimeFormat'

const props = defineProps<{
    group: GroupLeaderboardGroup
    leaderboard?: GroupLeaderboardPayload | null
    leaderboard_cache?: GroupLeaderboardCacheMeta | null
}>()

const boards = useGroupLeaderboardRankings(() => props.group.slug, () => props.leaderboard)
const { t, locale } = useI18n()
const nowMs = ref(Date.now())
const isRefreshing = ref(false)
const isRetrying = ref(false)
let cooldownTimer: number | undefined

const isLoading = computed(() => props.leaderboard === undefined || isRefreshing.value || isRetrying.value)
const refreshCooldownRemainingSeconds = computed(() => {
    const availableAt = props.leaderboard_cache?.refresh_available_at
    return availableAt ? Math.max(0, Math.ceil((new Date(availableAt).getTime() - nowMs.value) / 1000)) : 0
})
const canRefreshLeaderboard = computed(() => Boolean(props.leaderboard && props.leaderboard_cache)
    && !isLoading.value && refreshCooldownRemainingSeconds.value === 0)
const refreshButtonLabel = computed(() => {
    if (isRefreshing.value) return t('groups.leaderboard.cache.refreshing')
    if (refreshCooldownRemainingSeconds.value > 0) {
        const seconds = refreshCooldownRemainingSeconds.value
        return t('groups.leaderboard.cache.cooldown', { time: `${Math.floor(seconds / 60)}:${(seconds % 60).toString().padStart(2, '0')}` })
    }
    return t('groups.leaderboard.cache.refresh')
})
const refreshButtonTitle = computed(() => {
    const format = (date: string | null) => date
        ? createDateTimeFormatter(locale.value, { dateStyle: 'short', timeStyle: 'short' }).format(new Date(date))
        : t('groups.leaderboard.cache.unknown')
    return props.leaderboard_cache ? t('groups.leaderboard.cache.tooltip', {
        cached: format(props.leaderboard_cache.cached_at), expires: format(props.leaderboard_cache.expires_at),
    }) : undefined
})

function refreshLeaderboard() {
    if (!canRefreshLeaderboard.value) return
    isRefreshing.value = true
    router.post(route('groups.dashboard.leaderboard.refresh', props.group.slug), {}, {
        preserveScroll: true,
        onFinish: () => { isRefreshing.value = false },
    })
}

function retryLeaderboard() {
    if (isRetrying.value) return
    isRetrying.value = true
    router.reload({
        only: ['leaderboard', 'leaderboard_cache'],
        onFinish: () => { isRetrying.value = false },
    })
}

onMounted(() => {
    cooldownTimer = window.setInterval(() => { nowMs.value = Date.now() }, 1000)
})
onBeforeUnmount(() => {
    if (cooldownTimer !== undefined) window.clearInterval(cooldownTimer)
})
</script>

<template>
    <div class="w-full dark:[--ui-primary:var(--color-brand-400)]">
        <PageHeader :title="t('groups.leaderboard.title')" :subtitle="t('groups.leaderboard.subtitle', { group: group.name })">
            <UButton color="neutral" variant="soft" icon="i-lucide-refresh-cw"
                :label="refreshButtonLabel" :title="refreshButtonTitle" :loading="isRefreshing"
                :disabled="!canRefreshLeaderboard" @click="refreshLeaderboard" />
        </PageHeader>

        <GroupLeaderboardSkeleton v-if="isLoading" />
        <UAlert v-else-if="!leaderboard" class="mt-4" color="error" variant="soft"
            icon="i-lucide-circle-alert" :title="t('groups.leaderboard.loading.error_title')"
            :description="t('groups.leaderboard.loading.error_description')">
            <template #actions>
                <UButton color="error" variant="soft" icon="i-lucide-refresh-cw"
                    :label="t('groups.leaderboard.loading.retry')" @click="retryLeaderboard" />
            </template>
        </UAlert>
        <GroupLeaderboardContent v-else :leaderboard="leaderboard" :boards="boards" />
    </div>
</template>
