<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { BozjaHolsterSummary } from '@/Types/Bozja'
import { holsterRoles } from '@/utils/bozjaHolsterRoles'

const props = defineProps<{ holsters: BozjaHolsterSummary[]; selectedId: number | null; busy: boolean }>()
const emit = defineEmits<{ select: [holster: BozjaHolsterSummary]; create: []; toggle: [holster: BozjaHolsterSummary] }>()
const { t } = useI18n()
const k = 'groups.index.content.delubrum_reginae_savage.holsters.'
const search = ref('')
const status = ref('all')
const expanded = ref<number[]>([])
const statusOptions = computed(() => ['all', 'active', 'inactive'].map(value => ({ value, label: t(k + 'filters.' + value) })))
const prepops = computed(() => props.holsters.filter(h => h.type === 'prepop'))
const refillsFor = (id: number) => props.holsters.filter(h => h.parent_holster_id === id && h.type === 'refill')
const matches = (h: BozjaHolsterSummary) => {
    const query = search.value.trim().toLowerCase()
    return (status.value === 'all' || h.is_active === (status.value === 'active'))
        && (!query || [h.display_name, h.notes, ...Object.values(h.name ?? {}), ...h.items.map(item => item.display_name)].some(value => String(value ?? '').toLowerCase().includes(query)))
}
const groups = computed(() => [...holsterRoles, { value: null, label: k + 'role_unset', icon: '' }].map(role => ({
    ...role, holsters: prepops.value.filter(h => h.role === role.value && (matches(h) || refillsFor(h.id).some(matches))),
})).filter(group => group.holsters.length))
const isExpanded = (h: BozjaHolsterSummary) => expanded.value.includes(h.id) || Boolean(search.value.trim()) || refillsFor(h.id).some(r => r.id === props.selectedId)
function toggleExpanded(id: number) { expanded.value = expanded.value.includes(id) ? expanded.value.filter(value => value !== id) : [...expanded.value, id] }
</script>

<template>
    <aside class="drs-panel min-w-0 self-start p-4 xl:sticky xl:top-20">
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-lg font-semibold">{{ t(k + 'workspace.library') }}</h2>
            <UButton size="xs" color="neutral" variant="outline" icon="i-lucide-plus" :label="t(k + 'new_title')" :disabled="busy" @click="emit('create')" />
        </div>
        <p class="mt-1 text-xs text-muted">{{ t(k + 'workspace.count', { total: prepops.length, active: prepops.filter(h => h.is_active).length }) }}</p>
        <div class="my-4 flex gap-2">
            <UInput v-model="search" icon="i-lucide-search" :placeholder="t(k + 'search_placeholder')" :aria-label="t(k + 'search_placeholder')" class="min-w-0 flex-1" />
            <UPopover>
                <UButton icon="i-lucide-sliders-horizontal" color="neutral" variant="outline" :aria-label="t(k + 'workspace.filter')" :class="status !== 'all' ? 'text-primary' : ''" />
                <template #content><USelect v-model="status" :items="statusOptions" :aria-label="t(k + 'workspace.filter')" class="m-3 w-36" /></template>
            </UPopover>
        </div>
        <div class="overflow-y-auto xl:max-h-[calc(100dvh-19rem)]">
            <section v-for="group in groups" :key="group.value ?? 'unset'" class="border-t border-default py-3">
                <h3 class="mb-2 flex items-center gap-2 text-sm font-semibold">
                    <img v-if="group.icon" :src="group.icon" alt="" class="size-5 object-contain">
                    {{ t(group.label) }} <span class="text-muted">({{ group.holsters.length }})</span>
                </h3>
                <div v-for="holster in group.holsters" :key="holster.id">
                    <div class="flex items-center gap-1 rounded-md border-l-4 pr-2" :class="selectedId === holster.id ? 'border-brand-400 bg-brand-500/15' : 'border-transparent hover:bg-elevation-3'">
                        <UButton v-if="refillsFor(holster.id).length" :icon="isExpanded(holster) ? 'i-lucide-chevron-down' : 'i-lucide-chevron-right'" size="xs" color="neutral" variant="ghost" :aria-label="t(k + (isExpanded(holster) ? 'hide_refills' : 'show_refills'))" :aria-expanded="isExpanded(holster)" @click="toggleExpanded(holster.id)" />
                        <button type="button" class="flex min-w-0 flex-1 items-center gap-2 py-3 text-left focus-visible:outline-primary" :disabled="busy" :aria-current="selectedId === holster.id ? 'true' : undefined" @click="emit('select', holster)">
                            <img v-if="group.icon" :src="group.icon" alt="" class="size-8 shrink-0 object-contain">
                            <span class="min-w-0"><span class="block truncate text-sm font-semibold">{{ holster.display_name || t(k + 'untitled') }}</span><span class="block text-xs text-muted">{{ t(k + 'types.prepop') }} · {{ holster.capacity_used }}/{{ holster.max_capacity }}</span></span>
                        </button>
                        <UIcon v-if="holster.is_default" name="i-lucide-star" class="size-4 shrink-0 text-amber-500" :aria-label="t(k + 'workspace.default')" />
                        <USwitch class="xl:hidden 2xl:inline-flex" size="xs" :model-value="holster.is_active" :disabled="busy" :aria-label="t(k + 'active') + ': ' + holster.display_name" @update:model-value="emit('toggle', holster)" />
                    </div>
                    <div v-if="isExpanded(holster)" class="ml-5 border-l border-default pl-3">
                        <div v-for="refill in refillsFor(holster.id).filter(matches)" :key="refill.id" class="flex items-center gap-2 rounded-md px-2" :class="selectedId === refill.id ? 'bg-brand-500/15' : 'hover:bg-elevation-3'">
                            <button type="button" class="flex min-w-0 flex-1 items-center gap-2 py-2 text-left" :disabled="busy" :aria-current="selectedId === refill.id ? 'true' : undefined" @click="emit('select', refill)">
                                <UIcon name="i-lucide-copy" class="size-4 shrink-0 text-muted" />
                                <span class="min-w-0"><span class="block truncate text-sm">{{ refill.display_name || t(k + 'untitled') }}</span><span class="block text-xs text-muted">{{ t(k + 'types.refill') }} · {{ refill.capacity_used }}/{{ refill.max_capacity }}</span></span>
                            </button>
                            <USwitch class="xl:hidden 2xl:inline-flex" size="xs" :model-value="refill.is_active" :disabled="busy" :aria-label="t(k + 'active') + ': ' + refill.display_name" @update:model-value="emit('toggle', refill)" />
                        </div>
                    </div>
                </div>
            </section>
            <p v-if="!groups.length" class="py-8 text-center text-sm text-muted">{{ t(k + 'workspace.no_matches') }}</p>
        </div>
    </aside>
</template>
