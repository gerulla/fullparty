<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
    <div class="mt-4 space-y-6" role="status" aria-busy="true" :aria-label="t('groups.statistics.loading.label')">
        <span class="sr-only">{{ t('groups.statistics.loading.label') }}</span>
        <div aria-hidden="true" class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <UCard v-for="metric in 5" :key="metric" :ui="{ body: 'space-y-4 p-4 sm:p-4' }">
                <USkeleton class="h-4 w-2/3 motion-reduce:animate-none" />
                <USkeleton class="h-9 w-20 motion-reduce:animate-none" />
                <USkeleton class="h-3 w-full motion-reduce:animate-none" />
            </UCard>
        </div>
        <div aria-hidden="true" class="grid gap-6 xl:grid-cols-2">
            <UCard v-for="chart in 8" :key="chart" class="dark:bg-elevated/25"
                :class="chart === 3 || chart === 4 ? 'xl:col-span-2' : ''"
                :ui="{ body: 'p-4 sm:p-4' }">
                <template #header>
                    <USkeleton class="mb-2 h-5 w-40 motion-reduce:animate-none" />
                    <USkeleton class="h-4 w-3/4 motion-reduce:animate-none" />
                </template>
                <USkeleton class="h-64 w-full motion-reduce:animate-none" />
            </UCard>
        </div>
    </div>
</template>
