<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { Head, usePage, useRemember } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import V2Layout from '@/Layouts/V2Layout.vue'
import GroupCreatePreviewControls from '@/components/Groups/GroupCreatePreviewControls.vue'
import GroupCreateTypePicker from '@/components/Groups/GroupCreateTypePicker.vue'
import GroupCreateDatacenterPicker from '@/components/Groups/GroupCreateDatacenterPicker.vue'
import GroupCreateIdentityForm from '@/components/Groups/GroupCreateIdentityForm.vue'
import GroupCreateArtworkStep from '@/components/Groups/GroupCreateArtworkStep.vue'
import GroupCreateFitStep from '@/components/Groups/GroupCreateFitStep.vue'
import GroupCreateFocusStep from '@/components/Groups/GroupCreateFocusStep.vue'
import GroupCreateActivityWindowStep from '@/components/Groups/GroupCreateActivityWindowStep.vue'
import GroupCreateJoiningStep from '@/components/Groups/GroupCreateJoiningStep.vue'
import GroupCreateLoadingScreen from '@/components/Groups/GroupCreateLoadingScreen.vue'
import { createGroupActivityWindow, groupActivityTimePreset } from '@/utils/groupActivityWindow'
import { useGroupCreateArtwork } from '@/composables/useGroupCreateArtwork'
import { useGroupCreateSubmission } from '@/composables/useGroupCreateSubmission'
import { groupCreateSteps } from '@/utils/groupCreateSubmission'
import { datacenterRegionForTimezone } from '@/utils/datacenterRegion'
import type { DatacenterLookup, DatacenterRegion, GroupCreateDraft, GroupCreateIdentity, GroupCreateStep, GroupDiscoveryLookups, GroupType } from '@/Types/Groups'

defineOptions({ layout: V2Layout })

const { t } = useI18n()
const props = withDefaults(defineProps<{
    datacenterWorlds: Record<string, string[]>
    popularTags?: string[]
    reservedTagKeys?: string[]
}>(), { popularTags: () => [], reservedTagKeys: () => [] })
const page = usePage<{ lookups: { datacenters: DatacenterLookup[]; group_discovery: GroupDiscoveryLookups } }>()
const discoveryLookups = computed(() => page.props.lookups.group_discovery)
const canPreview = computed(() => page.props.theme_preview_enabled === true)
const heading = ref<HTMLElement | null>(null)
const artwork = useGroupCreateArtwork()
const datacenters = computed(() => page.props.lookups.datacenters.map(dc => ({
    ...dc,
    worlds: props.datacenterWorlds[dc.value] ?? [],
})))
const draft = useRemember(reactive<GroupCreateDraft>({
    groupType: null,
    step: 'type',
    region: null,
    datacenter: null,
    identity: { name: '', slug: '', description: '' },
    fit: { experience_expectation: '', voice_expectation: '', preferred_languages: [] },
    focus: { primary_focuses: [], tags: [] },
    activity: createGroupActivityWindow(),
    joining: { join_mode: 'invite_only', is_visible: true },
}), 'groups.create')
const submission = useGroupCreateSubmission(draft, artwork, showStep)
const loadingPreview = ref(false)
const errorPreview = ref(false)
const isSubmitting = computed(() => submission.processing || loadingPreview.value)
const totalSteps = groupCreateSteps.length + 1
const progress = computed(() => isSubmitting.value ? totalSteps : groupCreateSteps.indexOf(draft.step as GroupCreateStep) + 1)
const failure = computed(() => errorPreview.value && draft.step === 'joining'
    ? 'groups.index.create_flow.submission.server_error' : submission.currentFailure)

// Keep remembered drafts from earlier versions of the flow usable.
draft.identity ??= { name: '', slug: '', description: '' }
draft.fit ??= { experience_expectation: '', voice_expectation: '', preferred_languages: [] }
draft.focus ??= { primary_focuses: [], tags: [] }
draft.activity ??= createGroupActivityWindow()
draft.activity.timePreset ??= groupActivityTimePreset(draft.activity.active_start_time, draft.activity.active_end_time)
draft.activity.customTime ??= draft.activity.timePreset === 'custom'
    ? { start: draft.activity.active_start_time, end: draft.activity.active_end_time }
    : { start: '11:00', end: '18:00' }
draft.joining ??= { join_mode: 'invite_only', is_visible: true }
if (draft.step === 'next' && !draft.fit.experience_expectation) draft.step = 'fit'
else if (draft.step === 'next' && !draft.activity.active_timezone) draft.step = 'activity'
else if (draft.step === 'next') draft.step = 'joining'

onMounted(() => {
    if (!draft.activity.active_timezone) {
        draft.activity.active_timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
    }
    if (!draft.region) {
        draft.region = datacenterRegionForTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone) ?? 'EU'
    }
})

async function chooseType(type: GroupType) {
    draft.groupType = type
    await submission.advance('datacenter')
}

async function showStep(step: GroupCreateStep) {
    draft.step = step
    await nextTick()
    window.scrollTo({ top: 0, behavior: 'instant' })
    heading.value?.focus({ preventScroll: true })
}

function chooseRegion(region: DatacenterRegion) {
    draft.region = region
    if (!datacenters.value.some(dc => dc.value === draft.datacenter && dc.region === region)) {
        draft.datacenter = null
    }
}

async function continueFromDatacenter() {
    if (!datacenters.value.some(dc => dc.value === draft.datacenter && dc.region === draft.region)) return
    await submission.advance('details')
}

async function changeType() {
    await showStep('type')
}

async function continueFromIdentity(identity: GroupCreateIdentity) {
    draft.identity = identity
    await submission.advance('artwork')
}

async function continueFromArtwork() {
    if (!artwork.canContinue) return
    await submission.advance('fit')
}

async function submitGroup() {
    if (loadingPreview.value || errorPreview.value) return
    submission.submit()
    await nextTick()
    window.scrollTo({ top: 0, behavior: 'instant' })
}

async function previewSubmission(state: 'loading' | 'error') {
    loadingPreview.value = state === 'loading'
    errorPreview.value = state === 'error'
    await showStep('joining')
}
</script>

<template>
    <div class="mx-auto w-full max-w-4xl space-y-6 py-6 sm:px-6 sm:py-10">
        <Head :title="t('groups.index.create_modal.title')" />
        <GroupCreatePreviewControls v-if="canPreview" :disabled="submission.processing" @preview-submission="previewSubmission" />
        <UProgress :model-value="progress" :max="totalSteps" color="brand" size="sm"
            :get-value-label="() => t('groups.index.create_flow.progress_label')"
            :get-value-text="value => t('groups.index.create_flow.progress_value', { percent: Math.round((value ?? 0) / totalSteps * 100) })"
            :ui="{ base: 'rounded-full bg-elevation-4 dark:bg-elevation-5', indicator: 'rounded-full bg-brand-600 transition-transform duration-200 motion-reduce:transition-none dark:bg-brand-400' }" />
        <UAlert v-if="!isSubmitting && (failure || Object.keys(submission.stepErrors).length)"
            color="error" variant="soft" icon="i-lucide-circle-alert" role="alert" class="rounded-lg"
            :close="errorPreview" @update:open="errorPreview = false"
            :ui="{ title: 'text-red-800 dark:text-red-300', description: 'text-red-800 dark:text-red-300', icon: 'text-red-700 dark:text-red-300' }"
            :title="t(`groups.index.create_flow.submission.${failure ? 'error_title' : 'validation_title'}`)">
            <template #description>
                <p v-if="failure">{{ t(failure) }}</p>
                <ul v-if="Object.keys(submission.stepErrors).length" class="list-inside list-disc space-y-1">
                    <li v-for="(message, field) in submission.stepErrors" :key="field">{{ message }}</li>
                </ul>
            </template>
        </UAlert>
        <template v-if="isSubmitting">
            <GroupCreateLoadingScreen :name="draft.identity.name" :upload-percent="loadingPreview ? null : submission.uploadPercent" />
            <div v-if="loadingPreview" class="flex justify-center">
                <UButton color="neutral" variant="ghost" class="rounded-lg" @click="loadingPreview = false">
                    {{ t('groups.index.create_flow.submission.close_preview') }}
                </UButton>
            </div>
        </template>
        <template v-else-if="draft.step === 'type'">
            <header class="text-center">
                <h1 ref="heading" tabindex="-1" class="text-2xl font-semibold text-highlighted outline-none">
                    {{ t('groups.index.create_modal.title') }}
                </h1>
                <p class="mt-2 text-sm text-muted">{{ t('groups.index.create_flow.subtitle') }}</p>
            </header>
            <GroupCreateTypePicker @choose="chooseType" />
        </template>
        <template v-else-if="draft.step === 'datacenter'">
            <header class="flex items-start justify-between gap-4 text-left">
                <div class="min-w-0">
                    <h1 ref="heading" tabindex="-1" class="text-2xl font-semibold text-highlighted outline-none">
                        {{ t(`groups.index.create_flow.datacenter.title.${draft.groupType ?? 'community'}`) }}
                    </h1>
                    <p class="mt-2 text-sm text-muted">{{ t('groups.index.create_flow.datacenter.subtitle') }}</p>
                </div>
                <UButton color="neutral" variant="link" icon="i-lucide-arrow-left"
                    class="shrink-0 text-muted max-sm:max-w-28 max-sm:whitespace-normal max-sm:text-xs" @click="changeType">
                    {{ t('groups.index.create_flow.change_type') }}
                </UButton>
            </header>
            <GroupCreateDatacenterPicker v-model="draft.datacenter" :region="draft.region ?? 'EU'"
                :datacenters="datacenters" @update:region="chooseRegion" @continue="continueFromDatacenter" />
        </template>
        <template v-else-if="draft.step === 'details'">
            <header class="flex items-start justify-between gap-4 text-left">
                <div class="min-w-0">
                    <h1 ref="heading" tabindex="-1" class="text-2xl font-semibold text-highlighted outline-none">
                        {{ t(`groups.index.create_flow.identity.title.${draft.groupType ?? 'community'}`) }}
                    </h1>
                    <p class="mt-2 text-sm text-muted">{{ t('groups.index.create_flow.identity.subtitle') }}</p>
                </div>
                <UButton color="neutral" variant="link" icon="i-lucide-arrow-left"
                    class="shrink-0 text-muted max-sm:max-w-28 max-sm:whitespace-normal max-sm:text-xs" @click="showStep('datacenter')">
                    {{ t('groups.index.create_flow.datacenter.change_datacenter') }}
                </UButton>
            </header>
            <GroupCreateIdentityForm v-model="draft.identity" :errors="submission.stepErrors" @continue="continueFromIdentity" />
        </template>
        <template v-else-if="draft.step === 'artwork'">
            <header class="flex items-start justify-between gap-4 text-left">
                <div class="min-w-0">
                    <h1 ref="heading" tabindex="-1" class="text-2xl font-semibold text-highlighted outline-none">
                        {{ t(`groups.index.create_flow.artwork.title.${draft.groupType ?? 'community'}`) }}
                    </h1>
                    <p class="mt-2 text-sm text-muted">{{ t('groups.index.create_flow.artwork.subtitle') }}</p>
                </div>
                <UButton color="neutral" variant="link" icon="i-lucide-arrow-left"
                    class="shrink-0 text-muted max-sm:max-w-28 max-sm:whitespace-normal max-sm:text-xs" @click="showStep('details')">
                    {{ t('groups.index.create_flow.identity.edit_details') }}
                </UButton>
            </header>
            <GroupCreateArtworkStep :identity="draft.identity" :datacenter="draft.datacenter" :region="draft.region"
                :artwork="artwork" @continue="continueFromArtwork" />
        </template>
        <template v-else-if="draft.step === 'fit' || draft.step === 'focus'">
            <header class="flex items-start justify-between gap-4 text-left">
                <div class="min-w-0">
                    <h1 ref="heading" tabindex="-1" class="text-2xl font-semibold text-highlighted outline-none">
                        {{ t(`groups.index.create_flow.${draft.step}.title.${draft.groupType ?? 'community'}`) }}
                    </h1>
                    <p class="mt-2 text-sm text-muted">{{ t(`groups.index.create_flow.${draft.step}.subtitle`) }}</p>
                </div>
                <UButton color="neutral" variant="link" icon="i-lucide-arrow-left"
                    class="shrink-0 text-muted max-sm:max-w-28 max-sm:whitespace-normal max-sm:text-xs"
                    @click="showStep(draft.step === 'fit' ? 'artwork' : 'fit')">
                    {{ t(`groups.index.create_flow.${draft.step === 'fit' ? 'artwork.edit_images' : 'fit.edit_fit'}`) }}
                </UButton>
            </header>
            <GroupCreateFitStep v-if="draft.step === 'fit'" v-model="draft.fit" :lookups="discoveryLookups" @continue="submission.advance('focus')" />
            <GroupCreateFocusStep v-else v-model="draft.focus" :lookups="discoveryLookups" :popular-tags="popularTags"
                :reserved-tag-keys="reservedTagKeys" @continue="submission.advance('activity')" />
        </template>
        <template v-else-if="draft.step === 'activity'">
            <header class="flex items-start justify-between gap-4 text-left">
                <div class="min-w-0">
                    <h1 ref="heading" tabindex="-1" class="text-2xl font-semibold text-highlighted outline-none">
                        {{ t('groups.index.create_modal.sections.schedule.title') }}
                    </h1>
                    <p class="mt-2 text-sm text-muted">{{ t('groups.index.create_modal.sections.schedule.subtitle') }}</p>
                </div>
                <UButton color="neutral" variant="link" icon="i-lucide-arrow-left"
                    class="shrink-0 text-muted max-sm:max-w-28 max-sm:whitespace-normal max-sm:text-xs" @click="showStep('focus')">
                    {{ t('groups.index.create_flow.focus.edit_focus') }}
                </UButton>
            </header>
            <GroupCreateActivityWindowStep v-model="draft.activity" @continue="submission.advance('joining')" />
        </template>
        <template v-else-if="draft.step === 'joining'">
            <header class="flex items-start justify-between gap-4 text-left">
                <div class="min-w-0">
                    <h1 ref="heading" tabindex="-1" class="text-2xl font-semibold text-highlighted outline-none">
                        {{ t('groups.index.create_flow.joining.title') }}
                    </h1>
                    <p class="mt-2 text-sm text-muted">{{ t('groups.index.create_flow.joining.subtitle') }}</p>
                </div>
                <UButton color="neutral" variant="link" icon="i-lucide-arrow-left"
                    class="shrink-0 text-muted max-sm:max-w-28 max-sm:whitespace-normal max-sm:text-xs" @click="showStep('activity')">
                    {{ t('groups.index.create_flow.activity.edit_activity') }}
                </UButton>
            </header>
            <GroupCreateJoiningStep v-model="draft.joining" :group-name="draft.identity.name" :disabled="errorPreview" @submit="submitGroup" />
        </template>
    </div>
</template>
