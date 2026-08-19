<script setup lang="ts">
import type { ContextMenuItem } from '@nuxt/ui'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const UTILITY_SECTION_BLOCKS = ['opener', 'odd_burst', 'even_burst', 'cooldown_phase'] as const
const UTILITY_LOOP_BLOCKS = ['loop_start', 'loop_end'] as const
const UTILITY_BLOCK_KINDS = [...UTILITY_SECTION_BLOCKS, ...UTILITY_LOOP_BLOCKS] as const

type TimelineUtilityBlockKind = (typeof UTILITY_BLOCK_KINDS)[number]

const UTILITY_BLOCK_META: Record<TimelineUtilityBlockKind, {
    icon: string
    markerClass: string
    lineClass: string
}> = {
    opener: {
        icon: 'i-lucide-flag',
        markerClass: 'border-primary/60 bg-primary/10 text-primary',
        lineClass: 'bg-primary/70 shadow-[0_0_10px_rgba(168,85,247,0.35)]',
    },
    odd_burst: {
        icon: 'i-pinhead-one',
        markerClass: 'border-warning/60 bg-warning/10 text-warning',
        lineClass: 'bg-warning/70 shadow-[0_0_10px_rgba(251,191,36,0.22)]',
    },
    even_burst: {
        icon: 'i-pinhead-two',
        markerClass: 'border-info/60 bg-info/10 text-info',
        lineClass: 'bg-info/70 shadow-[0_0_10px_rgba(56,189,248,0.22)]',
    },
    cooldown_phase: {
        icon: 'i-lucide-clock-3',
        markerClass: 'border-success/60 bg-success/10 text-success',
        lineClass: 'bg-success/70 shadow-[0_0_10px_rgba(74,222,128,0.22)]',
    },
    loop_start: {
        icon: 'i-lucide-repeat',
        markerClass: 'border-secondary/60 bg-secondary/10 text-secondary',
        lineClass: 'bg-secondary/70 shadow-[0_0_10px_rgba(96,165,250,0.22)]',
    },
    loop_end: {
        icon: 'i-lucide-repeat-2',
        markerClass: 'border-error/60 bg-error/10 text-error',
        lineClass: 'bg-error/70 shadow-[0_0_10px_rgba(248,113,113,0.22)]',
    },
}

type CatalogAction = {
    id: number
    sourceId: number
    name: string
    unlockLevel: number | null
    iconPath: string
    detailUrl: string
    actionCategory: string | null
    castSeconds: number | null
    recastSeconds: number | null
    maxCharges: number | null
}

type CatalogTrait = {
    id: number
    sourceId: number
    name: string
    unlockLevel: number | null
    iconPath: string
    detailUrl: string
}

type CatalogJob = {
    role: string
    id: number
    name: string
    abbreviation: string
    classIconPath: string
    actions: CatalogAction[]
    traits: CatalogTrait[]
}

type CatalogIndex = {
    jobs: CatalogJob[]
}

type TimelineSection = 'prepull' | 'postpull'

type TimelineActionEntry = {
    key: string
    type: 'action'
    action: CatalogAction
    section: TimelineSection
}

type TimelineUtilityEntry = {
    key: string
    type: 'utility'
    kind: TimelineUtilityBlockKind
    section: TimelineSection
}

type TimelineEntry = TimelineActionEntry | TimelineUtilityEntry

type TimelineDisplayEntry = TimelineEntry & {
    timeSeconds: number
    isGcd: boolean
    sectionIndex: number
}

type ActionDetail = {
    name: string
    description: string | null
    effects: string[]
    action_category: {
        name: string | null
    } | null
    timing: {
        cast_seconds: number | null
        recast_seconds: number | null
        max_charges: number | null
    } | null
    costs: {
        primary?: {
            value: number
        }
        secondary?: {
            value: number
        }
    } | null
    range: {
        target_yalms: number | null
        effect_yalms: number | null
    } | null
    job: {
        name: string
        abbreviation: string
    } | null
    unlock_level: number | null
}

type TraitDetail = {
    name: string
    description: string | null
    effects: string[]
    role: string | null
    job: {
        name: string
        abbreviation: string
    } | null
    unlock_level: number | null
}

const props = defineProps<{
    job: string | null
    level: number | null
}>()

const { locale, t } = useI18n()

const catalog = ref<CatalogIndex | null>(null)
const isLoading = ref(false)
const loadError = ref<string | null>(null)
const timelineEntries = ref<TimelineEntry[]>([])
const timelineContextTarget = ref<{ section: TimelineSection, sectionIndex: number }>({
    section: 'postpull',
    sectionIndex: 0,
})
const timelineDropPreview = ref<{ section: TimelineSection, sectionIndex: number } | null>(null)
const draggingActionId = ref<number | null>(null)
const draggingTimelineEntryKey = ref<string | null>(null)
const pressedPopoverKey = ref<string | null>(null)
const openPopoverKey = ref<string | null>(null)
const actionDetails = ref<Map<number, ActionDetail>>(new Map())
const loadingActionDetailIds = ref<Set<number>>(new Set())
const traitDetails = ref<Map<number, TraitDetail>>(new Map())
const loadingTraitDetailIds = ref<Set<number>>(new Set())
let detailPopoverOpenTimer: ReturnType<typeof setTimeout> | null = null

const FALLBACK_GCD_SECONDS = 2.5

const activeJob = computed(() => {
    const job = props.job?.toUpperCase()

    if (!job) {
        return null
    }

    return catalog.value?.jobs.find(entry => entry.abbreviation === job) ?? null
})

const actionMap = computed(() => new Map(activeJob.value?.actions.map(action => [action.id, action]) ?? []))
const gcdActions = computed(() => activeJob.value?.actions.filter(action => isGcdAction(action)) ?? [])
const ogcdActions = computed(() => activeJob.value?.actions.filter(action => isOgcdAction(action)) ?? [])
const enabledTraitCount = computed(() => activeJob.value?.traits.filter(trait => isTraitEnabled(trait)).length ?? 0)
const prepullTimelineEntries = computed(() => timelineDisplayEntriesForSection('prepull'))
const postpullTimelineEntries = computed(() => timelineDisplayEntriesForSection('postpull'))
const hasTimelineEntries = computed(() => timelineEntries.value.length > 0)
const isTimelineDragActive = computed(() => draggingActionId.value !== null || draggingTimelineEntryKey.value !== null)
const timelineContextMenuItems = computed<ContextMenuItem[][]>(() => [
    UTILITY_SECTION_BLOCKS.map(kind => ({
        label: utilityBlockLabel(kind),
        icon: utilityBlockIcon(kind),
        onSelect: () => addUtilityBlock(kind),
    })),
    UTILITY_LOOP_BLOCKS.map(kind => ({
        label: utilityBlockLabel(kind),
        icon: utilityBlockIcon(kind),
        onSelect: () => addUtilityBlock(kind),
    })),
])

onMounted(() => {
    void loadCatalog()
})

watch(activeJob, () => {
    timelineEntries.value = []
})

async function loadCatalog() {
    if (catalog.value || isLoading.value) {
        return
    }

    isLoading.value = true
    loadError.value = null

    try {
        const response = await fetch(localizedEndpoint('/catalog'))

        if (!response.ok) {
            throw new Error(`status ${response.status}`)
        }

        catalog.value = await response.json() as CatalogIndex
    } catch {
        loadError.value = t('calculator.rotation_editor.load_failed')
    } finally {
        isLoading.value = false
    }
}

function isGcdAction(action: CatalogAction) {
    return action.actionCategory === 'Weaponskill' || action.actionCategory === 'Spell'
}

function isOgcdAction(action: CatalogAction) {
    return action.actionCategory === 'Ability'
}

function actionRecastSeconds(action: CatalogAction) {
    return action.recastSeconds && action.recastSeconds > 0 ? action.recastSeconds : FALLBACK_GCD_SECONDS
}

function formatTimelineTime(timeSeconds: number) {
    const formatted = new Intl.NumberFormat(undefined, {
        maximumFractionDigits: 1,
        minimumFractionDigits: Number.isInteger(timeSeconds) ? 0 : 1,
    }).format(timeSeconds)

    return `${formatted}s`
}

function timelineDisplayEntriesForSection(section: TimelineSection) {
    const entries = timelineEntries.value.filter(entry => entry.section === section)
    let gcdCount = 0
    let prepullGcdCount = entries.filter(entry => isTimelineActionEntry(entry) && isGcdAction(entry.action)).length

    return entries.map((entry, sectionIndex) => {
        const isGcd = isTimelineActionEntry(entry) && isGcdAction(entry.action)
        let timeSeconds = 0

        if (isTimelineActionEntry(entry) && isGcd) {
            if (section === 'prepull') {
                prepullGcdCount -= 1
                timeSeconds = -(prepullGcdCount + 1) * actionRecastSeconds(entry.action)
            } else {
                timeSeconds = gcdCount * actionRecastSeconds(entry.action)
                gcdCount += 1
            }
        }

        return {
            ...entry,
            timeSeconds,
            isGcd,
            sectionIndex,
        }
    })
}

function isTimelineActionEntry(entry: TimelineEntry): entry is TimelineActionEntry {
    return entry.type === 'action'
}

function setTimelineContextTarget(section: TimelineSection, sectionIndex: number) {
    timelineContextTarget.value = {
        section,
        sectionIndex,
    }
}

function addUtilityBlock(kind: TimelineUtilityBlockKind) {
    const target = timelineContextTarget.value

    insertTimelineEntry(
        timelineEntries.value,
        {
            key: timelineEntryKey(`utility-${kind}`),
            type: 'utility',
            kind,
            section: target.section,
        },
        target.section,
        target.sectionIndex,
    )
}

function isTraitEnabled(trait: CatalogTrait) {
    if (trait.unlockLevel === null) {
        return true
    }

    return props.level !== null && trait.unlockLevel <= props.level
}

function traitTitle(trait: CatalogTrait) {
    if (trait.unlockLevel === null) {
        return trait.name
    }

    return `${trait.name} - ${t('calculator.rotation_editor.level_short', { level: trait.unlockLevel })}`
}

function startActionDrag(event: DragEvent, action: CatalogAction) {
    hideDetailPopover()
    clearTimelineDropPreview()
    draggingActionId.value = action.id
    draggingTimelineEntryKey.value = null
    event.dataTransfer?.setData('application/x-fullparty-calculator-action-id', String(action.id))
    event.dataTransfer?.setData('text/plain', action.name)

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'copy'
    }
}

function endActionDrag() {
    draggingActionId.value = null
    draggingTimelineEntryKey.value = null
    pressedPopoverKey.value = null
    clearTimelineDropPreview()
}

function dropAction(event: DragEvent) {
    dropTimelineEntry(event, 'postpull', postpullTimelineEntries.value.length)
}

function startTimelineEntryDrag(event: DragEvent, entry: TimelineEntry) {
    hideDetailPopover()
    clearTimelineDropPreview()
    draggingTimelineEntryKey.value = entry.key
    draggingActionId.value = isTimelineActionEntry(entry) ? entry.action.id : null
    event.dataTransfer?.setData('application/x-fullparty-calculator-timeline-key', entry.key)
    event.dataTransfer?.setData('text/plain', timelineEntryLabel(entry))

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move'
    }
}

function dropTimelineEntry(event: DragEvent, section: TimelineSection, sectionIndex: number) {
    clearTimelineDropPreview()

    const actionIdPayload = event.dataTransfer?.getData('application/x-fullparty-calculator-action-id')
    const id = actionIdPayload ? Number(actionIdPayload) : null
    const timelineKey = event.dataTransfer?.getData('application/x-fullparty-calculator-timeline-key')
    const insertIndex = clampedSectionIndex(section, sectionIndex, timelineKey)

    if (timelineKey) {
        const existingEntry = timelineEntries.value.find(entry => entry.key === timelineKey)

        if (!existingEntry) {
            return
        }

        insertTimelineEntry(
            timelineEntries.value.filter(entry => entry.key !== timelineKey),
            {
                ...existingEntry,
                section,
            },
            section,
            insertIndex,
        )

        return
    }

    const action = id === null ? null : actionMap.value.get(id)

    if (!action) {
        return
    }

    insertTimelineEntry(
        timelineEntries.value,
        {
            key: timelineEntryKey(`action-${action.id}`),
            type: 'action',
            action,
            section,
        },
        section,
        insertIndex,
    )
}

function removeTimelineEntry(key: string) {
    timelineEntries.value = timelineEntries.value.filter(entry => entry.key !== key)
}

function insertTimelineEntry(entries: TimelineEntry[], entry: TimelineEntry, section: TimelineSection, sectionIndex: number) {
    const prepullEntries = entries.filter(item => item.section === 'prepull')
    const postpullEntries = entries.filter(item => item.section === 'postpull')
    const targetEntries = section === 'prepull' ? prepullEntries : postpullEntries

    targetEntries.splice(Math.max(0, Math.min(sectionIndex, targetEntries.length)), 0, {
        ...entry,
        section,
    })
    timelineEntries.value = [...prepullEntries, ...postpullEntries]
}

function clampedSectionIndex(section: TimelineSection, sectionIndex: number, movingKey?: string) {
    const sectionEntries = timelineEntries.value.filter(entry => entry.section === section)
    const movingIndex = movingKey ? sectionEntries.findIndex(entry => entry.key === movingKey) : -1
    let index = Math.max(0, Math.min(sectionIndex, sectionEntries.length))

    if (movingIndex !== -1 && movingIndex < index) {
        index -= 1
    }

    return index
}

function setTimelineDropPreview(section: TimelineSection, sectionIndex: number) {
    if (!isTimelineDragActive.value) {
        return
    }

    timelineDropPreview.value = {
        section,
        sectionIndex,
    }
}

function clearTimelineDropPreview() {
    timelineDropPreview.value = null
}

function isTimelineDropPreview(section: TimelineSection, sectionIndex: number) {
    return timelineDropPreview.value?.section === section
        && timelineDropPreview.value.sectionIndex === sectionIndex
}

function timelineEntryKey(prefix: string) {
    return `${prefix}-${Date.now()}-${timelineEntries.value.length}`
}

function timelineEntryLabel(entry: TimelineEntry) {
    return isTimelineActionEntry(entry) ? entry.action.name : utilityBlockLabel(entry.kind)
}

function utilityBlockLabel(kind: TimelineUtilityBlockKind) {
    return t(`calculator.rotation_editor.utility_blocks.${kind}`)
}

function utilityBlockIcon(kind: TimelineUtilityBlockKind) {
    return UTILITY_BLOCK_META[kind].icon
}

function utilityBlockMarkerClass(kind: TimelineUtilityBlockKind) {
    return UTILITY_BLOCK_META[kind].markerClass
}

function utilityBlockLineClass(kind: TimelineUtilityBlockKind) {
    return UTILITY_BLOCK_META[kind].lineClass
}

function actionPopoverKey(action: CatalogAction) {
    return `action:${action.id}`
}

function traitPopoverKey(trait: CatalogTrait) {
    return `trait:${trait.id}`
}

function scheduleActionPopover(action: CatalogAction) {
    scheduleDetailPopover(actionPopoverKey(action), () => loadActionDetail(action))
}

function scheduleTraitPopover(trait: CatalogTrait) {
    scheduleDetailPopover(traitPopoverKey(trait), () => loadTraitDetail(trait))
}

function scheduleDetailPopover(key: string, loadDetail: () => Promise<void>) {
    if (draggingActionId.value !== null || pressedPopoverKey.value !== null) {
        return
    }

    clearDetailPopoverTimer()
    detailPopoverOpenTimer = setTimeout(() => {
        if (draggingActionId.value !== null || pressedPopoverKey.value !== null) {
            return
        }

        openPopoverKey.value = key
        void loadDetail().catch(() => {})
    }, 500)
}

function hideActionPopover(action?: CatalogAction) {
    hideDetailPopover(action ? actionPopoverKey(action) : undefined)
}

function hideTraitPopover(trait?: CatalogTrait) {
    hideDetailPopover(trait ? traitPopoverKey(trait) : undefined)
}

function hideDetailPopover(key?: string) {
    clearDetailPopoverTimer()

    if (!key || openPopoverKey.value === key) {
        openPopoverKey.value = null
    }
}

function pressAction(action: CatalogAction) {
    pressDetailPopover(actionPopoverKey(action))
}

function pressTrait(trait: CatalogTrait) {
    pressDetailPopover(traitPopoverKey(trait))
}

function pressDetailPopover(key: string) {
    clearDetailPopoverTimer()
    pressedPopoverKey.value = key
    openPopoverKey.value = null
}

function releasePopover() {
    pressedPopoverKey.value = null
}

function clearDetailPopoverTimer() {
    if (!detailPopoverOpenTimer) {
        return
    }

    clearTimeout(detailPopoverOpenTimer)
    detailPopoverOpenTimer = null
}

async function loadActionDetail(action: CatalogAction) {
    if (actionDetails.value.has(action.id) || loadingActionDetailIds.value.has(action.id)) {
        return
    }

    const nextLoading = new Set(loadingActionDetailIds.value)
    nextLoading.add(action.id)
    loadingActionDetailIds.value = nextLoading

    try {
        const response = await fetch(localizedEndpoint(action.detailUrl))

        if (!response.ok) {
            throw new Error(`status ${response.status}`)
        }

        const nextDetails = new Map(actionDetails.value)
        nextDetails.set(action.id, await response.json() as ActionDetail)
        actionDetails.value = nextDetails
    } finally {
        const nextLoading = new Set(loadingActionDetailIds.value)
        nextLoading.delete(action.id)
        loadingActionDetailIds.value = nextLoading
    }
}

async function loadTraitDetail(trait: CatalogTrait) {
    if (traitDetails.value.has(trait.id) || loadingTraitDetailIds.value.has(trait.id)) {
        return
    }

    const nextLoading = new Set(loadingTraitDetailIds.value)
    nextLoading.add(trait.id)
    loadingTraitDetailIds.value = nextLoading

    try {
        const response = await fetch(localizedEndpoint(trait.detailUrl))

        if (!response.ok) {
            throw new Error(`status ${response.status}`)
        }

        const nextDetails = new Map(traitDetails.value)
        nextDetails.set(trait.id, await response.json() as TraitDetail)
        traitDetails.value = nextDetails
    } finally {
        const nextLoading = new Set(loadingTraitDetailIds.value)
        nextLoading.delete(trait.id)
        loadingTraitDetailIds.value = nextLoading
    }
}

function localizedEndpoint(path: string) {
    const language = locale.value.split('-')[0]
    const url = new URL(path, window.location.origin)

    if (language) {
        url.searchParams.set('locale', language)
    }

    return url.toString()
}

function actionDetail(action: CatalogAction) {
    return actionDetails.value.get(action.id) ?? null
}

function isActionDetailLoading(action: CatalogAction) {
    return loadingActionDetailIds.value.has(action.id)
}

function traitDetail(trait: CatalogTrait) {
    return traitDetails.value.get(trait.id) ?? null
}

function isTraitDetailLoading(trait: CatalogTrait) {
    return loadingTraitDetailIds.value.has(trait.id)
}

function actionCategory(action: CatalogAction, detail: ActionDetail | null) {
    return detail?.action_category?.name ?? action.actionCategory ?? t('calculator.rotation_editor.action')
}

function actionDescription(detail: ActionDetail | null) {
    return detail?.description?.trim() || t('calculator.rotation_editor.no_description')
}

function actionJobLevel(action: CatalogAction, detail: ActionDetail | null) {
    const job = detail?.job?.name ?? activeJob.value?.name
    const level = detail?.unlock_level ?? action.unlockLevel

    if (!job && !level) {
        return ''
    }

    if (!level) {
        return job ?? ''
    }

    return `${job ?? activeJob.value?.abbreviation ?? ''} ${t('calculator.rotation_editor.level_short', { level })}`.trim()
}

function traitJobLevel(trait: CatalogTrait, detail: TraitDetail | null) {
    const job = detail?.job?.name ?? activeJob.value?.name
    const level = detail?.unlock_level ?? trait.unlockLevel

    if (!job && !level) {
        return ''
    }

    if (!level) {
        return job ?? ''
    }

    return `${job ?? activeJob.value?.abbreviation ?? ''} ${t('calculator.rotation_editor.level_short', { level })}`.trim()
}

function traitDescription(detail: TraitDetail | null) {
    return detail?.description?.trim() || t('calculator.rotation_editor.no_trait_description')
}

function affinityLabel(detail: Pick<ActionDetail, 'job'> | Pick<TraitDetail, 'job'> | null) {
    const job = detail?.job?.abbreviation ?? activeJob.value?.abbreviation

    return job ? t('calculator.rotation_editor.affinity', { job }) : ''
}

function castTime(action: CatalogAction, detail: ActionDetail | null) {
    const seconds = detail?.timing?.cast_seconds ?? action.castSeconds ?? 0

    return seconds > 0 ? t('calculator.rotation_editor.seconds', { seconds: formatSeconds(seconds) }) : t('calculator.rotation_editor.instant')
}

function recastTime(action: CatalogAction, detail: ActionDetail | null) {
    const seconds = detail?.timing?.recast_seconds ?? action.recastSeconds ?? 0

    return seconds > 0 ? t('calculator.rotation_editor.seconds', { seconds: formatSeconds(seconds) }) : t('calculator.rotation_editor.instant')
}

function rangeLabel(detail: ActionDetail | null) {
    const range = detail?.range?.target_yalms

    if (!Number.isFinite(range)) {
        return ''
    }

    return t('calculator.rotation_editor.yalms', { value: range && range > 0 ? range : 0 })
}

function radiusLabel(detail: ActionDetail | null) {
    const radius = detail?.range?.effect_yalms

    if (!Number.isFinite(radius) || !radius || radius <= 0) {
        return ''
    }

    return t('calculator.rotation_editor.yalms', { value: radius })
}

function costLines(detail: ActionDetail | null) {
    if (!detail?.costs) {
        return []
    }

    return [detail.costs.primary, detail.costs.secondary]
        .map(cost => cost?.value)
        .filter(value => Number.isFinite(value) && value > 0)
        .map(value => t('calculator.rotation_editor.cost', { value }))
}

function formatSeconds(value: number) {
    return new Intl.NumberFormat(undefined, {
        maximumFractionDigits: 2,
    }).format(value)
}
</script>

<template>
    <section class="flex min-h-[28rem] flex-col border border-default bg-default">
        <div class="flex items-center justify-between gap-3 border-b border-default px-3 py-2.5">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-highlighted">
                    {{ t('calculator.rotation_editor.title') }}
                </h2>
                <p class="mt-0.5 text-xs text-muted">
                    <template v-if="activeJob">
                        {{ t('calculator.rotation_editor.detected_job', { job: activeJob.name, abbreviation: activeJob.abbreviation }) }}
                    </template>
                    <template v-else>
                        {{ t('calculator.rotation_editor.no_job') }}
                    </template>
                </p>
            </div>

            <img
                v-if="activeJob"
                :src="activeJob.classIconPath"
                alt=""
                class="size-10 shrink-0 object-contain"
                loading="lazy"
            >
        </div>

        <div
            v-if="loadError"
            class="m-3"
        >
            <UAlert
                color="error"
                variant="subtle"
                icon="i-lucide-circle-alert"
                :description="loadError"
            />
        </div>

        <div
            v-else-if="isLoading"
            class="flex flex-1 items-center justify-center text-sm text-muted"
        >
            <UIcon
                name="i-lucide-loader-circle"
                class="mr-2 size-4 animate-spin"
            />
            {{ t('calculator.rotation_editor.loading') }}
        </div>

        <div
            v-else
            class="flex min-h-0 flex-1 flex-col gap-3 p-3"
        >
            <template v-if="activeJob">
                <section class="min-h-0 border border-default bg-muted">
                    <div class="border-b border-default px-2.5 py-1.5">
                        <h3 class="text-xs font-semibold uppercase text-highlighted">
                            {{ t('calculator.rotation_editor.gcds', { job: activeJob.abbreviation }) }}
                        </h3>
                    </div>

                    <div class="flex max-h-36 flex-wrap gap-2 overflow-y-auto p-2">
                        <UPopover
                            v-for="action in gcdActions"
                            :key="action.id"
                            mode="hover"
                            :open="openPopoverKey === actionPopoverKey(action)"
                            :open-delay="500"
                            :close-delay="80"
                            :content="{ side: 'bottom', align: 'start', collisionPadding: 12 }"
                        >
                            <template #default>
                                <button
                                    type="button"
                                    draggable="true"
                                    class="border border-default bg-default p-1 transition hover:border-primary/60 hover:bg-primary/10 active:cursor-grabbing"
                                    :class="{ 'opacity-50': draggingActionId === action.id }"
                                    :aria-label="action.name"
                                    @mouseenter="scheduleActionPopover(action)"
                                    @mouseleave="hideActionPopover(action)"
                                    @pointerdown="pressAction(action)"
                                    @pointerup="releasePopover"
                                    @pointercancel="releasePopover"
                                    @dragstart="startActionDrag($event, action)"
                                    @dragend="endActionDrag"
                                >
                                    <img
                                        :src="action.iconPath"
                                        alt=""
                                        class="size-12 object-contain"
                                        loading="lazy"
                                    >
                                </button>
                            </template>

                            <template #content>
                                <div class="w-80 border border-default bg-neutral-950 text-neutral-100 shadow-xl">
                                    <div class="flex items-center justify-between gap-3 border-b border-default bg-muted px-2 py-1">
                                        <h4 class="truncate text-sm font-semibold text-highlighted">
                                            {{ actionDetail(action)?.name ?? action.name }}
                                        </h4>
                                        <span class="text-xs text-muted">
                                            x
                                        </span>
                                    </div>

                                    <div class="space-y-2 p-2">
                                        <div class="grid grid-cols-[2.75rem_minmax(0,1fr)_4.75rem] gap-2">
                                            <img
                                                :src="action.iconPath"
                                                alt=""
                                                class="size-10 border border-default bg-default object-contain"
                                                loading="lazy"
                                            >
                                            <div class="min-w-0">
                                                <p class="text-xs font-semibold text-toned">
                                                    {{ actionCategory(action, actionDetail(action)) }}
                                                </p>
                                                <p class="mt-1 truncate text-[11px] text-muted">
                                                    {{ actionJobLevel(action, actionDetail(action)) }}
                                                </p>
                                            </div>
                                            <div class="text-right text-[10px] leading-4 text-muted">
                                                <p v-if="rangeLabel(actionDetail(action))">
                                                    {{ t('calculator.rotation_editor.range') }} {{ rangeLabel(actionDetail(action)) }}
                                                </p>
                                                <p v-if="radiusLabel(actionDetail(action))">
                                                    {{ t('calculator.rotation_editor.radius') }} {{ radiusLabel(actionDetail(action)) }}
                                                </p>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-2 gap-3 border-y border-default py-1.5">
                                            <div>
                                                <p class="text-right text-[10px] uppercase text-muted">
                                                    {{ t('calculator.rotation_editor.cast') }}
                                                </p>
                                                <p class="border-t border-primary/40 pt-0.5 text-right text-sm font-semibold text-highlighted">
                                                    {{ castTime(action, actionDetail(action)) }}
                                                </p>
                                            </div>
                                            <div>
                                                <p class="text-right text-[10px] uppercase text-muted">
                                                    {{ t('calculator.rotation_editor.recast') }}
                                                </p>
                                                <p class="border-t border-primary/40 pt-0.5 text-right text-sm font-semibold text-highlighted">
                                                    {{ recastTime(action, actionDetail(action)) }}
                                                </p>
                                            </div>
                                        </div>

                                        <div class="flex justify-end border border-default bg-muted px-2 py-0.5 text-[10px] font-semibold text-muted">
                                            {{ t('calculator.rotation_editor.stats') }}
                                        </div>

                                        <div
                                            v-if="isActionDetailLoading(action)"
                                            class="py-4 text-center text-xs text-muted"
                                        >
                                            {{ t('calculator.rotation_editor.action_loading') }}
                                        </div>
                                        <div
                                            v-else
                                            class="space-y-2 text-xs leading-5 text-toned"
                                        >
                                            <p class="whitespace-pre-line">
                                                {{ actionDescription(actionDetail(action)) }}
                                            </p>
                                            <div
                                                v-if="costLines(actionDetail(action)).length"
                                                class="space-y-0.5 border-t border-default pt-1 text-[11px] text-muted"
                                            >
                                                <p
                                                    v-for="cost in costLines(actionDetail(action))"
                                                    :key="cost"
                                                >
                                                    {{ cost }}
                                                </p>
                                            </div>
                                            <p
                                                v-if="affinityLabel(actionDetail(action))"
                                                class="border-t border-default pt-1 text-[11px] text-muted"
                                            >
                                                {{ affinityLabel(actionDetail(action)) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </UPopover>
                    </div>
                </section>

                <section class="min-h-0 border border-default bg-muted">
                    <div class="border-b border-default px-2.5 py-1.5">
                        <h3 class="text-xs font-semibold uppercase text-highlighted">
                            {{ t('calculator.rotation_editor.ogcds', { job: activeJob.abbreviation }) }}
                        </h3>
                    </div>

                    <div class="flex max-h-36 flex-wrap gap-2 overflow-y-auto p-2">
                        <UPopover
                            v-for="action in ogcdActions"
                            :key="action.id"
                            mode="hover"
                            :open="openPopoverKey === actionPopoverKey(action)"
                            :open-delay="500"
                            :close-delay="80"
                            :content="{ side: 'bottom', align: 'start', collisionPadding: 12 }"
                        >
                            <template #default>
                                <button
                                    type="button"
                                    draggable="true"
                                    class="border border-default bg-default p-1 transition hover:border-primary/60 hover:bg-primary/10 active:cursor-grabbing"
                                    :class="{ 'opacity-50': draggingActionId === action.id }"
                                    :aria-label="action.name"
                                    @mouseenter="scheduleActionPopover(action)"
                                    @mouseleave="hideActionPopover(action)"
                                    @pointerdown="pressAction(action)"
                                    @pointerup="releasePopover"
                                    @pointercancel="releasePopover"
                                    @dragstart="startActionDrag($event, action)"
                                    @dragend="endActionDrag"
                                >
                                    <img
                                        :src="action.iconPath"
                                        alt=""
                                        class="size-12 object-contain"
                                        loading="lazy"
                                    >
                                </button>
                            </template>

                            <template #content>
                                <div class="w-80 border border-default bg-neutral-950 text-neutral-100 shadow-xl">
                                    <div class="flex items-center justify-between gap-3 border-b border-default bg-muted px-2 py-1">
                                        <h4 class="truncate text-sm font-semibold text-highlighted">
                                            {{ actionDetail(action)?.name ?? action.name }}
                                        </h4>
                                        <span class="text-xs text-muted">
                                            x
                                        </span>
                                    </div>

                                    <div class="space-y-2 p-2">
                                        <div class="grid grid-cols-[2.75rem_minmax(0,1fr)_4.75rem] gap-2">
                                            <img
                                                :src="action.iconPath"
                                                alt=""
                                                class="size-10 border border-default bg-default object-contain"
                                                loading="lazy"
                                            >
                                            <div class="min-w-0">
                                                <p class="text-xs font-semibold text-toned">
                                                    {{ actionCategory(action, actionDetail(action)) }}
                                                </p>
                                                <p class="mt-1 truncate text-[11px] text-muted">
                                                    {{ actionJobLevel(action, actionDetail(action)) }}
                                                </p>
                                            </div>
                                            <div class="text-right text-[10px] leading-4 text-muted">
                                                <p v-if="rangeLabel(actionDetail(action))">
                                                    {{ t('calculator.rotation_editor.range') }} {{ rangeLabel(actionDetail(action)) }}
                                                </p>
                                                <p v-if="radiusLabel(actionDetail(action))">
                                                    {{ t('calculator.rotation_editor.radius') }} {{ radiusLabel(actionDetail(action)) }}
                                                </p>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-2 gap-3 border-y border-default py-1.5">
                                            <div>
                                                <p class="text-right text-[10px] uppercase text-muted">
                                                    {{ t('calculator.rotation_editor.cast') }}
                                                </p>
                                                <p class="border-t border-primary/40 pt-0.5 text-right text-sm font-semibold text-highlighted">
                                                    {{ castTime(action, actionDetail(action)) }}
                                                </p>
                                            </div>
                                            <div>
                                                <p class="text-right text-[10px] uppercase text-muted">
                                                    {{ t('calculator.rotation_editor.recast') }}
                                                </p>
                                                <p class="border-t border-primary/40 pt-0.5 text-right text-sm font-semibold text-highlighted">
                                                    {{ recastTime(action, actionDetail(action)) }}
                                                </p>
                                            </div>
                                        </div>

                                        <div class="flex justify-end border border-default bg-muted px-2 py-0.5 text-[10px] font-semibold text-muted">
                                            {{ t('calculator.rotation_editor.stats') }}
                                        </div>

                                        <div
                                            v-if="isActionDetailLoading(action)"
                                            class="py-4 text-center text-xs text-muted"
                                        >
                                            {{ t('calculator.rotation_editor.action_loading') }}
                                        </div>
                                        <div
                                            v-else
                                            class="space-y-2 text-xs leading-5 text-toned"
                                        >
                                            <p class="whitespace-pre-line">
                                                {{ actionDescription(actionDetail(action)) }}
                                            </p>
                                            <div
                                                v-if="costLines(actionDetail(action)).length"
                                                class="space-y-0.5 border-t border-default pt-1 text-[11px] text-muted"
                                            >
                                                <p
                                                    v-for="cost in costLines(actionDetail(action))"
                                                    :key="cost"
                                                >
                                                    {{ cost }}
                                                </p>
                                            </div>
                                            <p
                                                v-if="affinityLabel(actionDetail(action))"
                                                class="border-t border-default pt-1 text-[11px] text-muted"
                                            >
                                                {{ affinityLabel(actionDetail(action)) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </UPopover>
                    </div>
                </section>

                <section class="border border-default bg-muted">
                    <div class="flex items-center justify-between gap-3 border-b border-default px-2.5 py-1.5">
                        <h3 class="text-xs font-semibold uppercase text-highlighted">
                            {{ t('calculator.rotation_editor.traits', { job: activeJob.abbreviation }) }}
                        </h3>
                        <UBadge
                            :label="t('calculator.rotation_editor.enabled_count', { enabled: enabledTraitCount, total: activeJob.traits.length })"
                            color="neutral"
                            variant="subtle"
                            size="xs"
                        />
                    </div>

                    <div class="flex max-h-28 flex-wrap gap-2 overflow-y-auto p-2">
                        <UPopover
                            v-for="trait in activeJob.traits"
                            :key="trait.id"
                            mode="hover"
                            :open="openPopoverKey === traitPopoverKey(trait)"
                            :open-delay="500"
                            :close-delay="80"
                            :content="{ side: 'bottom', align: 'start', collisionPadding: 12 }"
                        >
                            <template #default>
                                <div
                                    class="border p-1 transition"
                                    :class="isTraitEnabled(trait)
                                        ? 'border-primary/50 bg-primary/10 text-highlighted'
                                        : 'border-default bg-default text-muted opacity-35 grayscale'"
                                    :aria-label="trait.name"
                                    :title="traitTitle(trait)"
                                    @mouseenter="scheduleTraitPopover(trait)"
                                    @mouseleave="hideTraitPopover(trait)"
                                    @pointerdown="pressTrait(trait)"
                                    @pointerup="releasePopover"
                                    @pointercancel="releasePopover"
                                >
                                    <img
                                        :src="trait.iconPath"
                                        alt=""
                                        class="size-9 object-contain"
                                        loading="lazy"
                                    >
                                </div>
                            </template>

                            <template #content>
                                <div class="w-80 border border-default bg-neutral-950 text-neutral-100 shadow-xl">
                                    <div class="flex items-center justify-between gap-3 border-b border-default bg-muted px-2 py-1">
                                        <h4 class="truncate text-sm font-semibold text-highlighted">
                                            {{ traitDetail(trait)?.name ?? trait.name }}
                                        </h4>
                                        <span class="text-xs text-muted">
                                            x
                                        </span>
                                    </div>

                                    <div class="space-y-2 p-2">
                                        <div class="grid grid-cols-[2.75rem_minmax(0,1fr)_4.75rem] gap-2">
                                            <img
                                                :src="trait.iconPath"
                                                alt=""
                                                class="size-10 border border-default bg-default object-contain"
                                                loading="lazy"
                                            >
                                            <div class="min-w-0">
                                                <p class="text-xs font-semibold text-toned">
                                                    {{ t('calculator.rotation_editor.trait') }}
                                                </p>
                                                <p class="mt-1 truncate text-[11px] text-muted">
                                                    {{ traitJobLevel(trait, traitDetail(trait)) }}
                                                </p>
                                            </div>
                                            <div class="text-right text-[10px] leading-4 text-muted">
                                                <p>
                                                    {{ traitDetail(trait)?.role ?? activeJob.role }}
                                                </p>
                                            </div>
                                        </div>

                                        <div class="flex justify-end border border-default bg-muted px-2 py-0.5 text-[10px] font-semibold text-muted">
                                            {{ t('calculator.rotation_editor.stats') }}
                                        </div>

                                        <div
                                            v-if="isTraitDetailLoading(trait)"
                                            class="py-4 text-center text-xs text-muted"
                                        >
                                            {{ t('calculator.rotation_editor.trait_loading') }}
                                        </div>
                                        <div
                                            v-else
                                            class="space-y-2 text-xs leading-5 text-toned"
                                        >
                                            <p class="whitespace-pre-line">
                                                {{ traitDescription(traitDetail(trait)) }}
                                            </p>
                                            <p
                                                v-if="affinityLabel(traitDetail(trait))"
                                                class="border-t border-default pt-1 text-[11px] text-muted"
                                            >
                                                {{ affinityLabel(traitDetail(trait)) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </UPopover>
                    </div>
                </section>
            </template>

            <div class="h-px bg-default" />

            <section class="flex min-h-0 flex-1 flex-col border border-default bg-muted">
                <div class="border-b border-default px-2.5 py-1.5">
                    <h3 class="text-xs font-semibold uppercase text-highlighted">
                        {{ t('calculator.rotation_editor.timeline') }}
                    </h3>
                </div>

                <UContextMenu :items="timelineContextMenuItems">
                    <div
                        class="flex-1 bg-default/40 p-3"
                        @contextmenu.self="setTimelineContextTarget('postpull', postpullTimelineEntries.length)"
                        @dragover.self.prevent="setTimelineDropPreview('postpull', postpullTimelineEntries.length)"
                        @dragleave.self="clearTimelineDropPreview"
                        @drop.prevent="dropAction"
                    >
                        <div
                            class="flex flex-wrap content-start items-start gap-y-3"
                            @contextmenu.self="setTimelineContextTarget('postpull', postpullTimelineEntries.length)"
                            @dragover.self.prevent="setTimelineDropPreview('postpull', postpullTimelineEntries.length)"
                            @dragleave.self="clearTimelineDropPreview"
                        >
                        <div
                            class="group relative h-32 w-1.5 shrink-0"
                            @contextmenu="setTimelineContextTarget('prepull', 0)"
                            @dragover.stop.prevent="setTimelineDropPreview('prepull', 0)"
                            @drop.stop.prevent="dropTimelineEntry($event, 'prepull', 0)"
                        >
                            <span class="pointer-events-none absolute inset-x-0 top-[4.9rem] h-px bg-default" />
                            <span
                                class="absolute inset-y-3 left-1/2 w-0.5 -translate-x-1/2 transition"
                                :class="isTimelineDropPreview('prepull', 0)
                                    ? 'bg-primary opacity-100 shadow-[0_0_12px_rgba(168,85,247,0.75)]'
                                    : 'bg-transparent opacity-80 group-hover:bg-primary/70'"
                            />
                        </div>

                        <template
                            v-for="(entry, index) in prepullTimelineEntries"
                            :key="entry.key"
                        >
                            <div
                                class="group relative h-32 shrink-0"
                                :class="[entry.type === 'utility' ? 'w-[4.5rem]' : entry.isGcd ? 'w-16' : 'w-12', { 'opacity-40': draggingTimelineEntryKey === entry.key }]"
                                draggable="true"
                                @contextmenu="setTimelineContextTarget('prepull', index + 1)"
                                @dragover.stop.prevent="setTimelineDropPreview('prepull', index + 1)"
                                @drop.stop.prevent="dropTimelineEntry($event, 'prepull', index + 1)"
                                @dragstart="startTimelineEntryDrag($event, entry)"
                                @dragend="endActionDrag"
                            >
                                <span class="pointer-events-none absolute inset-x-0 top-[4.9rem] h-px bg-default" />
                                <button
                                    v-if="entry.type === 'action'"
                                    type="button"
                                    class="absolute left-1/2 -translate-x-1/2 border border-default bg-default p-1 shadow-lg transition hover:border-primary/60 hover:bg-primary/10"
                                    :class="entry.isGcd ? 'top-[3.15rem] size-14' : 'top-7 size-12'"
                                    :aria-label="t('calculator.rotation_editor.remove_action')"
                                    :title="entry.action.name"
                                    @click="removeTimelineEntry(entry.key)"
                                >
                                    <img
                                        :src="entry.action.iconPath"
                                        alt=""
                                        class="size-full object-contain"
                                        loading="lazy"
                                    >
                                    <span class="pointer-events-none absolute -right-1 -top-1 hidden size-4 items-center justify-center border border-default bg-muted text-muted group-hover:flex">
                                        <UIcon
                                            name="i-lucide-x"
                                            class="size-2.5"
                                        />
                                    </span>
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    class="absolute left-1/2 top-3 flex -translate-x-1/2 flex-col items-center gap-1 text-center"
                                    :aria-label="t('calculator.rotation_editor.remove_utility_block')"
                                    :title="utilityBlockLabel(entry.kind)"
                                    @click="removeTimelineEntry(entry.key)"
                                >
                                    <span
                                        class="pointer-events-none absolute left-1/2 top-5 h-[5.25rem] w-px -translate-x-1/2"
                                        :class="utilityBlockLineClass(entry.kind)"
                                    />
                                    <span
                                        class="relative z-10 flex size-8 items-center justify-center border"
                                        :class="utilityBlockMarkerClass(entry.kind)"
                                    >
                                        <UIcon
                                            :name="utilityBlockIcon(entry.kind)"
                                            class="size-4"
                                        />
                                    </span>
                                    <span
                                        class="relative z-10 max-w-16 truncate bg-default px-1 py-0.5 text-[9px] font-semibold uppercase leading-none"
                                        :class="utilityBlockMarkerClass(entry.kind)"
                                    >
                                        {{ utilityBlockLabel(entry.kind) }}
                                    </span>
                                    <span class="pointer-events-none absolute -right-1 top-1 hidden size-4 items-center justify-center border border-default bg-muted text-muted group-hover:flex">
                                        <UIcon
                                            name="i-lucide-x"
                                            class="size-2.5"
                                        />
                                    </span>
                                </button>
                                <span
                                    v-if="entry.type === 'action' && entry.isGcd"
                                    class="absolute left-1/2 top-[6.9rem] -translate-x-1/2 font-mono text-[10px] text-muted"
                                >
                                    {{ formatTimelineTime(entry.timeSeconds) }}
                                </span>
                            </div>

                            <div
                                class="group relative h-32 w-1.5 shrink-0"
                                @contextmenu="setTimelineContextTarget('prepull', index + 1)"
                                @dragover.stop.prevent="setTimelineDropPreview('prepull', index + 1)"
                                @drop.stop.prevent="dropTimelineEntry($event, 'prepull', index + 1)"
                            >
                                <span class="pointer-events-none absolute inset-x-0 top-[4.9rem] h-px bg-default" />
                                <span
                                    class="absolute inset-y-3 left-1/2 w-0.5 -translate-x-1/2 transition"
                                    :class="isTimelineDropPreview('prepull', index + 1)
                                        ? 'bg-primary opacity-100 shadow-[0_0_12px_rgba(168,85,247,0.75)]'
                                        : 'bg-transparent opacity-80 group-hover:bg-primary/70'"
                                />
                            </div>
                        </template>

                        <div
                            class="relative z-20 h-32 w-8 shrink-0"
                            @contextmenu="setTimelineContextTarget('postpull', 0)"
                            @dragover.stop.prevent="setTimelineDropPreview('postpull', 0)"
                            @drop.stop.prevent="dropTimelineEntry($event, 'postpull', 0)"
                        >
                            <span class="pointer-events-none absolute inset-x-0 top-[4.9rem] h-px bg-default" />
                            <span class="absolute inset-y-3 left-1/2 w-px -translate-x-1/2 bg-primary shadow-[0_0_12px_rgba(168,85,247,0.35)]" />
                            <span class="absolute top-1 left-1/2 -translate-x-1/2 font-mono text-[10px] font-semibold uppercase text-primary">
                                {{ t('calculator.rotation_editor.pull') }}
                            </span>
                        </div>

                        <div
                            class="group relative h-32 w-1.5 shrink-0"
                            @contextmenu="setTimelineContextTarget('postpull', 0)"
                            @dragover.stop.prevent="setTimelineDropPreview('postpull', 0)"
                            @drop.stop.prevent="dropTimelineEntry($event, 'postpull', 0)"
                        >
                            <span class="pointer-events-none absolute inset-x-0 top-[4.9rem] h-px bg-default" />
                            <span
                                class="absolute inset-y-3 left-1/2 w-0.5 -translate-x-1/2 transition"
                                :class="isTimelineDropPreview('postpull', 0)
                                    ? 'bg-primary opacity-100 shadow-[0_0_12px_rgba(168,85,247,0.75)]'
                                    : 'bg-transparent opacity-80 group-hover:bg-primary/70'"
                            />
                        </div>

                        <template
                            v-for="(entry, index) in postpullTimelineEntries"
                            :key="entry.key"
                        >
                            <div
                                class="group relative h-32 shrink-0"
                                :class="[entry.type === 'utility' ? 'w-[4.5rem]' : entry.isGcd ? 'w-16' : 'w-12', { 'opacity-40': draggingTimelineEntryKey === entry.key }]"
                                draggable="true"
                                @contextmenu="setTimelineContextTarget('postpull', index + 1)"
                                @dragover.stop.prevent="setTimelineDropPreview('postpull', index + 1)"
                                @drop.stop.prevent="dropTimelineEntry($event, 'postpull', index + 1)"
                                @dragstart="startTimelineEntryDrag($event, entry)"
                                @dragend="endActionDrag"
                            >
                                <span class="pointer-events-none absolute inset-x-0 top-[4.9rem] h-px bg-default" />
                                <button
                                    v-if="entry.type === 'action'"
                                    type="button"
                                    class="absolute left-1/2 -translate-x-1/2 border border-default bg-default p-1 shadow-lg transition hover:border-primary/60 hover:bg-primary/10"
                                    :class="entry.isGcd ? 'top-[3.15rem] size-14' : 'top-7 size-12'"
                                    :aria-label="t('calculator.rotation_editor.remove_action')"
                                    :title="entry.action.name"
                                    @click="removeTimelineEntry(entry.key)"
                                >
                                    <img
                                        :src="entry.action.iconPath"
                                        alt=""
                                        class="size-full object-contain"
                                        loading="lazy"
                                    >
                                    <span class="pointer-events-none absolute -right-1 -top-1 hidden size-4 items-center justify-center border border-default bg-muted text-muted group-hover:flex">
                                        <UIcon
                                            name="i-lucide-x"
                                            class="size-2.5"
                                        />
                                    </span>
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    class="absolute left-1/2 top-3 flex -translate-x-1/2 flex-col items-center gap-1 text-center"
                                    :aria-label="t('calculator.rotation_editor.remove_utility_block')"
                                    :title="utilityBlockLabel(entry.kind)"
                                    @click="removeTimelineEntry(entry.key)"
                                >
                                    <span
                                        class="pointer-events-none absolute left-1/2 top-5 h-[5.25rem] w-px -translate-x-1/2"
                                        :class="utilityBlockLineClass(entry.kind)"
                                    />
                                    <span
                                        class="relative z-10 flex size-8 items-center justify-center border"
                                        :class="utilityBlockMarkerClass(entry.kind)"
                                    >
                                        <UIcon
                                            :name="utilityBlockIcon(entry.kind)"
                                            class="size-4"
                                        />
                                    </span>
                                    <span
                                        class="relative z-10 max-w-16 truncate bg-default px-1 py-0.5 text-[9px] font-semibold uppercase leading-none"
                                        :class="utilityBlockMarkerClass(entry.kind)"
                                    >
                                        {{ utilityBlockLabel(entry.kind) }}
                                    </span>
                                    <span class="pointer-events-none absolute -right-1 top-1 hidden size-4 items-center justify-center border border-default bg-muted text-muted group-hover:flex">
                                        <UIcon
                                            name="i-lucide-x"
                                            class="size-2.5"
                                        />
                                    </span>
                                </button>
                                <span
                                    v-if="entry.type === 'action' && entry.isGcd"
                                    class="absolute left-1/2 top-[6.9rem] -translate-x-1/2 font-mono text-[10px] text-muted"
                                >
                                    {{ formatTimelineTime(entry.timeSeconds) }}
                                </span>
                            </div>

                            <div
                                class="group relative h-32 w-1.5 shrink-0"
                                @contextmenu="setTimelineContextTarget('postpull', index + 1)"
                                @dragover.stop.prevent="setTimelineDropPreview('postpull', index + 1)"
                                @drop.stop.prevent="dropTimelineEntry($event, 'postpull', index + 1)"
                            >
                                <span class="pointer-events-none absolute inset-x-0 top-[4.9rem] h-px bg-default" />
                                <span
                                    class="absolute inset-y-3 left-1/2 w-0.5 -translate-x-1/2 transition"
                                    :class="isTimelineDropPreview('postpull', index + 1)
                                        ? 'bg-primary opacity-100 shadow-[0_0_12px_rgba(168,85,247,0.75)]'
                                        : 'bg-transparent opacity-80 group-hover:bg-primary/70'"
                                />
                            </div>
                        </template>
                        </div>

                        <p
                            v-if="!hasTimelineEntries"
                            class="mt-2 text-center text-xs text-muted"
                        >
                            {{ t('calculator.rotation_editor.empty_timeline') }}
                        </p>
                    </div>
                </UContextMenu>
            </section>

            <div class="flex justify-end">
                <UButton
                    :label="t('calculator.rotation_editor.simulate')"
                    icon="i-lucide-play"
                    color="primary"
                    disabled
                />
            </div>
        </div>
    </section>
</template>
