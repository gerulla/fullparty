<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGameIconCatalog } from '@/composables/useGameIconCatalog'
import { searchGameIcons } from '@/utils/gameIcons'
import type { GameIcon, GameIconCategory } from '@/Types/GameIcons'

defineProps<{ selected?: string }>()
const emit = defineEmits<{ select: [icon: GameIcon] }>()
const { t, locale } = useI18n()
const catalog = useGameIconCatalog()
const query = ref('')
const category = ref<GameIconCategory | 'all'>('all')
const page = ref(1)
const pageSize = 60
const loading = ref(false)
const failed = ref(false)
const scroller = ref<HTMLElement>()
const categories = computed(() => [
    { value: 'all', label: t('rich_text.game_icons_all_categories') },
    ...[...new Set(catalog.icons.value.map(icon => icon.category))].map(value => ({ value, label: t(`rich_text.game_icon_categories.${value}`) })),
])
const matches = computed(() => searchGameIcons(
    catalog.icons.value.filter(icon => category.value === 'all' || icon.category === category.value),
    query.value, catalog.icons.value.length,
))
const visible = computed(() => matches.value.slice((page.value - 1) * pageSize, page.value * pageSize))
const name = (icon: GameIcon) => icon.names[locale.value] || icon.names.en
watch([query, category], () => { page.value = 1 })
watch([query, category, page], async () => { await nextTick(); scroller.value?.scrollTo({ top: 0 }) })
async function load() {
    loading.value = true
    failed.value = false
    try { await catalog.load() }
    catch { failed.value = true }
    finally { loading.value = false }
}
onMounted(load)
</script>

<template>
    <div class="flex min-h-0 min-w-0 flex-1 flex-col">
        <div class="flex flex-wrap gap-2 border-b border-default p-3">
            <UInput v-model="query" icon="i-lucide-search" :placeholder="t('rich_text.game_icons_search')" :aria-label="t('rich_text.game_icons_search')" class="min-w-40 flex-1" />
            <USelect v-model="category" :items="categories" :aria-label="t('rich_text.game_icons_category')" class="w-full sm:w-44" />
        </div>
        <div v-if="loading" role="status" class="flex flex-1 items-center justify-center p-6 text-sm text-muted">{{ t('rich_text.game_icons_loading') }}</div>
        <div v-else-if="failed" role="alert" class="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center text-sm"><p>{{ t('rich_text.game_icons_failed') }}</p><UButton :label="t('rich_text.game_icons_retry')" @click="load" /></div>
        <div v-else-if="!matches.length" role="status" class="flex flex-1 items-center justify-center p-6 text-center text-sm text-muted">{{ t('rich_text.game_icons_empty') }}</div>
        <div v-else ref="scroller" class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-3">
            <div class="grid grid-cols-[repeat(auto-fill,minmax(110px,1fr))] gap-2">
                <button v-for="icon in visible" :key="icon.key" type="button" :title="`${name(icon)} · :${icon.shortcode}:`" :aria-label="`${name(icon)} :${icon.shortcode}:`" :aria-pressed="selected === icon.src" class="flex min-w-0 flex-col items-center gap-2 border border-default p-3 hover:bg-elevated focus-visible:outline-2 focus-visible:outline-primary" :class="{ 'bg-primary/10 ring-2 ring-primary': selected === icon.src }" @click="emit('select', icon)">
                    <img :src="icon.src" alt="" loading="lazy" class="size-10 object-contain" />
                    <span class="w-full truncate text-center text-sm">{{ name(icon) }}</span>
                    <span class="w-full truncate text-center font-mono text-[10px] text-muted">:{{ icon.shortcode }}:</span>
                </button>
            </div>
        </div>
        <div class="flex shrink-0 flex-wrap items-center justify-between gap-2 border-t border-default p-3">
            <span class="text-sm text-muted">{{ t('rich_text.game_icons_count', { count: matches.length }) }}</span>
            <UPagination v-model:page="page" :total="matches.length" :items-per-page="pageSize" :sibling-count="0" :disabled="loading || failed" />
        </div>
    </div>
</template>
