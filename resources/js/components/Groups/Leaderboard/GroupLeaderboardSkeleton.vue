<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
    <div class="mt-4 space-y-6" role="status" aria-busy="true" :aria-label="t('groups.leaderboard.loading.label')">
        <span class="sr-only">{{ t('groups.leaderboard.loading.label') }}</span>
        <div aria-hidden="true" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <UCard v-for="metric in 4" :key="metric" :ui="{ body: 'space-y-4 p-4 sm:p-4' }">
                <USkeleton class="h-4 w-2/3 motion-reduce:animate-none" />
                <USkeleton class="h-9 w-20 motion-reduce:animate-none" />
            </UCard>
        </div>
        <div aria-hidden="true" class="grid gap-6 xl:grid-cols-2">
            <UCard v-for="featured in 2" :key="featured" :ui="{ body: 'space-y-4' }">
                <USkeleton class="h-5 w-40 motion-reduce:animate-none" />
                <USkeleton class="h-28 w-full motion-reduce:animate-none" />
            </UCard>
        </div>
        <div aria-hidden="true" class="grid gap-6 xl:grid-cols-3">
            <UCard v-for="board in 4" :key="board" :class="board === 4 ? 'xl:col-span-3' : ''">
                <template #header><USkeleton class="h-6 w-40 motion-reduce:animate-none" /></template>
                <div class="space-y-4">
                    <USkeleton v-for="row in 10" :key="row" class="h-10 w-full motion-reduce:animate-none" />
                </div>
            </UCard>
        </div>
    </div>
</template>
