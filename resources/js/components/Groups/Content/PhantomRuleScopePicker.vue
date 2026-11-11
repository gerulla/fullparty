<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePhantomRulePresentation } from '@/composables/usePhantomRulePresentation'
import type { PhantomCompositionPartyId, PhantomCompositionRulePlaceholder, PhantomCompositionScopePreset, PhantomJobOption, PhantomRuleScopeSelection } from '@/Types/PhantomComposition'

const props = defineProps<{
    rule: PhantomCompositionRulePlaceholder
    phantomJobs: PhantomJobOption[]
    disabled?: boolean
    initiallyEditing?: boolean
}>()
const emit = defineEmits<{ confirm: [selection: PhantomRuleScopeSelection] }>()
const { t } = useI18n()
const k = 'groups.index.content.forked_tower_blood.compositions.editor.'
const { primaryScopeModeOptions, partyScopeModeOptions, partyOptions, sideOneParties, sideTwoParties,
    defaultScopeParties, normalizeScopeParties, ruleScopeParties, ruleScopeSummary, ruleScopePlainText,
    scopeSummary, scopePlainText, scopeRequiresParties } = usePhantomRulePresentation(() => props.phantomJobs)
const editing = ref(Boolean(props.initiallyEditing))
const scope = ref<PhantomCompositionScopePreset | null>(null)
const parties = ref<PhantomCompositionPartyId[]>([])
const preview = computed(() => scope.value ? scopeSummary(scope.value, parties.value) : null)
const valid = computed(() => scope.value && (!scopeRequiresParties(scope.value) || parties.value.length > 0))

function start() {
    scope.value = null
    parties.value = []
    editing.value = true
}
function chooseScope(value: PhantomCompositionScopePreset) {
    scope.value = value
    parties.value = normalizeScopeParties(value, props.rule.scope === value ? ruleScopeParties(props.rule) : defaultScopeParties(value))
}
function selectParty(party: PhantomCompositionPartyId) {
    if (scope.value === 'one_side') {
        parties.value = sideOneParties.includes(party) ? [...sideOneParties] : [...sideTwoParties]
    } else if (scope.value === 'specific_party') {
        parties.value = [party]
    } else {
        parties.value = parties.value.includes(party) ? parties.value.filter(value => value !== party) : [...parties.value, party]
    }
}
function confirm() {
    if (props.disabled || !scope.value || !valid.value) return
    emit('confirm', { scope: scope.value, parties: normalizeScopeParties(scope.value, parties.value) })
    editing.value = false
}
</script>

<template>
    <fieldset :disabled="disabled" class="@container min-w-0 space-y-3">
        <div v-if="!editing" class="flex min-w-0 items-start gap-3 rounded-lg bg-elevation-1 p-3 dark:bg-elevation-4">
            <UIcon :name="ruleScopeSummary(rule).icon" class="mt-0.5 size-5 shrink-0 text-primary" />
            <div class="min-w-0 flex-1">
                <p class="break-words text-sm font-semibold">{{ ruleScopeSummary(rule).title }}</p>
                <p class="mt-1 text-xs leading-5 text-muted">{{ ruleScopeSummary(rule).description }}</p>
                <p class="mt-3 border-l-2 border-primary/50 pl-3 text-xs leading-5 text-toned">{{ ruleScopePlainText(rule) }}</p>
            </div>
            <UButton icon="i-lucide-pencil" color="neutral" variant="ghost" class="min-h-10 min-w-10 shrink-0 justify-center rounded-md" :aria-label="t(k + 'inbox.edit_scope')" :disabled="disabled" @click="start" />
        </div>
        <template v-else-if="scope === null">
            <div class="grid gap-2 @min-[26rem]:grid-cols-2">
                <button v-for="option in primaryScopeModeOptions" :key="option.value" type="button"
                    class="min-w-0 rounded-lg border border-default bg-elevation-1 p-3 text-left transition hover:border-primary/60 hover:bg-primary/5 dark:bg-elevation-4" @click="chooseScope(option.value)">
                    <span class="flex items-start gap-3">
                        <UIcon :name="option.icon" class="mt-1 size-5 shrink-0 text-primary" />
                        <span class="min-w-0">
                            <span class="flex flex-wrap gap-1"><span v-for="token in option.tokens" :key="token" class="rounded border border-default px-1.5 py-0.5 text-[0.68rem] font-semibold text-muted">{{ token }}</span></span>
                            <span class="mt-2 block text-sm font-semibold">{{ option.label }}</span>
                            <span class="mt-1 block text-xs leading-5 text-muted">{{ option.description }}</span>
                        </span>
                    </span>
                </button>
            </div>
            <div class="grid gap-2 @min-[32rem]:grid-cols-3">
                <button v-for="option in partyScopeModeOptions" :key="option.value" type="button"
                    class="min-w-0 rounded-lg border border-default bg-elevation-1 p-3 text-left transition hover:border-primary/60 hover:bg-primary/5 dark:bg-elevation-4" @click="chooseScope(option.value)">
                    <span class="mb-2 flex flex-wrap gap-1"><span v-for="token in option.tokens" :key="token" class="rounded border border-default px-1.5 py-0.5 text-[0.68rem] font-semibold text-muted">{{ token }}</span></span>
                    <span class="flex items-center gap-2 text-sm font-semibold"><UIcon :name="option.icon" class="size-4 shrink-0 text-primary" />{{ option.label }}</span>
                    <span class="mt-1 block text-xs leading-5 text-muted">{{ option.description }}</span>
                </button>
            </div>
            <UButton color="neutral" variant="ghost" icon="i-lucide-x" :label="t('general.cancel')" class="min-h-10 rounded-md" @click="editing = false" />
        </template>
        <template v-else>
            <div class="flex min-w-0 items-start gap-3 rounded-lg bg-elevation-1 p-3 dark:bg-elevation-4">
                <UIcon :name="preview?.icon" class="mt-1 size-5 shrink-0 text-primary" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold">{{ preview?.title }}</p>
                    <p class="mt-1 text-xs leading-5 text-muted">{{ preview?.description }}</p>
                    <p class="mt-3 border-l-2 border-primary/50 pl-3 text-xs leading-5 text-toned">{{ scopePlainText(rule, scope, parties) }}</p>
                </div>
                <UButton icon="i-lucide-arrow-left" color="neutral" variant="ghost" class="min-h-10 min-w-10 shrink-0 justify-center rounded-md" :aria-label="t('general.back')" @click="scope = null" />
            </div>
            <div v-if="scopeRequiresParties(scope)" class="grid grid-cols-2 gap-2 @min-[24rem]:grid-cols-3">
                <button v-for="party in partyOptions" :key="party.value" type="button" :aria-pressed="parties.includes(party.value)"
                    class="flex min-h-11 min-w-0 items-center justify-between gap-2 rounded-md border px-3 py-2 text-left text-sm transition"
                    :class="parties.includes(party.value) ? 'border-primary bg-primary/10 text-highlighted' : 'border-default bg-elevation-1 text-muted hover:border-primary/50 dark:bg-elevation-4'"
                    @click="selectParty(party.value)">
                    <span class="font-semibold">{{ party.label }}</span>
                    <UIcon :name="parties.includes(party.value) ? 'i-lucide-square-check' : 'i-lucide-square'" class="size-4 shrink-0" />
                </button>
            </div>
            <p v-else class="rounded-lg border border-dashed border-default px-3 py-4 text-sm text-muted">{{ t(k + 'scope_builder.no_party_needed') }}</p>
            <div class="flex justify-between gap-2">
                <UButton color="neutral" variant="ghost" :label="t('general.cancel')" class="min-h-10 rounded-md" @click="editing = false" />
                <UButton icon="i-lucide-check" :label="t('general.confirm')" :disabled="disabled || !valid" class="min-h-10 rounded-md" @click="confirm" />
            </div>
        </template>
    </fieldset>
</template>
