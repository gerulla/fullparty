<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { BozjaHolsterDraft, BozjaItemOption } from '@/Types/Bozja'

const draft = defineModel<BozjaHolsterDraft>({ required: true })
const props = defineProps<{ items: BozjaItemOption[]; capacity: number; maximum: number; busy: boolean }>()
const emit = defineEmits<{ back: [] }>()
const { t } = useI18n()
const k = 'groups.index.content.delubrum_reginae_savage.holsters.'
const search = ref('')
const selectedIds = computed(() => new Set(draft.value.items.map(item => item.id)))
const filtered = computed(() => {
    const query = search.value.trim().toLowerCase()
    return props.items.filter(item => [item.display_name, item.key, item.classification, ...Object.values(item.name ?? {})]
        .some(value => String(value ?? '').toLowerCase().includes(query)))
})
function add(item: BozjaItemOption) {
    if (props.busy || selectedIds.value.has(item.id) || props.capacity + item.cache_weight > props.maximum) return
    draft.value.items.push({ ...item, quantity: 1 })
}
</script>

<template>
    <section class="drs-panel min-w-0 p-4 sm:p-5">
        <header class="mb-4 space-y-3 border-b border-default pb-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">{{ t(k + 'workspace.action_picker') }}</h2>
                <UButton icon="i-lucide-arrow-left" color="neutral" variant="ghost" size="xs" :label="t(k + 'workspace.back_to_details')" @click="emit('back')" />
            </div>
            <p class="text-xs text-muted" aria-live="polite">{{ t(k + 'capacity.remaining', { count: Math.max(0, maximum - capacity) }) }}</p>
            <UInput v-model="search" icon="i-lucide-search" :placeholder="t(k + 'contents.search')" :aria-label="t(k + 'contents.search')" class="w-full" />
        </header>
        <div class="max-h-[60dvh] space-y-1 overflow-y-auto overscroll-contain xl:max-h-[calc(100dvh-20rem)]">
            <button v-for="item in filtered" :key="item.id" type="button"
                class="flex w-full items-center gap-2 rounded-md p-2 text-left transition hover:bg-elevation-3 disabled:cursor-default"
                :class="selectedIds.has(item.id) ? 'bg-brand-500/10 text-primary' : capacity + item.cache_weight > maximum ? 'opacity-40' : ''"
                :disabled="busy || selectedIds.has(item.id) || capacity + item.cache_weight > maximum"
                :aria-pressed="selectedIds.has(item.id)" @click="add(item)">
                <img v-if="item.icon_url" :src="item.icon_url" alt="" class="size-9 shrink-0 object-contain">
                <UIcon v-else name="i-lucide-package" class="size-9 shrink-0 text-muted" />
                <span class="min-w-0 flex-1"><span class="block text-sm font-medium">{{ item.display_name }}</span><span class="block text-xs text-muted">{{ t(k + 'contents.weight', { weight: item.cache_weight }) }}</span></span>
                <UIcon :name="selectedIds.has(item.id) ? 'i-lucide-check' : 'i-lucide-plus'" class="size-4 shrink-0" />
            </button>
            <p v-if="!filtered.length" class="py-8 text-center text-sm text-muted">{{ t(k + 'library_empty') }}</p>
        </div>
    </section>
</template>
