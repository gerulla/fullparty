<script setup lang="ts">
import { onMounted, ref, shallowRef } from 'vue'
import type { Component } from 'vue'
import { useIntersectionObserver } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import type { RunCardData, RunCardType } from '@/Types/RunCards'
import RunCardSkeleton from '@/components/Runs/RunCardSkeleton.vue'

withDefaults(defineProps<{ run: RunCardData; type?: RunCardType }>(), { type: 'default' })
const { t } = useI18n()
const container = ref<HTMLElement | null>(null)
const card = shallowRef<Component | null>(null)
const ready = ref(false)
const failed = ref(false)
const load = async () => {
    failed.value = false
    try {
        card.value = (await import('@/components/Runs/RunCard.vue')).default
    } catch {
        failed.value = true
    }
}
const { stop, isSupported } = useIntersectionObserver(container, entries => {
    if (entries.some(entry => entry.isIntersecting)) {
        stop()
        void load()
    }
}, { rootMargin: '160px' })
onMounted(() => { if (!isSupported.value) void load() })
</script>

<template>
    <div ref="container" class="relative max-w-full" :class="type === 'date' ? 'w-full min-w-0 max-w-md' : 'w-72'"
        :aria-busy="!ready && !failed">
        <component :is="card" v-if="card" :run="run" :type="type" eager-image
            class="transition-opacity duration-200 motion-reduce:transition-none"
            :class="ready ? 'opacity-100' : 'pointer-events-none opacity-0'"
            :inert="!ready" @ready="ready = true" />
        <RunCardSkeleton v-if="!ready && !failed" :type="type" :class="card ? 'absolute inset-0' : ''" />
        <span v-if="!ready && !failed" class="sr-only" role="status">{{ t('general.loading') }}</span>
        <div v-if="failed" class="flex min-h-64 items-center justify-center rounded-xl bg-elevation-1 dark:bg-elevation-2">
            <UButton color="neutral" variant="ghost" icon="i-lucide-refresh-cw" class="rounded-lg"
                :label="t('general.try_again')" @click="load" />
        </div>
    </div>
</template>
