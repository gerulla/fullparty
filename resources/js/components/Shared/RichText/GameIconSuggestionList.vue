<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { GameIcon } from '@/Types/GameIcons'

const props = defineProps<{ items: GameIcon[]; command: (icon: GameIcon) => void; loading: boolean; failed: boolean; retry: () => void }>()
const { t, locale } = useI18n()
const selected = ref(0)
const list = ref<HTMLElement>()
watch(() => props.items, () => { selected.value = 0 })
function move(delta: number) {
    selected.value = (selected.value + delta + props.items.length) % props.items.length
    void nextTick(() => list.value?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' }))
}
function onKeyDown(event: KeyboardEvent) {
    if (!props.items.length) return false
    if (event.key === 'ArrowUp' || event.key === 'ArrowDown') { move(event.key === 'ArrowUp' ? -1 : 1); return true }
    if (event.key === 'Enter' || event.key === 'Tab') { props.command(props.items[selected.value]); return true }
    return false
}
defineExpose({ onKeyDown })
</script>

<template>
    <div class="game-icon-suggestions border border-default bg-default shadow-xl" @mousedown.prevent>
        <div class="border-b border-default px-3 py-2 text-xs text-muted">{{ t('rich_text.game_icons_hint') }}</div>
        <div v-if="loading" class="p-3 text-sm text-muted" role="status">{{ t('rich_text.game_icons_loading') }}</div>
        <div v-else-if="failed" class="p-3 text-sm"><p class="mb-2">{{ t('rich_text.game_icons_failed') }}</p><UButton size="xs" :label="t('rich_text.game_icons_retry')" @click="retry" /></div>
        <div v-else-if="!items.length" class="p-3 text-sm text-muted" role="status">{{ t('rich_text.game_icons_empty') }}</div>
        <div v-else ref="list" role="listbox" :aria-label="t('rich_text.game_icons')" class="max-h-[min(20rem,45dvh,var(--game-icon-list-height,20rem))] overflow-y-auto overscroll-contain p-1">
            <button v-for="(item, index) in items" :key="item.key" type="button" role="option" :aria-selected="index === selected" :aria-label="`:${item.shortcode}: ${item.names[locale] || item.names.en}`" class="flex w-full items-center gap-3 px-2 py-2 text-left" :class="index === selected ? 'bg-primary/15' : 'hover:bg-elevated'" @mouseenter="selected = index" @click="command(item)">
                <img :src="item.src" alt="" class="size-8 shrink-0 object-contain" loading="lazy" />
                <span class="min-w-0 flex-1"><span class="block truncate text-sm text-highlighted">{{ item.names[locale] || item.names.en }}</span><span class="block truncate font-mono text-xs text-muted">:{{ item.shortcode }}:</span></span>
                <span class="max-w-24 shrink-0 text-right text-[10px] text-muted">{{ t(`rich_text.game_icon_categories.${item.category}`) }}<span v-if="item.job" class="block">{{ item.job }}</span></span>
            </button>
        </div>
    </div>
</template>
