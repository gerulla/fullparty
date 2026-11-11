import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { translatePhantomJobName } from '@/utils/characterJobTranslations'
import type { PhantomCompositionComparison, PhantomCompositionPartyId, PhantomCompositionRulePlaceholder, PhantomCompositionRuleType, PhantomCompositionScopePreset, PhantomCompositionSeverity, PhantomJobOption } from '@/Types/PhantomComposition'

export function usePhantomRulePresentation(jobs: () => PhantomJobOption[]) {
    const { t } = useI18n()
    const ruleTypeOptions = computed<{ label: string, value: PhantomCompositionRuleType }[]>(() => [
        { label: t('groups.index.content.forked_tower_blood.compositions.editor.rule_types.single_job_count'), value: 'single_job_count' },
        { label: t('groups.index.content.forked_tower_blood.compositions.editor.rule_types.duplicate_limit'), value: 'duplicate_limit' },
    ])

    const comparisonOptions = computed<{ label: string, value: PhantomCompositionComparison }[]>(() => [
        { label: t('groups.index.content.forked_tower_blood.compositions.editor.comparisons.at_least'), value: 'at_least' },
        { label: t('groups.index.content.forked_tower_blood.compositions.editor.comparisons.exactly'), value: 'exactly' },
        { label: t('groups.index.content.forked_tower_blood.compositions.editor.comparisons.at_most'), value: 'at_most' },
    ])

    const severityOptions = computed(() => [
        {
            label: t('groups.index.content.forked_tower_blood.compositions.editor.severities.required'),
            value: 'required' as const,
            icon: 'i-lucide-shield-alert',
            color: 'error' as const,
        },
        {
            label: t('groups.index.content.forked_tower_blood.compositions.editor.severities.recommended'),
            value: 'recommended' as const,
            icon: 'i-lucide-star',
            color: 'primary' as const,
        },
        {
            label: t('groups.index.content.forked_tower_blood.compositions.editor.severities.optional'),
            value: 'optional' as const,
            icon: 'i-lucide-circle',
            color: 'neutral' as const,
        },
    ])

    const partyIds: PhantomCompositionPartyId[] = ['A', 'B', 'C', 'D', 'E', 'F']
    const sideOneParties: PhantomCompositionPartyId[] = ['A', 'B', 'C']
    const sideTwoParties: PhantomCompositionPartyId[] = ['D', 'E', 'F']
    const partyDisplayIds: Partial<Record<PhantomCompositionPartyId, string>> = {
        D: 'D/1',
        E: 'E/2',
        F: 'F/3',
    }

    const partyOptions = computed(() => partyIds.map(id => ({
        label: t('groups.index.content.forked_tower_blood.compositions.editor.parties.party', { party: partyDisplayIds[id] ?? id }),
        value: id,
    })))

    const scopeModeOptions = computed(() => [
        {
            label: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.anywhere.title'),
            description: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.anywhere.description'),
            value: 'anywhere' as const,
            icon: 'i-lucide-users',
            layout: 'wide' as const,
            tokens: [t('groups.index.content.forked_tower_blood.compositions.editor.parties.all')],
        },
        {
            label: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.one_side.title'),
            description: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.one_side.description'),
            value: 'one_side' as const,
            icon: 'i-lucide-git-compare',
            layout: 'wide' as const,
            tokens: ['ABC', 'D/1 E/2 F/3'],
        },
        {
            label: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.specific_party.title'),
            description: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.specific_party.description'),
            value: 'specific_party' as const,
            icon: 'i-lucide-user-round',
            layout: 'normal' as const,
            tokens: ['A'],
        },
        {
            label: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.either_party.title'),
            description: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.either_party.description'),
            value: 'either_party' as const,
            icon: 'i-lucide-list-filter',
            layout: 'normal' as const,
            tokens: ['A', 'D/1', 'F/3'],
        },
        {
            label: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.all_parties.title'),
            description: t('groups.index.content.forked_tower_blood.compositions.editor.scopes.all_parties.description'),
            value: 'all_parties' as const,
            icon: 'i-lucide-list-checks',
            layout: 'normal' as const,
            tokens: ['A', 'D/1', 'F/3'],
        },
    ])

    const primaryScopeModeOptions = computed(() => scopeModeOptions.value.filter(option => option.layout === 'wide'))
    const partyScopeModeOptions = computed(() => scopeModeOptions.value.filter(option => option.layout === 'normal'))

    const phantomJobOptions = computed(() => jobs().map(job => ({
        label: translatePhantomJobName(t, { name: job.name }, job.name),
        name: job.name,
        iconUrl: job.transparent_icon_url || job.icon_url || job.sprite_url || null,
        maxLevel: job.max_level,
        value: job.id,
    })))

    const hasPhantomJobs = computed(() => phantomJobOptions.value.length > 0)

    const optionLabel = <T extends string | number>(options: { label: string, value: T }[], value: T): string => (
        options.find(option => option.value === value)?.label ?? String(value)
    )

    const ruleTypeLabel = (value: PhantomCompositionRuleType): string => optionLabel(ruleTypeOptions.value, value)
    const comparisonLabel = (value: PhantomCompositionComparison): string => optionLabel(comparisonOptions.value, value)

    const comparisonShortLabel = (comparison: PhantomCompositionComparison): string => {
        if (comparison === 'at_least') {
            return '>='
        }

        if (comparison === 'at_most') {
            return '<='
        }

        return '='
    }

    const severityDisplay = (severity: PhantomCompositionSeverity) => (
        severityOptions.value.find(option => option.value === severity) ?? severityOptions.value[0]
    )

    const selectedPhantomJob = (targetId: number) => phantomJobOptions.value.find(option => option.value === targetId) ?? null

    const targetJobLabel = (targetId: number): string => (
        selectedPhantomJob(targetId)?.label
        ?? t('groups.index.content.forked_tower_blood.compositions.editor.unknown_job')
    )

    const targetJobIconUrl = (targetId: number): string | null => selectedPhantomJob(targetId)?.iconUrl ?? null

    const scopeModeOption = (scope: PhantomCompositionScopePreset) => (
        scopeModeOptions.value.find(option => option.value === scope) ?? scopeModeOptions.value[0]
    )

    const formatPartyLetters = (parties: PhantomCompositionPartyId[], separator = ' / '): string => parties.join(separator)

    const formatPartyNames = (parties: PhantomCompositionPartyId[]): string => (
        parties.map(party => partyOptions.value.find(option => option.value === party)?.label ?? party).join(', ')
    )

    const scopeRequiresParties = (scope: PhantomCompositionScopePreset | null): boolean => scope !== null && scope !== 'anywhere'

    const normalizeScopeParties = (
        scope: PhantomCompositionScopePreset,
        parties: PhantomCompositionPartyId[],
    ): PhantomCompositionPartyId[] => {
        const validParties = parties.filter((party, index, items) => partyIds.includes(party) && items.indexOf(party) === index)

        if (scope === 'anywhere') {
            return []
        }

        if (scope === 'one_side') {
            const firstParty = validParties[0] ?? sideOneParties[0]

            return sideOneParties.includes(firstParty) ? [...sideOneParties] : [...sideTwoParties]
        }

        if (scope === 'specific_party') {
            return validParties.slice(0, 1)
        }

        return validParties
    }

    const defaultScopeParties = (scope: PhantomCompositionScopePreset): PhantomCompositionPartyId[] => {
        if (scope === 'one_side') {
            return [...sideOneParties]
        }

        if (scope === 'specific_party') {
            return ['A']
        }

        if (scope === 'either_party' || scope === 'all_parties') {
            return ['A', 'D', 'F']
        }

        return []
    }

    const ruleScopeParties = (rule: PhantomCompositionRulePlaceholder): PhantomCompositionPartyId[] => (
        normalizeScopeParties(rule.scope, rule.scope_parties ?? defaultScopeParties(rule.scope))
    )

    const scopeSummary = (scope: PhantomCompositionScopePreset, parties: PhantomCompositionPartyId[]) => {
        const normalizedParties = normalizeScopeParties(scope, parties)
        const mode = scopeModeOption(scope)
        const partyText = normalizedParties.length > 0
            ? formatPartyLetters(normalizedParties)
            : t('groups.index.content.forked_tower_blood.compositions.editor.parties.all')

        return {
            title: t(`groups.index.content.forked_tower_blood.compositions.editor.scope_display.${scope}.title`, { parties: partyText }),
            description: t(`groups.index.content.forked_tower_blood.compositions.editor.scope_display.${scope}.description`, { parties: partyText }),
            icon: mode.icon,
        }
    }

    const ruleScopeSummary = (rule: PhantomCompositionRulePlaceholder) => scopeSummary(rule.scope, ruleScopeParties(rule))

    const scopePlainText = (
        rule: PhantomCompositionRulePlaceholder,
        scope: PhantomCompositionScopePreset,
        parties: PhantomCompositionPartyId[],
    ): string => {
        const normalizedParties = normalizeScopeParties(scope, parties)
        const partyText = normalizedParties.length > 0
            ? formatPartyNames(normalizedParties)
            : t('groups.index.content.forked_tower_blood.compositions.editor.parties.all')

        return t(`groups.index.content.forked_tower_blood.compositions.editor.scope_plain.${scope}`, {
            job: targetJobLabel(rule.target_id),
            comparison: comparisonLabel(rule.comparison),
            count: rule.target_count,
            parties: partyText,
        })
    }

    const ruleScopePlainText = (rule: PhantomCompositionRulePlaceholder) => (
        scopePlainText(rule, rule.scope, ruleScopeParties(rule))
    )

    return { ruleTypeOptions, comparisonOptions, severityOptions, partyIds, sideOneParties, sideTwoParties, partyOptions, primaryScopeModeOptions, partyScopeModeOptions, phantomJobOptions, hasPhantomJobs, ruleTypeLabel, comparisonLabel, comparisonShortLabel, severityDisplay, selectedPhantomJob, targetJobLabel, targetJobIconUrl, scopeRequiresParties, normalizeScopeParties, defaultScopeParties, ruleScopeParties, scopeSummary, ruleScopeSummary, scopePlainText, ruleScopePlainText }
}
