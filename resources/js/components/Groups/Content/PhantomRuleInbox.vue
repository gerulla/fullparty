<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { usePhantomRulePresentation } from '@/composables/usePhantomRulePresentation'
import type { PhantomCompositionRulePlaceholder, PhantomCompositionSeverity, PhantomJobOption } from '@/Types/PhantomComposition'
import PhantomRuleFields from './PhantomRuleFields.vue'

const props = defineProps<{
    rules: PhantomCompositionRulePlaceholder[]
    phantomJobs: PhantomJobOption[]
    disabled?: boolean
    newRuleId?: number | null
}>()
const emit = defineEmits<{
    add: []
    update: [rule: PhantomCompositionRulePlaceholder]
    duplicate: [rule: PhantomCompositionRulePlaceholder]
    remove: [id: number]
    move: [id: number, direction: -1 | 1]
}>()
const { t } = useI18n()
const k = 'groups.index.content.forked_tower_blood.compositions.editor.'
const { targetJobLabel, targetJobIconUrl, severityDisplay, severityOptions, ruleScopeSummary, comparisonShortLabel } = usePhantomRulePresentation(() => props.phantomJobs)
const isTablet = useBreakpoints(breakpointsTailwind).greaterOrEqual('md')
const selectedId = ref<number | null>(props.rules[0]?.id ?? null)
const mobileEditorOpen = ref(false)
const heading = ref<HTMLElement | null>(null)
const searchOpen = ref(false)
const search = ref('')
const severity = ref<PhantomCompositionSeverity | 'all'>('all')
const filters = computed(() => [{ label: t(k + 'inbox.all_rules'), value: 'all' }, ...severityOptions.value])
const filteredRules = computed(() => {
    const query = search.value.trim().toLowerCase()
    return props.rules.filter(rule => (severity.value === 'all' || rule.severity === severity.value)
        && (!query || [rule.label, targetJobLabel(rule.target_id), ruleScopeSummary(rule).title].some(text => text.toLowerCase().includes(query))))
})
const selectedRule = computed(() => props.rules.find(rule => rule.id === selectedId.value) ?? null)
const selectedIndex = computed(() => props.rules.findIndex(rule => rule.id === selectedId.value))
const filteredIndex = computed(() => filteredRules.value.findIndex(rule => rule.id === selectedId.value))
const editorVisible = computed(() => Boolean(selectedRule.value) && (isTablet.value || mobileEditorOpen.value))
const listVisible = computed(() => isTablet.value || !mobileEditorOpen.value)

watch(() => props.rules.map(rule => rule.id), ids => {
    if (selectedId.value !== null && ids.includes(selectedId.value)) return
    selectedId.value = ids[0] ?? null
    if (!ids.length) mobileEditorOpen.value = false
})

async function focusHeading() {
    await nextTick()
    if (!isTablet.value) {
        heading.value?.focus({ preventScroll: true })
        heading.value?.scrollIntoView({ block: 'start' })
    }
}
function selectRule(id: number) {
    if (props.disabled) return
    selectedId.value = id
    mobileEditorOpen.value = true
    void focusHeading()
}
function openRule(id: number) {
    search.value = ''
    severity.value = 'all'
    selectRule(id)
}
function showList() {
    mobileEditorOpen.value = false
    void focusHeading()
}
function navigate(direction: -1 | 1) {
    const rule = filteredRules.value[filteredIndex.value + direction]
    if (rule) selectRule(rule.id)
}
const actions = computed(() => {
    const rule = selectedRule.value
    if (!rule) return []
    return [[
        { label: t(k + 'duplicate_rule'), icon: 'i-lucide-copy', disabled: props.disabled, onSelect: () => emit('duplicate', rule) },
        { label: t(k + 'inbox.move_up'), icon: 'i-lucide-arrow-up', disabled: props.disabled || selectedIndex.value === 0, onSelect: () => emit('move', rule.id, -1) },
        { label: t(k + 'inbox.move_down'), icon: 'i-lucide-arrow-down', disabled: props.disabled || selectedIndex.value === props.rules.length - 1, onSelect: () => emit('move', rule.id, 1) },
    ], [
        { label: t(k + 'remove_rule'), icon: 'i-lucide-trash-2', color: 'error' as const, disabled: props.disabled, onSelect: () => emit('remove', rule.id) },
    ]]
})
defineExpose({ openRule })
</script>

<template>
    <div ref="heading" tabindex="-1" class="min-w-0 scroll-mt-20 outline-none">
        <div class="grid min-w-0 items-start gap-5 md:grid-cols-[minmax(13rem,0.8fr)_minmax(0,1.4fr)] lg:grid-cols-[minmax(16rem,0.75fr)_minmax(0,1.5fr)]">
            <section v-show="listVisible" class="min-w-0 md:sticky md:top-20">
                <header class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-base font-semibold">{{ t(k + 'rules_title') }} <span class="text-sm font-normal text-muted">({{ rules.length }})</span></h3>
                    <div class="flex gap-1">
                        <UButton icon="i-lucide-search" color="neutral" variant="ghost" :aria-label="t(k + 'inbox.search')" :aria-expanded="searchOpen" class="min-h-10 min-w-10 justify-center rounded-md" @click="searchOpen = !searchOpen" />
                        <UPopover>
                            <UButton icon="i-lucide-list-filter" color="neutral" variant="ghost" :aria-label="t(k + 'inbox.filter')" :class="severity !== 'all' ? 'text-primary' : ''" class="min-h-10 min-w-10 justify-center rounded-md" />
                            <template #content><USelect v-model="severity" :items="filters" :aria-label="t(k + 'inbox.filter')" class="m-3 w-48" :ui="{ base: 'min-h-10 rounded-md' }" /></template>
                        </UPopover>
                        <UButton icon="i-lucide-plus" :aria-label="t(k + 'add_rule')" :disabled="disabled || !phantomJobs.length" class="min-h-10 min-w-10 justify-center rounded-md" @click="emit('add')" />
                    </div>
                </header>
                <UInput v-if="searchOpen || search" v-model="search" icon="i-lucide-search" :placeholder="t(k + 'inbox.search')" :aria-label="t(k + 'inbox.search')" class="mt-3 w-full" :ui="{ base: 'min-h-10 rounded-md' }" />
                <div class="mt-3 space-y-1 md:max-h-[calc(100dvh-14rem)] md:overflow-y-auto">
                    <button v-for="rule in filteredRules" :key="rule.id" type="button" :disabled="disabled"
                        :aria-current="selectedId === rule.id ? 'true' : undefined" :aria-label="t(k + 'inbox.edit_rule', { name: rule.label })"
                        class="flex min-h-20 w-full min-w-0 items-center gap-3 rounded-lg border px-3 py-3 text-left transition"
                        :class="selectedId === rule.id ? 'border-primary/60 bg-primary/10' : 'border-transparent border-b-default hover:bg-elevation-2 dark:hover:bg-elevation-3'"
                        @click="selectRule(rule.id)">
                        <img v-if="targetJobIconUrl(rule.target_id)" :src="targetJobIconUrl(rule.target_id) ?? undefined" alt="" class="size-7 shrink-0 object-contain" />
                        <UIcon v-else name="i-lucide-sparkles" class="size-7 shrink-0 text-muted" />
                        <span class="min-w-0 flex-1">
                            <span class="flex items-start justify-between gap-2">
                                <span class="min-w-0 break-words text-sm font-semibold">{{ rule.label === t(k + 'new_rule_label') ? targetJobLabel(rule.target_id) : rule.label }}</span>
                                <UBadge :color="severityDisplay(rule.severity).color" variant="soft" :label="severityDisplay(rule.severity).label" class="shrink-0 rounded" size="sm" />
                            </span>
                            <span class="mt-1 block text-xs leading-5 text-muted">{{ comparisonShortLabel(rule.comparison) }} {{ rule.target_count }} · {{ ruleScopeSummary(rule).title }}</span>
                        </span>
                        <UIcon name="i-lucide-chevron-right" class="size-4 shrink-0 text-muted" />
                    </button>
                    <p v-if="!filteredRules.length" class="py-8 text-center text-sm text-muted">{{ t(k + (rules.length ? 'inbox.no_results' : 'no_rules_description')) }}</p>
                </div>
            </section>
            <section v-if="editorVisible && selectedRule" class="min-w-0 space-y-4">
                <header class="space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <UButton icon="i-lucide-arrow-left" color="neutral" variant="ghost" :label="t(k + 'rules_title')" class="min-h-10 rounded-md md:hidden" @click="showList" />
                        <div class="ml-auto flex items-center gap-1">
                            <UButton icon="i-lucide-chevron-left" color="neutral" variant="ghost" :aria-label="t(k + 'inbox.previous')" :disabled="disabled || filteredIndex <= 0" class="min-h-10 min-w-10 justify-center rounded-md" @click="navigate(-1)" />
                            <span class="text-xs text-muted">{{ t(k + 'inbox.position', { current: selectedIndex + 1, total: rules.length }) }}</span>
                            <UButton icon="i-lucide-chevron-right" color="neutral" variant="ghost" :aria-label="t(k + 'inbox.next')" :disabled="disabled || filteredIndex < 0 || filteredIndex >= filteredRules.length - 1" class="min-h-10 min-w-10 justify-center rounded-md" @click="navigate(1)" />
                            <UDropdownMenu :items="actions"><UButton icon="i-lucide-ellipsis" color="neutral" variant="ghost" :aria-label="t(k + 'inbox.actions')" class="min-h-10 min-w-10 justify-center rounded-md" /></UDropdownMenu>
                        </div>
                    </div>
                    <h3 class="break-words text-lg font-semibold">{{ targetJobLabel(selectedRule.target_id) }}</h3>
                </header>
                <PhantomRuleFields :key="selectedRule.id" :rule="selectedRule" :phantom-jobs="phantomJobs" :disabled="disabled" :initially-editing="newRuleId === selectedRule.id" @update="emit('update', $event)" />
            </section>
            <div v-else-if="isTablet" class="flex min-h-64 items-center justify-center rounded-lg border border-dashed border-default p-6 text-center text-sm text-muted">{{ t(k + 'inbox.select_rule') }}</div>
        </div>
    </div>
</template>
