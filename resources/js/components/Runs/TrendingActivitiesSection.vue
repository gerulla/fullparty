<script setup lang="ts">
import { useId } from 'vue'
import { useI18n } from 'vue-i18n'
import ActivityTypeCard from '@/components/Shared/ActivityTypeCard.vue'
import { localizedValue } from '@/utils/localizedValue'
import type { TrendingRunActivity } from '@/Types/RunDiscovery'

defineProps<{ activities?: TrendingRunActivity[] }>()
const emit = defineEmits<{ select: [activity: string] }>()
const { t, locale } = useI18n()
const headingId = useId()
const slideWidth = 'max-w-full grow basis-60 sm:basis-72 lg:basis-1/4 2xl:basis-1/5 3xl:basis-1/6'
</script>

<template>
    <section v-if="activities === undefined || activities.length" :aria-labelledby="headingId" :aria-busy="activities === undefined" class="min-w-0 space-y-4">
        <h2 :id="headingId" class="min-h-8 pr-24 text-xl font-semibold text-highlighted sm:text-2xl">{{ t('runs.search.trending') }}</h2>
        <div v-if="activities === undefined" aria-hidden="true" class="overflow-hidden">
            <div class="-ms-4 flex">
                <div v-for="index in 6" :key="index" class="min-w-0 shrink-0 ps-4" :class="slideWidth">
                    <USkeleton class="h-40 rounded-2xl bg-elevation-3 sm:h-44 dark:bg-elevation-5" />
                </div>
            </div>
        </div>
        <UCarousel v-else v-slot="{ item }" :items="activities" align="start" arrows drag-free :aria-labelledby="headingId"
            :prev="{ color: 'neutral', variant: 'soft' }" :next="{ color: 'neutral', variant: 'soft' }"
            :ui="{
                item: slideWidth,
                viewport: 'px-1 py-2 -mx-1 -my-2 -me-4 sm:-me-6 lg:-me-1',
                controls: 'absolute right-0 -top-12',
                arrows: 'flex gap-2 [&:not(:has(:enabled))]:hidden',
                prev: 'static translate-y-0 rounded-md',
                next: 'static translate-y-0 rounded-md',
            }">
            <ActivityTypeCard as="button" :title="localizedValue(item.name, locale)" :image="item.image_url"
                class="w-full cursor-pointer rounded-2xl border-0 shadow-md dark:shadow-black/45
                    motion-safe:transition-transform motion-safe:duration-200 motion-safe:hover:scale-101
                    after:pointer-events-none after:absolute after:inset-0 after:rounded-[inherit] after:border-b-2 after:border-brand-700 after:opacity-0
                    motion-safe:after:transition-opacity motion-safe:after:duration-200 hover:after:opacity-100 dark:after:border-brand-400
                    focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400"
                @click="emit('select', item.value)">
                <p class="text-xs text-white/80">{{ t('runs.search.trending_count', item.run_count) }}</p>
            </ActivityTypeCard>
        </UCarousel>
    </section>
</template>
