<script setup lang="ts">
import type { HolsterPairOption, HolsterPairValue } from '@/Types/ActivityHolsters'
import { useI18n } from 'vue-i18n'
import { usePage } from '@inertiajs/vue3'
import { useHolsterPairPlanner } from '@/composables/useHolsterPairPlanner'
import { holsterPairKey } from '@/utils/holsterPlanner'
import HolsterPairPanel from '@/components/Shared/Holsters/HolsterPairPanel.vue'
import HolsterSelectMenu from './HolsterSelectMenu.vue'

const props = defineProps<{ modelValue: unknown; options: HolsterPairOption[]; multiple?: boolean; disabled?: boolean; allowedPairs?: HolsterPairValue[] }>()
const emit = defineEmits<{ 'update:modelValue': [value: HolsterPairValue | HolsterPairValue[]] }>()
const { t, locale } = useI18n()
const page = usePage()
const planner = useHolsterPairPlanner(props, () => locale.value, () => String(page.props.locale?.fallback ?? 'en'), value => emit('update:modelValue', value))
</script>

<template>
    <div class="space-y-3">
        <ul v-if="planner.selectedLoadouts.value.length" class="grid items-start gap-3" :class="{ 'xl:grid-cols-2 4xl:grid-cols-3': multiple }">
            <li v-for="selection in planner.selectedLoadouts.value" :key="holsterPairKey(selection.pair)" class="min-w-0 border border-default bg-elevated/20 p-3">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <span class="text-xs font-medium text-muted">{{ t('holsters.selected_pair') }}</span>
                    <UButton color="neutral" variant="ghost" size="xs" icon="i-lucide-x" :aria-label="t('holsters.remove_pair')" :disabled="disabled" @click="planner.remove(selection.pair)" />
                </div>
                <HolsterPairPanel v-if="selection.available && selection.prepop" :prepop="selection.prepop" :refills="selection.refill ? [selection.refill] : []" compact />
                <p v-else class="text-sm text-warning">{{ t('holsters.unavailable_pair') }}</p>
            </li>
        </ul>
        <UButton color="neutral" variant="outline" icon="i-lucide-backpack" :label="t(planner.selectedLoadouts.value.length ? 'holsters.change_selection' : 'holsters.choose_pairs')" :disabled="disabled" @click="planner.show" />
        <HolsterSelectMenu v-model:open="planner.open.value" v-model:query="planner.query.value" :groups="planner.filteredGroups.value" :selected="planner.validDraft.value" :multiple="multiple" :disabled="disabled" @toggle="planner.toggle" @confirm="planner.confirm" />
    </div>
</template>
