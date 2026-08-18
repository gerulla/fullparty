<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { STAT_KEYS } from '../math/combatMath.js'
import {
    enrichXivGearEquipment,
    extractXivGearEquipment,
    fetchXivGearPayload,
    fetchXivGearReferenceData,
} from '../importers/xivGearImporter.js'

type ImportedSheet = {
    sheet: {
        name: string | null
        job: string | null
        level: number | null
    }
    sets: ImportedSet[]
}

type ImportedSet = {
    index: number
    sourceIndex: number
    name: string
    description: string | null
    job: string
    level: number
    partyBonus: number | null
    race: string | null
    foodId: number | null
    ilvlSync: number | null
    stats: Record<string, number>
}

const emit = defineEmits<{
    'selected-set-change': [set: ImportedSet | null]
}>()

type EquipmentItem = {
    slot: string
    label: string
    id: number | null
    name: string | null
    iconUrl: string | null
    forceNq: boolean
    materia: {
        index: number
        id: number
        name: string | null
        iconUrl: string | null
        locked: boolean
    }[]
    relicStats: Record<string, number> | null
}

type XivGearReferenceEntry = {
    id: number | null
    name: string | null
    nameTranslations: Record<string, string> | null
    iconUrl: string | null
}

type XivGearReferenceData = {
    itemsById: Map<number, XivGearReferenceEntry>
    materiaById: Map<number, XivGearReferenceEntry>
    foodById: Map<number, XivGearReferenceEntry>
}

const CALCULATOR_CLASS_ICON_PATHS: Readonly<Record<string, string>> = Object.freeze({
    PLD: '/CalculatorData/Tank/Paladin/class-icon.png',
    WAR: '/CalculatorData/Tank/Warrior/class-icon.png',
    DRK: '/CalculatorData/Tank/Dark%20Knight/class-icon.png',
    GNB: '/CalculatorData/Tank/Gunbreaker/class-icon.png',
    WHM: '/CalculatorData/Healer/White%20Mage/class-icon.png',
    SCH: '/CalculatorData/Healer/Scholar/class-icon.png',
    AST: '/CalculatorData/Healer/Astrologian/class-icon.png',
    SGE: '/CalculatorData/Healer/Sage/class-icon.png',
    MNK: '/CalculatorData/Melee%20DPS/Monk/class-icon.png',
    DRG: '/CalculatorData/Melee%20DPS/Dragoon/class-icon.png',
    NIN: '/CalculatorData/Melee%20DPS/Ninja/class-icon.png',
    SAM: '/CalculatorData/Melee%20DPS/Samurai/class-icon.png',
    RPR: '/CalculatorData/Melee%20DPS/Reaper/class-icon.png',
    VPR: '/CalculatorData/Melee%20DPS/Viper/class-icon.png',
    BRD: '/CalculatorData/Physical%20Ranged%20DPS/Bard/class-icon.png',
    MCH: '/CalculatorData/Physical%20Ranged%20DPS/Machinist/class-icon.png',
    DNC: '/CalculatorData/Physical%20Ranged%20DPS/Dancer/class-icon.png',
    BLM: '/CalculatorData/Magical%20Ranged%20DPS/Black%20Mage/class-icon.png',
    SMN: '/CalculatorData/Magical%20Ranged%20DPS/Summoner/class-icon.png',
    RDM: '/CalculatorData/Magical%20Ranged%20DPS/Red%20Mage/class-icon.png',
    PCT: '/CalculatorData/Magical%20Ranged%20DPS/Pictomancer/class-icon.png',
    BLU: '/CalculatorData/Magical%20Ranged%20DPS/Blue%20Mage/class-icon.png',
})

const { locale, t } = useI18n()

const gearSetUrl = ref('https://xivgear.app/?page=sl|f977e1c0-aa15-40f9-804e-b3721e8762ba')
const importedSheet = ref<ImportedSheet | null>(null)
const selectedSetIndex = ref(0)
const isImporting = ref(false)
const importError = ref<string | null>(null)
const referenceData = ref<XivGearReferenceData | null>(null)
const referenceJob = ref<string | null>(null)
const isResolvingNames = ref(false)
const referenceError = ref<string | null>(null)
let referenceRequestId = 0

const selectedSet = computed(() => importedSheet.value?.sets[selectedSetIndex.value] ?? null)
const selectedSetClassIcon = computed(() => selectedSet.value ? CALCULATOR_CLASS_ICON_PATHS[selectedSet.value.job] ?? null : null)
const hasImportedSets = computed(() => Boolean(importedSheet.value?.sets.length))
const statRows = computed(() => {
    const set = selectedSet.value

    if (!set) {
        return []
    }

    return STAT_KEYS.map(key => ({
        key,
        label: t(`calculator.gear_import.stats.${key}`),
        value: formatStatValue(set.stats[key]),
    }))
})
const equipmentItems = computed<EquipmentItem[]>(() => {
    const set = selectedSet.value

    if (!set) {
        return []
    }

    const equipment = extractXivGearEquipment(set) as EquipmentItem[]

    if (!referenceData.value || referenceJob.value !== set.job) {
        return equipment
    }

    return enrichXivGearEquipment(equipment, referenceData.value, { locale: locale.value }) as EquipmentItem[]
})
const setOptions = computed(() => importedSheet.value?.sets ?? [])

async function importGearSet() {
    if (!gearSetUrl.value.trim() || isImporting.value) {
        return
    }

    isImporting.value = true
    importError.value = null

    try {
        importedSheet.value = await fetchXivGearPayload(gearSetUrl.value.trim()) as ImportedSheet
        selectedSetIndex.value = 0
        emitSelectedSet()
        void loadReferenceDataForSelectedSet()
    } catch (error) {
        importedSheet.value = null
        selectedSetIndex.value = 0
        importError.value = error instanceof Error ? error.message : t('calculator.gear_import.errors.generic')
        emitSelectedSet()
        resetReferenceLookup()
    } finally {
        isImporting.value = false
    }
}

function selectSet(index: number) {
    selectedSetIndex.value = index
    emitSelectedSet()
    void loadReferenceDataForSelectedSet()
}

function emitSelectedSet() {
    emit('selected-set-change', selectedSet.value)
}

async function loadReferenceDataForSelectedSet() {
    const set = selectedSet.value
    const requestId = ++referenceRequestId

    referenceError.value = null

    if (!set) {
        resetReferenceLookup()
        return
    }

    if (referenceData.value && referenceJob.value === set.job) {
        return
    }

    referenceData.value = null
    referenceJob.value = set.job
    isResolvingNames.value = true

    try {
        const data = await fetchXivGearReferenceData(set.job) as XivGearReferenceData

        if (requestId !== referenceRequestId) {
            return
        }

        referenceData.value = data
        referenceJob.value = set.job
    } catch (error) {
        if (requestId !== referenceRequestId) {
            return
        }

        referenceData.value = null
        referenceError.value = t('calculator.gear_import.errors.name_lookup_failed', {
            error: error instanceof Error ? error.message : t('calculator.gear_import.errors.generic'),
        })
    } finally {
        if (requestId === referenceRequestId) {
            isResolvingNames.value = false
        }
    }
}

function resetReferenceLookup() {
    referenceRequestId += 1
    referenceData.value = null
    referenceJob.value = null
    isResolvingNames.value = false
    referenceError.value = null
}

function displayItemName(item: EquipmentItem) {
    if (item.name) {
        return item.name
    }

    if (item.id) {
        return `#${item.id}`
    }

    return t('calculator.gear_import.empty_value')
}

function displayMateriaName(materia: EquipmentItem['materia'][number]) {
    return materia.name ?? `#${materia.id}`
}

function formatStatValue(value: number | undefined) {
    return Number.isFinite(value) ? new Intl.NumberFormat().format(value) : t('calculator.gear_import.empty_value')
}

function formatRelicStats(relicStats: Record<string, number> | null) {
    if (!relicStats) {
        return ''
    }

    return Object.entries(relicStats)
        .map(([stat, value]) => `${stat}: ${value}`)
        .join(', ')
}
</script>

<template>
    <section class="flex min-h-0 flex-col overflow-hidden border border-default bg-muted xl:max-h-[calc(100vh-7.5rem)]">
        <div class="border-b border-default px-3 py-2">
            <h2 class="text-sm font-semibold text-highlighted">
                {{ t('calculator.gear_import.title') }}
            </h2>
            <p class="mt-0.5 text-xs leading-5 text-muted">
                {{ t('calculator.gear_import.description') }}
            </p>
        </div>

        <div class="min-h-0 flex-1 space-y-3 overflow-y-auto p-3">
            <form
                class="flex flex-col gap-2"
                @submit.prevent="importGearSet"
            >
                <UFormField
                    :label="t('calculator.gear_import.url_label')"
                    class="flex-1"
                >
                    <UInput
                        v-model="gearSetUrl"
                        :placeholder="t('calculator.gear_import.url_placeholder')"
                        icon="i-lucide-link"
                        size="sm"
                        class="w-full"
                        :disabled="isImporting"
                    />
                </UFormField>
                <UButton
                    type="submit"
                    :label="t('calculator.gear_import.import_button')"
                    icon="i-lucide-download"
                    color="primary"
                    size="sm"
                    :loading="isImporting"
                    :disabled="!gearSetUrl.trim()"
                    class="justify-center"
                />
            </form>

            <UAlert
                v-if="importError"
                color="error"
                variant="subtle"
                icon="i-lucide-circle-alert"
                :title="t('calculator.gear_import.errors.title')"
                :description="importError"
            />

            <div
                v-if="hasImportedSets"
                class="space-y-3"
            >
                <div class="space-y-2 border border-default bg-default p-2.5">
                    <div class="flex min-w-0 items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-highlighted">
                                {{ selectedSet?.name }}
                            </p>
                            <p class="mt-1 text-xs text-muted">
                                {{ selectedSet?.job }} - {{ t('calculator.gear_import.level_short', { level: selectedSet?.level }) }}
                                <template v-if="selectedSet?.partyBonus != null">
                                    - {{ t('calculator.gear_import.party_bonus', { bonus: selectedSet?.partyBonus }) }}
                                </template>
                                <template v-if="selectedSet?.ilvlSync">
                                    - {{ t('calculator.gear_import.ilvl_sync', { ilvl: selectedSet.ilvlSync }) }}
                                </template>
                            </p>
                        </div>

                        <img
                            v-if="selectedSetClassIcon"
                            :src="selectedSetClassIcon"
                            alt=""
                            class="size-11 shrink-0 object-contain"
                            loading="lazy"
                        >
                    </div>

                    <div
                            v-if="(importedSheet?.sets.length ?? 0) > 1"
                            class="flex max-h-24 flex-wrap gap-1.5 overflow-y-auto"
                    >
                        <UButton
                            v-for="set in setOptions"
                            :key="`${set.sourceIndex}-${set.name}`"
                            :label="set.name"
                            size="xs"
                            :variant="selectedSetIndex === set.index ? 'solid' : 'outline'"
                            color="neutral"
                            @click="selectSet(set.index)"
                        />
                    </div>
                </div>

                <div class="space-y-3">
                    <section class="border border-default bg-default">
                        <div class="border-b border-default px-2.5 py-1.5">
                            <h3 class="text-xs font-semibold uppercase text-highlighted">
                                {{ t('calculator.gear_import.stats_title') }}
                            </h3>
                        </div>
                        <dl class="grid grid-cols-3 gap-px bg-default">
                            <div
                                v-for="stat in statRows"
                                :key="stat.key"
                                class="bg-muted px-2 py-1.5"
                            >
                                <dt class="text-[10px] font-medium uppercase text-muted">
                                    {{ stat.label }}
                                </dt>
                                <dd class="mt-0.5 font-mono text-xs text-highlighted">
                                    {{ stat.value }}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section class="border border-default bg-default">
                        <div class="flex items-center justify-between gap-2 border-b border-default px-2.5 py-1.5">
                            <h3 class="text-xs font-semibold uppercase text-highlighted">
                                {{ t('calculator.gear_import.equipment_title') }}
                            </h3>
                            <span
                                v-if="isResolvingNames"
                                class="inline-flex items-center gap-1 text-[11px] text-muted"
                            >
                                <UIcon
                                    name="i-lucide-loader-circle"
                                    class="size-3 animate-spin"
                                />
                                {{ t('calculator.gear_import.resolving_names') }}
                            </span>
                        </div>

                        <UAlert
                            v-if="referenceError"
                            color="warning"
                            variant="subtle"
                            icon="i-lucide-triangle-alert"
                            :description="referenceError"
                            class="m-2.5"
                        />

                        <div
                            v-if="equipmentItems.length"
                            class="divide-y divide-default"
                        >
                            <div
                                v-for="item in equipmentItems"
                                :key="item.slot"
                                class="grid gap-2 px-2.5 py-2 sm:grid-cols-[4.75rem_minmax(0,1fr)]"
                            >
                                <div class="text-[10px] font-semibold uppercase text-muted">
                                    {{ item.label }}
                                </div>
                                <div class="min-w-0 space-y-2">
                                    <div class="flex min-w-0 items-start gap-2">
                                        <img
                                            v-if="item.iconUrl"
                                            :src="item.iconUrl"
                                            alt=""
                                            class="size-7 shrink-0 border border-default bg-muted object-contain"
                                            loading="lazy"
                                        >
                                        <div class="min-w-0 space-y-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-medium text-highlighted">
                                                    {{ displayItemName(item) }}
                                                </span>
                                                <span
                                                    v-if="item.name && item.id"
                                                    class="font-mono text-[11px] text-muted"
                                                >
                                                    #{{ item.id }}
                                                </span>
                                                <UBadge
                                                    v-if="item.forceNq"
                                                    :label="t('calculator.gear_import.nq')"
                                                    color="neutral"
                                                    variant="subtle"
                                                    size="xs"
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    <div
                                        v-if="item.materia.length"
                                        class="flex flex-wrap gap-1.5"
                                    >
                                        <span
                                            v-for="materia in item.materia"
                                            :key="`${item.slot}-${materia.index}-${materia.id}`"
                                            class="inline-flex max-w-full items-center gap-1.5 border border-default bg-muted px-1.5 py-0.5 text-[11px] text-toned"
                                        >
                                            <img
                                                v-if="materia.iconUrl"
                                                :src="materia.iconUrl"
                                                alt=""
                                                class="size-3.5 shrink-0 object-contain"
                                                loading="lazy"
                                            >
                                            <UIcon
                                                v-if="materia.locked"
                                                name="i-lucide-lock"
                                                class="size-3 shrink-0 text-muted"
                                            />
                                            <span class="min-w-0 truncate">
                                                {{ displayMateriaName(materia) }}
                                            </span>
                                        </span>
                                    </div>
                                    <p
                                        v-else-if="item.slot !== 'Food'"
                                        class="text-xs text-muted"
                                    >
                                        {{ t('calculator.gear_import.no_melds') }}
                                    </p>

                                    <p
                                        v-if="item.relicStats"
                                        class="font-mono text-xs text-muted"
                                    >
                                        {{ formatRelicStats(item.relicStats) }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div
                            v-else
                            class="px-3 py-6 text-sm text-muted"
                        >
                            {{ t('calculator.gear_import.no_equipment') }}
                        </div>
                    </section>
                </div>
            </div>

            <div
                v-else-if="!importError"
                class="border border-dashed border-default bg-default px-3 py-6 text-sm text-muted"
            >
                {{ t('calculator.gear_import.empty_state') }}
            </div>
        </div>
    </section>
</template>
