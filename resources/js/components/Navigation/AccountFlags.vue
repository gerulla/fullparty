<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AccountFlagList from '@/components/Navigation/AccountFlagList.vue'
import { useAccountFlags } from '@/composables/useAccountFlags'
import { useAccountFlagsPreview } from '@/composables/useAccountFlagsPreview'
import type { AccountFlag } from '../../Types/AccountFlags'
import { accountFlagPresentation } from '@/utils/accountFlags'

const { t } = useI18n()
const page = usePage()
const { flags: liveFlags } = useAccountFlags()
const { enabled: previewFlags } = useAccountFlagsPreview()
const flags = computed<AccountFlag[]>(() => {
    if (page.props.theme_preview_enabled !== true || !previewFlags.value) return liveFlags.value
    return (['error', 'warning', 'info'] as const).map(severity => ({
        id: `preview:${severity}`, severity,
        title_key: `general.account_flags.preview_${severity}`,
        description_key: 'general.account_flags.preview_description',
        parameters: {}, url: route('account.characters'),
    }))
})
const highest = computed(() => flags.value[0]?.severity ?? 'info')
const open = ref(false)
const top = ref(76)
let header: Element | null = null
let observer: ResizeObserver | null = null
let frame: number | null = null

// The mobile header wraps its search row; use its actual bottom rather than a fixed height.
function measureHeader() {
    if (frame !== null) return
    frame = requestAnimationFrame(() => {
        top.value = Math.max(0, header?.getBoundingClientRect().bottom ?? 64) + 12
        if (window.matchMedia('(min-width: 768px)').matches) open.value = false
        frame = null
    })
}
watch(() => flags.value.length, count => { if (!count) open.value = false })
onMounted(() => {
    header = document.querySelector('[data-account-flags-header]')
    observer = new ResizeObserver(measureHeader)
    if (header) observer.observe(header)
    measureHeader()
    window.addEventListener('resize', measureHeader)
    window.addEventListener('scroll', measureHeader, { passive: true })
})
onBeforeUnmount(() => {
    observer?.disconnect()
    if (frame !== null) cancelAnimationFrame(frame)
    window.removeEventListener('resize', measureHeader)
    window.removeEventListener('scroll', measureHeader)
})
</script>

<template>
    <Teleport to="body">
        <div v-if="flags.length" class="pointer-events-none fixed right-3 z-30 font-inter sm:right-4 lg:right-6"
            :style="{ top: `${top}px`, '--account-flags-top': `${top}px` }">
            <p class="sr-only" role="status" aria-live="polite" aria-atomic="true">
                {{ t('general.account_flags.summary', { count: flags.length, severity: t(`general.account_flags.severity.${highest}`) }) }}
            </p>
            <aside class="pointer-events-auto hidden max-h-[calc(100dvh-var(--account-flags-top)-1rem)] w-88 overflow-y-auto overscroll-contain rounded-xl md:block"
                :aria-label="t('general.account_flags.title')">
                <AccountFlagList :flags="flags" />
            </aside>
            <div class="pointer-events-auto md:hidden">
                <UPopover v-model:open="open" :content="{ side: 'bottom', align: 'end', sideOffset: 8, collisionPadding: 12 }"
                    :ui="{ content: 'w-[min(22rem,calc(100vw-1.5rem))] max-h-[min(75dvh,var(--reka-popover-content-available-height))] overflow-y-auto overscroll-contain rounded-xl bg-default p-3 font-inter ring-default shadow-xl' }">
                    <UButton :color="highest" :icon="accountFlagPresentation[highest].icon" size="xl" square
                        class="relative size-12 justify-center rounded-full shadow-lg"
                        :aria-label="t('general.account_flags.summary', { count: flags.length, severity: t(`general.account_flags.severity.${highest}`) })">
                        <span class="absolute -right-1 -top-1 flex min-w-5 items-center justify-center rounded-full bg-inverted px-1 text-xs leading-5 font-semibold text-inverted" aria-hidden="true">
                            {{ flags.length }}
                        </span>
                    </UButton>
                    <template #content>
                        <section :aria-label="t('general.account_flags.title')">
                            <h2 class="px-1 pb-3 text-sm font-semibold text-highlighted">{{ t('general.account_flags.title') }}</h2>
                            <AccountFlagList :flags="flags" @view="open = false" />
                        </section>
                    </template>
                </UPopover>
            </div>
        </div>
    </Teleport>
</template>
