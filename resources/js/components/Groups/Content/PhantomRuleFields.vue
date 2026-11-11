<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePhantomRulePresentation } from '@/composables/usePhantomRulePresentation'
import type { PhantomCompositionRulePlaceholder, PhantomJobOption, PhantomRuleScopeSelection } from '@/Types/PhantomComposition'
import PhantomRuleScopePicker from './PhantomRuleScopePicker.vue'

const props = defineProps<{
    rule: PhantomCompositionRulePlaceholder
    phantomJobs: PhantomJobOption[]
    disabled?: boolean
    initiallyEditing?: boolean
}>()
const emit = defineEmits<{ update: [rule: PhantomCompositionRulePlaceholder] }>()
const { t } = useI18n()
const k = 'groups.index.content.forked_tower_blood.compositions.editor.'
const { phantomJobOptions, targetJobLabel, targetJobIconUrl, ruleTypeLabel, severityOptions, severityDisplay, comparisonOptions } = usePhantomRulePresentation(() => props.phantomJobs)
const jobPickerOpen = ref(Boolean(props.initiallyEditing))
const search = ref('')
const filteredJobs = computed(() => {
    const query = search.value.trim().toLowerCase()
    return phantomJobOptions.value.filter(job => !query || job.label.toLowerCase().includes(query) || job.name.toLowerCase().includes(query))
})
function update(patch: Partial<PhantomCompositionRulePlaceholder>) {
    if (!props.disabled) emit('update', { ...props.rule, ...patch })
}
function selectJob(id: number) {
    update({ target_id: id })
    jobPickerOpen.value = false
}
function confirmScope(selection: PhantomRuleScopeSelection) {
    update({ scope: selection.scope, scope_parties: selection.parties })
}
const severityMenu = computed(() => [severityOptions.value.map(option => ({
    label: option.label, icon: option.icon, color: option.color,
    onSelect: () => update({ severity: option.value }),
}))])
</script>

<template>
    <fieldset :disabled="disabled" class="@container min-w-0">
        <div class="grid min-w-0 gap-4 @min-[56rem]:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)_minmax(0,1.3fr)]">
            <section class="min-w-0 space-y-4 rounded-lg bg-elevation-2 p-4 dark:bg-elevation-3">
                <h4 class="text-sm font-semibold">{{ t(k + 'inbox.job_importance') }}</h4>
                <div v-if="!jobPickerOpen" class="flex min-w-0 items-center gap-3 rounded-md bg-elevation-1 p-3 dark:bg-elevation-4">
                    <img v-if="targetJobIconUrl(rule.target_id)" :src="targetJobIconUrl(rule.target_id) ?? undefined" alt="" class="size-8 shrink-0 object-contain" />
                    <div class="min-w-0 flex-1">
                        <p class="break-words text-sm font-semibold">{{ targetJobLabel(rule.target_id) }}</p>
                        <p class="mt-1 text-xs text-muted">{{ ruleTypeLabel(rule.type) }}</p>
                    </div>
                    <UButton icon="i-lucide-pencil" color="neutral" variant="ghost" :disabled="disabled" :aria-label="t(k + 'inbox.edit_job')" class="min-h-10 min-w-10 shrink-0 justify-center rounded-md" @click="jobPickerOpen = true" />
                </div>
                <div v-else class="space-y-2">
                    <UInput v-model="search" icon="i-lucide-search" :placeholder="t(k + 'job_picker.search_placeholder')" :aria-label="t(k + 'job_picker.search_placeholder')" class="w-full" :ui="{ base: 'min-h-10 rounded-md' }" />
                    <div class="max-h-56 overflow-y-auto rounded-md bg-elevation-1 dark:bg-elevation-4">
                        <button v-for="job in filteredJobs" :key="job.value" type="button" class="flex min-h-12 w-full items-center gap-3 p-3 text-left hover:bg-primary/10" @click="selectJob(job.value)">
                            <img v-if="job.iconUrl" :src="job.iconUrl" alt="" class="size-7 shrink-0 object-contain" />
                            <span class="min-w-0 flex-1"><span class="block text-sm font-medium">{{ job.label }}</span><span class="text-xs text-muted">{{ t(k + 'job_picker.max_level', { level: job.maxLevel }) }}</span></span>
                            <UIcon v-if="rule.target_id === job.value" name="i-lucide-check" class="size-4 shrink-0 text-primary" />
                        </button>
                        <p v-if="!filteredJobs.length" class="p-4 text-sm text-muted">{{ t(k + 'job_picker.no_results') }}</p>
                    </div>
                    <UButton color="neutral" variant="ghost" :label="t('general.cancel')" class="min-h-10 rounded-md" @click="jobPickerOpen = false" />
                </div>
                <UFormField :label="t(k + 'fields.severity')">
                    <UDropdownMenu :items="severityMenu">
                        <UButton :color="severityDisplay(rule.severity).color" variant="subtle" :icon="severityDisplay(rule.severity).icon" trailing-icon="i-lucide-chevron-down" :label="severityDisplay(rule.severity).label" :disabled="disabled" class="min-h-10 w-full justify-between rounded-md" />
                    </UDropdownMenu>
                </UFormField>
            </section>
            <section class="@container min-w-0 space-y-4 rounded-lg bg-elevation-2 p-4 dark:bg-elevation-3">
                <h4 class="text-sm font-semibold">{{ t(k + 'inbox.count_requirement') }}</h4>
                <div class="grid gap-4 @min-[20rem]:grid-cols-2">
                    <UFormField :label="t(k + 'fields.comparison')" class="min-w-0">
                        <USelect :model-value="rule.comparison" :items="comparisonOptions" value-key="value" :disabled="disabled || rule.type === 'duplicate_limit'" class="w-full" :ui="{ base: 'min-h-10 rounded-md' }" @update:model-value="value => update({ comparison: value })" />
                    </UFormField>
                    <UFormField :label="t(k + 'fields.target_count')" class="min-w-0">
                        <UInputNumber :model-value="rule.target_count" :min="0" :max="48" :disabled="disabled" class="w-full" :ui="{ base: 'min-h-10 rounded-md' }" @update:model-value="value => update({ target_count: value ?? 0 })" />
                    </UFormField>
                </div>
            </section>
            <section class="min-w-0 space-y-3 rounded-lg bg-elevation-2 p-4 dark:bg-elevation-3">
                <h4 class="text-sm font-semibold">{{ t(k + 'inbox.where_applies') }}</h4>
                <PhantomRuleScopePicker :rule="rule" :phantom-jobs="phantomJobs" :disabled="disabled" :initially-editing="initiallyEditing" @confirm="confirmScope" />
            </section>
        </div>
    </fieldset>
</template>
