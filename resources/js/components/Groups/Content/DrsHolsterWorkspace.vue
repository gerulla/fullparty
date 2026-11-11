<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { BozjaHolsterSummary, DrsWorkspaceData } from '@/Types/Bozja'
import { useDrsHolsterWorkspace } from '@/composables/useDrsHolsterWorkspace'
import DrsHolsterLibrary from './DrsHolsterLibrary.vue'
import DrsHolsterLoadout from './DrsHolsterLoadout.vue'
import DrsHolsterDetails from './DrsHolsterDetails.vue'
import DrsHolsterActionPicker from './DrsHolsterActionPicker.vue'
import ContentEditorSwitcher from './ContentEditorSwitcher.vue'

const props = defineProps<{ groupSlug: string; initialData: DrsWorkspaceData }>()
const { t, locale } = useI18n()
const k = 'groups.index.content.delubrum_reginae_savage.holsters.'
const workspace = useDrsHolsterWorkspace(props.groupSlug, props.initialData)
const isDesktop = useBreakpoints(breakpointsTailwind).greaterOrEqual('xl')
const mobileEditorOpen = ref(false)
const editorVisible = computed(() => isDesktop.value || mobileEditorOpen.value)
const pickerOpen = ref(false)
const workspaceHeading = ref<HTMLElement | null>(null)
const sidePanel = ref<HTMLElement | null>(null)

async function revealEditor() {
    mobileEditorOpen.value = true
    await nextTick()
    if (!isDesktop.value) {
        workspaceHeading.value?.focus({ preventScroll: true })
        workspaceHeading.value?.scrollIntoView({ block: 'start' })
    }
}
async function selectHolster(holster: BozjaHolsterSummary) {
    if (await workspace.select(holster)) await revealEditor()
}
async function createHolster(parent?: BozjaHolsterSummary) {
    if (await workspace.create(parent)) await revealEditor()
}
async function showLibrary() {
    if (!await workspace.leaveEditor()) return
    mobileEditorOpen.value = false
    pickerOpen.value = false
    await nextTick()
    workspaceHeading.value?.focus({ preventScroll: true })
    workspaceHeading.value?.scrollIntoView({ block: 'start' })
}
async function showActions() {
    pickerOpen.value = true
    await nextTick()
    sidePanel.value?.focus({ preventScroll: true })
    if (!isDesktop.value && sidePanel.value) {
        const bounds = sidePanel.value.getBoundingClientRect()
        if (bounds.top < 80 || bounds.bottom > window.innerHeight) {
            sidePanel.value.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' })
        }
    }
}
</script>

<template>
    <div class="dark:[--ui-primary:var(--color-brand-400)]">
        <header ref="workspaceHeading" tabindex="-1" class="scroll-mt-20 outline-none mb-5 flex flex-wrap items-center justify-between gap-3">
            <ContentEditorSwitcher :group-slug="groupSlug" content="drs" class="md:hidden" />
            <h1 class="hidden text-2xl font-bold md:block">{{ t('groups.index.content.delubrum_reginae_savage.title') }}</h1>
            <UButton v-if="!isDesktop && mobileEditorOpen" color="neutral" variant="ghost" icon="i-lucide-arrow-left" :label="t(k + 'workspace.library')" class="ml-auto" @click="showLibrary" />
            <UButton v-else :as="Link" :href="route('groups.dashboard.content.index', { group: groupSlug, locale })" color="neutral" variant="ghost" icon="i-lucide-chevron-left" :label="t(k + 'workspace.change_content')" class="hidden md:inline-flex" />
        </header>
        <UAlert v-if="Object.keys(workspace.errors).length" color="error" variant="soft" icon="i-lucide-circle-alert" :title="t('general.error')" :description="Object.values(workspace.errors).join(' ')" class="mb-4" />
        <div class="grid items-start gap-4 xl:grid-cols-[16rem_minmax(0,1fr)_18rem] 2xl:grid-cols-[18rem_minmax(0,1fr)_19rem]">
            <DrsHolsterLibrary v-show="isDesktop || !mobileEditorOpen" :holsters="workspace.records" :selected-id="workspace.selectedId" :busy="workspace.busy" @select="selectHolster" @create="createHolster()" @toggle="workspace.toggle" />
            <template v-if="editorVisible">
                <DrsHolsterLoadout :key="workspace.selectedId ?? 'new'" v-model="workspace.draft" :selected="workspace.selected" :capacity="workspace.capacity" :maximum="workspace.maximum" :busy="workspace.busy" :errors="workspace.errors" @toggle="workspace.selected && workspace.toggle(workspace.selected)" @default="workspace.selected && workspace.makeDefault(workspace.selected)" @duplicate="workspace.selected && workspace.duplicate(workspace.selected)" @delete="workspace.selected && workspace.remove(workspace.selected)" @save="workspace.save" @add-action="showActions">
                    <section v-if="workspace.selected && workspace.draft.type === 'prepop'" class="mt-6 border-t border-default pt-5">
                        <div class="mb-4 flex items-center justify-between gap-3"><div><h3 class="flex items-center gap-2 font-semibold"><UIcon name="i-lucide-link" class="size-5" />{{ t(k + 'workspace.linked_refills') }} ({{ workspace.refills.length }})</h3><p class="mt-1 text-xs text-muted">{{ t(k + 'workspace.refills_hint') }}</p></div><UButton icon="i-lucide-plus" color="neutral" variant="outline" size="xs" :label="t(k + 'workspace.add_refill')" :disabled="workspace.busy" @click="createHolster(workspace.selected)" /></div>
                        <div class="divide-y divide-default overflow-hidden rounded-lg border border-default">
                            <div v-for="refill in workspace.refills" :key="refill.id" class="flex items-center gap-3 bg-elevation-2 px-3 dark:bg-elevation-4">
                                <button type="button" class="flex min-w-0 flex-1 items-center gap-3 py-3 text-left" :disabled="workspace.busy" @click="selectHolster(refill)"><UIcon name="i-lucide-copy" class="size-5 shrink-0 text-muted" /><span class="min-w-0"><span class="block truncate text-sm font-medium">{{ refill.display_name || t(k + 'untitled') }}</span><span class="text-xs text-muted">{{ t(k + 'types.refill') }} · {{ t(k + 'item_count', { count: refill.items.length }) }}</span></span></button>
                                <USwitch size="sm" :model-value="refill.is_active" :disabled="workspace.busy" :aria-label="t(k + 'active') + ': ' + refill.display_name" @update:model-value="workspace.toggle(refill)" />
                                <UButton icon="i-lucide-chevron-right" color="neutral" variant="ghost" :aria-label="t(k + 'open_editor')" :disabled="workspace.busy" @click="selectHolster(refill)" />
                            </div>
                            <p v-if="!workspace.refills.length" class="p-4 text-sm text-muted">{{ t(k + 'workspace.no_refills') }}</p>
                        </div>
                    </section>
                </DrsHolsterLoadout>
                <div ref="sidePanel" tabindex="-1" class="min-w-0 scroll-mt-20 outline-none xl:sticky xl:top-20">
                    <DrsHolsterActionPicker v-if="pickerOpen" v-model="workspace.draft" :items="initialData.bozja_items" :capacity="workspace.capacity" :maximum="workspace.maximum" :busy="workspace.busy" @back="pickerOpen = false" />
                    <DrsHolsterDetails v-else v-model="workspace.draft" :holsters="workspace.records" :selected-id="workspace.selectedId" :errors="workspace.errors" :busy="workspace.busy" :exceeded="workspace.capacity > workspace.maximum" :dirty="workspace.dirty" @save="workspace.save" />
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
@reference '../../../../css/app.css';
:deep(.drs-panel) { @apply rounded-lg border border-default bg-elevation-1 shadow-sm dark:bg-elevation-2 dark:shadow-none; }
</style>
