<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useResizeObserver } from '@vueuse/core'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
    hostName: string
    targetProgress?: string | null
    durationHours?: number | null
}>()
const { t } = useI18n()
const row = ref<HTMLElement | null>(null)
const hostLabel = ref<HTMLElement | null>(null)
const targetLabel = ref<HTMLElement | null>(null)
const duration = ref<HTMLElement | null>(null)
const durationFits = ref(false)
const durationLabel = computed(() => props.durationHours
    ? t('groups.activities.create.summary.duration_value', { count: props.durationHours }) : '')
const showDuration = computed(() => Boolean(durationLabel.value) && durationFits.value)

function labelWidth(label: HTMLElement | null): number {
    const item = label?.parentElement
    if (!label || !item) return 0
    const icon = item.firstElementChild
    const iconWidth = icon ? Number.parseFloat(window.getComputedStyle(icon).width) || 0 : 0
    const gap = Number.parseFloat(window.getComputedStyle(item).columnGap) || 0
    // scrollWidth retains the full text width even after CSS applies an ellipsis.
    return label.scrollWidth + iconWidth + gap
}

function measure() {
    if (!row.value?.isConnected || !row.value.clientWidth || !duration.value) return
    const gap = Number.parseFloat(window.getComputedStyle(row.value).columnGap) || 0
    // Equal side space keeps duration centered, without shortening either label first.
    const sideWidth = Math.max(labelWidth(hostLabel.value), labelWidth(targetLabel.value))
    durationFits.value = 2 * sideWidth + duration.value.offsetWidth + 2 * gap <= row.value.clientWidth
}

useResizeObserver([row, hostLabel, targetLabel, duration], measure)
watch([() => props.hostName, () => props.targetProgress, durationLabel], measure, { flush: 'post' })
onMounted(() => {
    measure()
    void document.fonts?.ready.then(measure)
})
</script>

<template>
    <div ref="row" class="relative flex min-w-0 items-center gap-3 overflow-hidden text-xs text-muted">
        <div class="flex min-w-0 items-center gap-1.5" :title="hostName"
            :class="showDuration || !targetProgress ? 'flex-1' : 'max-w-[60%] shrink-0'">
            <UIcon name="i-lucide-user-round" class="size-3.5 shrink-0" aria-hidden="true" />
            <span ref="hostLabel" class="truncate">{{ hostName }}</span>
        </div>
        <!-- Keep hidden duration measurable so it can return when the card grows. -->
        <div v-if="durationLabel" ref="duration" class="flex w-max shrink-0 items-center justify-center gap-1.5 whitespace-nowrap"
            :class="showDuration ? '' : 'pointer-events-none invisible absolute left-0 top-0'"
            :aria-hidden="!showDuration || undefined">
            <UIcon name="i-lucide-clock" class="size-3.5 shrink-0" aria-hidden="true" />
            <span>{{ durationLabel }}</span>
        </div>
        <div class="flex min-w-0 flex-1 items-center justify-end gap-1.5 text-right" :title="targetProgress || undefined">
            <template v-if="targetProgress">
                <UIcon name="i-lucide-flag" class="size-3.5 shrink-0" aria-hidden="true" />
                <span ref="targetLabel" class="truncate">{{ targetProgress }}</span>
            </template>
        </div>
    </div>
</template>
