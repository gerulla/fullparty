<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { Head, usePage, useRemember } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import V2Layout from '@/Layouts/V2Layout.vue'
import GroupCreatePreviewControls from '@/components/Groups/GroupCreatePreviewControls.vue'
import GroupCreateTypePicker from '@/components/Groups/GroupCreateTypePicker.vue'
import GroupCreateDatacenterPicker from '@/components/Groups/GroupCreateDatacenterPicker.vue'
import GroupCreateIdentityForm from '@/components/Groups/GroupCreateIdentityForm.vue'
import { datacenterRegionForTimezone } from '@/utils/datacenterRegion'
import type { DatacenterLookup, DatacenterRegion, GroupCreateIdentity, GroupType } from '@/Types/Groups'

defineOptions({ layout: V2Layout })

const { t } = useI18n()
const props = defineProps<{ datacenterWorlds: Record<string, string[]> }>()
const page = usePage<{ lookups: { datacenters: DatacenterLookup[] } }>()
const canPreview = computed(() => page.props.theme_preview_enabled === true)
const heading = ref<HTMLElement | null>(null)
const datacenters = computed(() => page.props.lookups.datacenters.map(dc => ({
    ...dc,
    worlds: props.datacenterWorlds[dc.value] ?? [],
})))
const draft = useRemember(reactive({
    groupType: null as GroupType | null,
    step: 'type' as 'type' | 'datacenter' | 'details' | 'next',
    region: null as DatacenterRegion | null,
    datacenter: null as string | null,
    identity: { name: '', slug: '', description: '' } as GroupCreateIdentity,
}), 'groups.create')

// Keep remembered drafts from before the identity step usable.
draft.identity ??= { name: '', slug: '', description: '' }

onMounted(() => {
    if (!draft.region) {
        draft.region = datacenterRegionForTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone) ?? 'EU'
    }
})

async function chooseType(type: GroupType) {
    draft.groupType = type
    await showStep('datacenter')
}

async function showStep(step: typeof draft.step) {
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
    await showStep('details')
}

async function changeType() {
    await showStep('type')
}

async function continueFromIdentity(identity: GroupCreateIdentity) {
    draft.identity = identity
    await showStep('next')
}
</script>

<template>
    <div class="mx-auto w-full max-w-4xl space-y-6 py-6 sm:px-6 sm:py-10">
        <Head :title="t('groups.index.create_modal.title')" />
        <GroupCreatePreviewControls v-if="canPreview" />
        <template v-if="draft.step === 'type'">
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
            <GroupCreateIdentityForm v-model="draft.identity" @continue="continueFromIdentity" />
        </template>
        <header v-else class="flex flex-wrap items-center justify-between gap-3">
            <h1 ref="heading" tabindex="-1" class="text-xl font-semibold text-highlighted outline-none">
                {{ draft.identity.name }}
            </h1>
            <p class="text-sm text-muted">{{ draft.datacenter }}</p>
            <UButton color="neutral" variant="ghost" icon="i-lucide-arrow-left" class="rounded-md" @click="showStep('details')">
                {{ t('groups.index.create_flow.identity.edit_details') }}
            </UButton>
        </header>
    </div>
</template>
