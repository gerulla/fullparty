<script setup lang="ts">
import { computed, provide, ref, useId } from 'vue'
import { formFieldInjectionKey, inputIdInjectionKey } from '@nuxt/ui/composables/useFormField'
import { useI18n } from 'vue-i18n'
import type { HolsterPairValue } from '@/Types/ActivityHolsters'
import type { HolsterPlannerGroup } from '@/Types/HolsterPlanner'
import { holsterPairKey } from '@/utils/holsterPlanner'
import HolsterLoadoutPanel from '@/components/Shared/Holsters/HolsterLoadoutPanel.vue'

const props = defineProps<{ open: boolean; groups: HolsterPlannerGroup[]; selected: HolsterPairValue[]; query: string; multiple?: boolean; disabled?: boolean }>()
const emit = defineEmits<{
    'update:open': [value: boolean]
    'update:query': [value: string]
    toggle: [pair: HolsterPairValue]
    confirm: []
}>()
const { t } = useI18n()
const selectorId = useId()
// The modal's controls must not share the surrounding application field's ID.
provide(formFieldInjectionKey, undefined)
provide(inputIdInjectionKey, ref<string>())
const keys = computed(() => new Set(props.selected.map(holsterPairKey)))
const selectedPrepopIds = computed(() => new Set(props.selected.map(pair => String(pair.prepop_id))))
const pair = (prepop: string | number, refill: string | number): HolsterPairValue => ({ prepop_id: String(prepop), refill_id: String(refill) })
const isSelected = (prepop: string | number, refill: string | number) => keys.value.has(holsterPairKey(pair(prepop, refill)))

function toggleCard(event: MouseEvent, selection: HolsterPairValue) {
    if (props.disabled || event.defaultPrevented || !(event.target instanceof Element)) return
    if (event.target.closest('button, a, input, select, textarea, label, summary, [role="button"], [role="checkbox"], [contenteditable]')) return

    emit('toggle', selection)
}
</script>

<template>
    <UModal :open="open" :title="t('holsters.choose_pairs')" :description="t(multiple ? 'holsters.choose_multiple_hint' : 'holsters.choose_single_hint')" :ui="{ content: 'sm:max-w-4xl', body: 'flex min-h-0 flex-col overflow-hidden p-0 sm:p-0', footer: 'flex-wrap justify-between gap-3' }" @update:open="$emit('update:open', $event)">
        <template #body>
            <div class="shrink-0 border-b border-default p-4">
                <UInput :id="`${selectorId}-search`" :model-value="query" icon="i-lucide-search" class="w-full" :placeholder="t('holsters.search')" :aria-label="t('holsters.search')" @update:model-value="$emit('update:query', String($event))" />
            </div>
            <div class="min-h-0 max-h-[60dvh] overflow-y-auto overscroll-contain p-4 sm:p-5">
                <div v-if="groups.length" class="space-y-8">
                    <section v-for="group in groups" :key="group.prepop.id" class="grid items-start gap-4" :class="{ 'sm:grid-cols-2': !group.standalone }" :aria-label="group.prepop.name">
                        <div
                            class="border p-3 transition-colors"
                            :class="[
                                selectedPrepopIds.has(String(group.prepop.id)) ? 'border-primary bg-primary/5' : 'border-default bg-elevated/40',
                                { 'cursor-pointer': group.standalone && !disabled },
                            ]"
                            @click="group.standalone && toggleCard($event, pair(group.prepop.id, ''))"
                        >
                            <HolsterLoadoutPanel :holster="group.prepop" compact>
                                <template v-if="group.standalone" #heading>
                                    <UCheckbox :id="`${selectorId}-prepop-${group.prepop.id}`" :model-value="isSelected(group.prepop.id, '')" :label="group.prepop.name" :description="t('holsters.standalone')" :disabled="disabled" @update:model-value="$emit('toggle', pair(group.prepop.id, ''))" />
                                </template>
                            </HolsterLoadoutPanel>
                        </div>
                        <div v-if="group.refills.length" class="min-w-0 space-y-3">
                            <div
                                v-for="refill in group.refills"
                                :key="refill.id"
                                class="border p-3 transition-colors"
                                :class="[
                                    isSelected(group.prepop.id, refill.id) ? 'border-primary bg-primary/5' : 'border-default bg-elevated/40',
                                    { 'cursor-pointer': !disabled },
                                ]"
                                @click="toggleCard($event, pair(group.prepop.id, refill.id))"
                            >
                                <HolsterLoadoutPanel :holster="refill" compact>
                                    <template #heading>
                                        <UCheckbox :id="`${selectorId}-pair-${group.prepop.id}-${refill.id}`" :model-value="isSelected(group.prepop.id, refill.id)" :label="refill.name" :description="t('holsters.paired_with', { name: group.prepop.name })" :disabled="disabled" @update:model-value="$emit('toggle', pair(group.prepop.id, refill.id))" />
                                    </template>
                                </HolsterLoadoutPanel>
                            </div>
                        </div>
                    </section>
                </div>
                <p v-else class="py-8 text-center text-sm text-muted">{{ t('holsters.no_results') }}</p>
            </div>
        </template>
        <template #footer>
            <p class="text-sm text-muted" aria-live="polite">{{ t('holsters.selected_count', { count: selected.length }) }}</p>
            <div class="flex gap-2">
                <UButton color="neutral" variant="outline" :label="t('general.cancel')" @click="$emit('update:open', false)" />
                <UButton icon="i-lucide-check" :label="t('holsters.use_selection')" :disabled="disabled" @click="$emit('confirm')" />
            </div>
        </template>
    </UModal>
</template>
