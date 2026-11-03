<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import PageHeader from '@/components/PageHeader.vue'
import GroupStatisticsContent from '@/components/Groups/Statistics/GroupStatisticsContent.vue'
import GroupStatisticsSkeleton from '@/components/Groups/Statistics/GroupStatisticsSkeleton.vue'
import type { GroupStatisticsCacheMeta, GroupStatisticsGroup, GroupStatisticsPayload } from '@/Types/GroupStatistics'
import { createDateTimeFormatter } from '@/utils/dateTimeFormat'

const props = defineProps<{
    group: GroupStatisticsGroup
    statistics?: GroupStatisticsPayload | null
    statistics_cache?: GroupStatisticsCacheMeta | null
}>()

const { t, locale } = useI18n()
const nowMs = ref(Date.now())
const isRefreshing = ref(false)
const isRetrying = ref(false)
let cooldownTimer: number | undefined

const isLoading = computed(() => props.statistics === undefined || isRefreshing.value || isRetrying.value)
const refreshCooldownRemainingSeconds = computed(() => {
    const availableAt = props.statistics_cache?.refresh_available_at
    return availableAt ? Math.max(0, Math.ceil((new Date(availableAt).getTime() - nowMs.value) / 1000)) : 0
})
const canRefreshStatistics = computed(() => Boolean(props.statistics && props.statistics_cache)
    && !isLoading.value && refreshCooldownRemainingSeconds.value === 0)
const refreshButtonLabel = computed(() => {
    if (isRefreshing.value) return t('groups.statistics.cache.refreshing')
    if (refreshCooldownRemainingSeconds.value > 0) {
        const seconds = refreshCooldownRemainingSeconds.value
        return t('groups.statistics.cache.cooldown', { time: `${Math.floor(seconds / 60)}:${(seconds % 60).toString().padStart(2, '0')}` })
    }
    return t('groups.statistics.cache.refresh')
})
const refreshButtonTitle = computed(() => {
    const format = (date: string | null) => date
        ? createDateTimeFormatter(locale.value, { dateStyle: 'short', timeStyle: 'short' }).format(new Date(date))
        : t('groups.statistics.cache.unknown')
    return props.statistics_cache ? t('groups.statistics.cache.tooltip', {
        cached: format(props.statistics_cache.cached_at), expires: format(props.statistics_cache.expires_at),
    }) : undefined
})

function refreshStatistics() {
    if (!canRefreshStatistics.value) return
    isRefreshing.value = true
    router.post(route('groups.dashboard.statistics.refresh', props.group.slug), {}, {
        preserveScroll: true,
        onFinish: () => { isRefreshing.value = false },
    })
}

function retryStatistics() {
    if (isRetrying.value) return
    isRetrying.value = true
    router.reload({
        only: ['statistics', 'statistics_cache'],
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
    <div class="w-full">
        <PageHeader :title="t('groups.statistics.title')" :subtitle="t('groups.statistics.subtitle', { group: group.name })">
            <UButton color="neutral" variant="soft" icon="i-lucide-refresh-cw"
                :label="refreshButtonLabel" :title="refreshButtonTitle" :loading="isRefreshing"
                :disabled="!canRefreshStatistics" @click="refreshStatistics" />
        </PageHeader>

        <GroupStatisticsSkeleton v-if="isLoading" />
        <UAlert v-else-if="!statistics" class="mt-4" color="error" variant="soft"
            icon="i-lucide-circle-alert" :title="t('groups.statistics.loading.error_title')"
            :description="t('groups.statistics.loading.error_description')">
            <template #actions>
                <UButton color="error" variant="soft" icon="i-lucide-refresh-cw"
                    :label="t('groups.statistics.loading.retry')" @click="retryStatistics" />
            </template>
        </UAlert>
        <GroupStatisticsContent v-else :statistics="statistics" />
    </div>
</template>
