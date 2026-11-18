<script setup lang="ts">
import { useId } from 'vue'
import { useI18n } from 'vue-i18n'
import RunCarousel from '@/components/Runs/RunCarousel.vue'
import RunCardSkeleton from '@/components/Runs/RunCardSkeleton.vue'
import type { RunCardData } from '@/Types/RunCards'

defineProps<{ title: string; runs: RunCardData[]; loading?: boolean }>()
const { t } = useI18n()
const headingId = useId()
</script>

<template>
    <section :aria-labelledby="headingId" :aria-busy="loading" class="w-full min-w-0 space-y-4">
        <h2 :id="headingId" class="min-h-8 pr-24 text-xl font-semibold text-highlighted sm:text-2xl">{{ title }}</h2>

        <div v-if="loading" class="relative min-w-0">
            <p role="status" class="sr-only">{{ t('runs.browse.loading') }}</p>
            <div aria-hidden="true" class="absolute -top-12 right-0 flex gap-2">
                <USkeleton v-for="arrow in 2" :key="arrow" class="size-8 rounded-md bg-elevation-3 dark:bg-elevation-5" />
            </div>
            <div class="-m-1 -me-4 overflow-hidden p-1 sm:-me-6 lg:-me-8 2xl:-me-1">
                <div class="-ms-4 flex">
                    <div v-for="card in 6" :key="card" class="min-w-0 max-w-full shrink-0 basis-[19rem] ps-4 2xl:basis-[max(19rem,20%)]">
                        <RunCardSkeleton type="finder" />
                    </div>
                </div>
            </div>
        </div>
        <RunCarousel v-else-if="runs.length" :runs="runs" :label="title" type="finder" :rows="1" :desktop-columns="5" bleed-end />
        <p v-else class="rounded-2xl border border-default bg-elevation-1 px-6 py-10 text-sm text-muted dark:bg-elevation-2">
            {{ t('runs.browse.empty') }}
        </p>
    </section>
</template>
