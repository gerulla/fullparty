<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import V2Layout from '@/Layouts/V2Layout.vue'
import { computed, nextTick, ref, watch } from 'vue'
import { route } from 'ziggy-js'
import { useCharacterOnboarding } from '@/composables/useCharacterOnboarding'
import CharacterAddMethodPicker from '@/components/Characters/CharacterAddMethodPicker.vue'
import CharacterCandidateRow from '@/components/Characters/CharacterCandidateRow.vue'
import CharacterOnboardingPreviewControls from '@/components/Characters/CharacterOnboardingPreviewControls.vue'
import CharacterImportProgressPanel from '@/components/Characters/CharacterImportProgressPanel.vue'
import CharacterImportCompletePanel from '@/components/Characters/CharacterImportCompletePanel.vue'
import CharacterTransferCompletionPanel from '@/components/Characters/CharacterTransferCompletionPanel.vue'
import { useCharacterTransferCompletion } from '@/composables/useCharacterTransferCompletion'
import { characterImportIllustrations } from '@/utils/characterImportArtwork'
import type { CharacterCreateProps } from '../../../Types/CharacterOnboarding'

defineOptions({ layout: V2Layout })

const { t } = useI18n()
const props = defineProps<CharacterCreateProps>()
const page = usePage()
const canPreview = computed(() => page.props.theme_preview_enabled === true)
const illustrationPreloads = Object.values(characterImportIllustrations)
const { step, method, candidates, selected, added, name, world, busy, searched, error, reconnect,
    refreshFailed, partial, transferred, linkUrl, chooseMethod, loadCandidates, select, back, confirm, retryRefresh,
    previewScreen, previewActive, showPreview, transfer, finishTransfer, importProgress } = useCharacterOnboarding(props, canPreview)
const transferForm = useCharacterTransferCompletion(transfer, previewActive, finishTransfer)
const { history: historyChoice, upcoming: upcomingChoice, busy: transferBusy, error: transferError } = transferForm
const content = ref<HTMLElement | null>(null)
const isMobileOrTablet = useBreakpoints(breakpointsTailwind).smaller('xl')
const collapseLodestoneResults = computed(() => method.value === 'lodestone' && isMobileOrTablet.value)
const visibleCandidates = computed(() => collapseLodestoneResults.value && selected.value
    ? candidates.value.filter(candidate => candidate.lodestone_id === selected.value?.lodestone_id)
    : candidates.value)

// Combobox items cannot use the empty value reserved for clearing the selection.
const allWorldsValue = '__all_worlds__'
const worldOptions = computed(() => [
    [{ label: t('characters.onboarding.all_worlds'), value: allWorldsValue }],
    ...props.worldGroups.map(group => [
        ...(group.datacenter ? [{ type: 'label' as const, label: group.datacenter }] : []),
        ...group.worlds,
    ]),
])
const selectedWorld = computed({
    get: () => world.value || allWorldsValue,
    set: (value: string | undefined) => { world.value = value === allWorldsValue ? '' : (value ?? '') },
})
watch(step, async () => {
    if (previewActive.value) return
    await nextTick()
    content.value?.querySelector<HTMLElement>('h1')?.focus({ preventScroll: true })
})
</script>

<template>
    <div ref="content" class="mx-auto w-full max-w-4xl space-y-6 px-4 py-8 sm:px-6 sm:py-10">
        <Head :title="t('characters.add.title')">
            <link v-for="src in illustrationPreloads" :key="src" rel="preload" as="image" :href="src" />
        </Head>
        <CharacterOnboardingPreviewControls v-if="canPreview" :screen="previewScreen" :method="method"
            :disabled="(busy || transferBusy) && !previewActive" @preview="showPreview" />
        <UButton v-if="!['loading', 'transfer'].includes(step)" :to="route('account.characters')" color="neutral" variant="link" icon="i-lucide-arrow-left" class="p-0 text-muted">
            {{ t('characters.overview.all_characters') }}
        </UButton>
        <header v-if="step === 'method'" class="text-center">
            <h1 tabindex="-1" class="text-2xl font-semibold text-highlighted outline-none">{{ t('characters.add.title') }}</h1>
            <p class="mt-2 text-sm text-muted">{{ t('characters.onboarding.subtitle') }}</p>
        </header>
        <p v-if="previewActive" class="text-center text-xs text-muted" role="status">{{ t('characters.onboarding.preview.active') }}</p>
        <UAlert v-if="error" color="error" variant="soft" icon="i-lucide-circle-alert" :description="error" class="rounded-lg" role="alert" />
        <UButton v-if="reconnect" :href="previewActive ? undefined : linkUrl" :disabled="previewActive"
            color="brand" variant="link" class="p-0 underline">{{ t('characters.onboarding.reconnect') }}</UButton>

        <CharacterAddMethodPicker v-if="step === 'method'" :linked="xivAuthLinked" @choose="chooseMethod" />

        <section v-else class="rounded-xl border border-elevation-3 bg-elevation-1 p-5 dark:border-elevation-7 dark:bg-elevation-3 sm:p-8" :aria-busy="busy">
            <template v-if="step === 'select'">
                <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 tabindex="-1" class="text-lg font-semibold text-highlighted outline-none">{{ t(`characters.onboarding.select_${method}`) }}</h1>
                        <p class="mt-1 text-sm text-muted">{{ t(`characters.onboarding.select_${method}_help`) }}</p>
                    </div>
                    <UButton color="neutral" variant="ghost" icon="i-lucide-arrow-left" class="rounded-md" :disabled="busy" @click="back">{{ t('characters.onboarding.change_method') }}</UButton>
                </div>

                <form v-if="method === 'lodestone'" class="mb-6 flex flex-col items-stretch gap-3 sm:flex-row sm:items-end" @submit.prevent="loadCandidates">
                    <UFormField :label="t('characters.onboarding.character_name')" class="min-w-0 flex-1">
                        <UInput v-model="name" :placeholder="t('characters.onboarding.name_placeholder')" icon="i-lucide-search" class="w-full" :ui="{ base: 'rounded-md' }" :disabled="busy" :minlength="2" :maxlength="100" required />
                    </UFormField>
                    <UFormField :label="t('characters.onboarding.world')" class="sm:w-44">
                        <USelectMenu v-model="selectedWorld" :items="worldOptions" value-key="value" :disabled="busy" class="w-full rounded-md" :aria-label="t('characters.onboarding.world')" />
                    </UFormField>
                    <UButton type="submit" color="brand" class="justify-center rounded-md" :loading="busy" :disabled="name.trim().length < 2">{{ t('characters.onboarding.search') }}</UButton>
                </form>

                <div v-if="busy" class="space-y-3" role="status" :aria-label="t('characters.onboarding.finding')">
                    <div v-for="n in 3" :key="n" class="flex items-center gap-4 rounded-lg bg-elevation-2 p-4 dark:bg-elevation-4">
                        <USkeleton class="size-12 rounded-full" /><div class="flex-1 space-y-2"><USkeleton class="h-4 w-40" /><USkeleton class="h-3 w-24" /></div>
                    </div>
                </div>
                <div v-else-if="candidates.length" class="space-y-3">
                    <div class="flex flex-col gap-3" :class="{ 'lodestone-results': method === 'lodestone' }">
                        <button
                            v-for="candidate in visibleCandidates"
                            :key="candidate.lodestone_id"
                            type="button"
                            class="flex w-full shrink-0 items-center justify-between gap-4 rounded-lg border p-4 transition-[background-color,border-color,scale] duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand motion-reduce:transition-none"
                            :class="[
                                { 'min-h-22': method === 'lodestone' },
                                selected?.lodestone_id === candidate.lodestone_id
                                    ? 'border-brand bg-brand/5 motion-safe:scale-[1.02]'
                                    : 'border-elevation-3 hover:bg-elevation-2 dark:border-elevation-6 dark:hover:bg-elevation-4',
                            ]"
                            :aria-pressed="selected?.lodestone_id === candidate.lodestone_id"
                            @click="select(candidate, collapseLodestoneResults)"
                        >
                            <CharacterCandidateRow :character="candidate" />
                            <UIcon
                                :name="selected?.lodestone_id === candidate.lodestone_id ? 'i-lucide-circle-check' : 'i-lucide-circle'"
                                class="size-5 shrink-0"
                                :class="selected?.lodestone_id === candidate.lodestone_id ? 'text-brand' : 'text-muted'"
                                aria-hidden="true"
                            />
                        </button>
                    </div>
                    <p v-if="method === 'lodestone' && visibleCandidates.length === 20" class="text-sm text-muted">{{ t('characters.onboarding.refine') }}</p>
                </div>
                <div v-else-if="searched" class="py-8 text-center" role="status">
                    <UIcon name="i-lucide-search-x" class="mb-3 size-8 text-muted" aria-hidden="true" />
                    <p class="font-medium">{{ t(`characters.onboarding.empty_${method}`) }}</p>
                    <p class="mx-auto mt-2 max-w-md text-sm text-muted">{{ t(`characters.onboarding.empty_${method}_help`) }}</p>
                </div>
                <div v-if="!busy && (method === 'xivauth' || selected)" class="mt-6 flex flex-wrap items-center justify-between gap-3">
                    <UButton v-if="method === 'xivauth'" color="neutral" variant="outline" icon="i-lucide-refresh-cw" class="w-full justify-center rounded-md md:w-auto" @click="loadCandidates">{{ t('characters.onboarding.reload') }}</UButton>
                    <p v-else class="max-w-sm text-sm text-muted">{{ t('characters.onboarding.confirm_lodestone') }}</p>
                    <UButton v-if="selected" color="brand" icon="i-lucide-plus" class="ml-auto w-full shrink-0 justify-center rounded-md md:w-auto" @click="confirm">{{ t('characters.onboarding.confirm_button') }}</UButton>
                </div>
            </template>

            <CharacterImportProgressPanel v-else-if="step === 'loading'" :character="selected" :progress="importProgress" />

            <CharacterTransferCompletionPanel v-else-if="step === 'transfer' && transfer" :summary="transfer"
                :automatic-transfer="method === 'xivauth'"
                v-model:history="historyChoice" v-model:upcoming="upcomingChoice"
                :busy="transferBusy" :error="transferError" @complete="transferForm.complete" />

            <CharacterImportCompletePanel v-else-if="step === 'complete' && added" :character="added" :method="method"
                :preview="previewActive" :refresh-failed="refreshFailed" :partial="partial" :transferred="transferred" @retry="retryRefresh" />
        </section>
    </div>
</template>

<style scoped>
.lodestone-results {
    /* Five and a half 5.5rem rows, five 0.75rem gaps, and the top focus padding. */
    max-height: 34.5rem;
    margin-inline: -0.5rem;
    padding: 0.5rem;
    overflow-y: auto;
    overscroll-behavior-y: contain;
}
</style>
