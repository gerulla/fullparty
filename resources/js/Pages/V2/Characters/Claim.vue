<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3'
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import V2Layout from '@/Layouts/V2Layout.vue'
import CharacterCandidateRow from '@/components/Characters/CharacterCandidateRow.vue'
import CharacterClaimPreviewControls from '@/components/Characters/CharacterClaimPreviewControls.vue'
import CharacterClaimConflictPanel from '@/components/Characters/CharacterClaimConflictPanel.vue'
import CharacterTransferNotice from '@/components/Characters/CharacterTransferNotice.vue'
import CharacterTransferCompletionPanel from '@/components/Characters/CharacterTransferCompletionPanel.vue'
import { useCharacterTransferCompletion } from '@/composables/useCharacterTransferCompletion'
import { useCharacterClaim } from '@/composables/useCharacterClaim'
import { useAppearance } from '@/composables/useAppearance'
import type { CharacterClaimProps, CharacterClaimConflictStage } from '../../../Types/CharacterClaim'

defineOptions({ layout: V2Layout })
const props = defineProps<CharacterClaimProps>()
const { t } = useI18n()
const { isDark } = useAppearance()
const page = usePage()
const canPreview = computed(() => page.props.theme_preview_enabled === true)
const discordUrl = computed(() => page.props.site_links?.discord ?? null)
const { character, stage, context, transferred, code, expiry, busy, error, copied, copyError, profileUrl, charactersUrl, loadCode, copyCode, verify,
    previewScreen, previewActive, showPreview, previewClaim, transfer, finishTransfer } = useCharacterClaim(props, canPreview)
const transferForm = useCharacterTransferCompletion(transfer, previewActive, finishTransfer)
const { history: historyChoice, upcoming: upcomingChoice, busy: transferBusy, error: transferError } = transferForm
const conflictStage = computed(() => ['linked_elsewhere', 'claimed_elsewhere'].includes(stage.value)
    ? stage.value as CharacterClaimConflictStage : null)
const heading = ref<HTMLElement | null>(null)
watch(stage, async () => {
    if (previewActive.value) return
    await nextTick()
    heading.value?.focus({ preventScroll: true })
})
</script>

<template>
    <div class="mx-auto w-full max-w-3xl space-y-6 px-4 py-8 sm:px-6 sm:py-10">
        <Head :title="t('characters.overview.claim')" />
        <CharacterClaimPreviewControls v-if="canPreview" :screen="previewScreen"
            :disabled="(busy || transferBusy) && !previewActive" @preview="showPreview" />
        <UButton v-if="stage !== 'transfer'" :to="route('account.characters')" color="neutral" variant="link" icon="i-lucide-arrow-left" class="p-0 text-muted">
            {{ t('characters.overview.all_characters') }}
        </UButton>
        <header v-if="stage !== 'transfer'">
            <h1 ref="heading" tabindex="-1" class="text-2xl font-semibold text-highlighted outline-none">{{ t(`characters.claim_flow.${stage === 'complete' ? 'success_title' : 'title'}`) }}</h1>
            <p class="mt-2 text-sm leading-relaxed text-muted">{{ t(`characters.claim_flow.${conflictStage ? 'conflict_subtitle' : stage === 'complete' ? 'success_description' : 'subtitle'}`) }}</p>
        </header>
        <p v-if="previewActive" class="text-center text-xs text-muted" role="status">{{ t('characters.claim_flow.preview.active') }}</p>

        <section class="overflow-hidden rounded-xl border border-elevation-3 bg-elevation-1 dark:border-elevation-7 dark:bg-elevation-3" :aria-busy="busy">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-elevation-3 p-5 dark:border-elevation-7 sm:px-7">
                <CharacterCandidateRow :character="character" />
                <UBadge :color="character.status === 'claimed' ? 'brand' : character.status === 'verified' ? 'success' : 'error'" :variant="isDark ? 'outline' : 'solid'" class="rounded-md">
                    {{ t(`characters.verification_guide.states.${character.status}.title`) }}
                </UBadge>
            </div>

            <div v-if="stage === 'instructions'" class="space-y-6 p-5 sm:p-7">
                <UAlert v-if="context === 'transfer'" color="info" variant="soft" icon="i-lucide-user-lock"
                    :title="t('characters.claim_flow.transfer_title')"
                    :description="t(`characters.claim_flow.transfer_${character.status}`)" class="rounded-lg" />
                <UAlert v-if="error" color="error" variant="soft" icon="i-lucide-circle-alert" :description="error" class="rounded-lg" role="alert" />
                <ol class="space-y-6">
                    <li class="flex gap-3 sm:gap-4">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-700/10 text-sm font-semibold text-brand-700 dark:bg-brand-300/10 dark:text-brand-300" aria-hidden="true">1</span>
                        <div class="min-w-0 flex-1 space-y-3">
                            <h2 class="font-semibold text-highlighted">{{ t('characters.claim_flow.copy_title') }}</h2>
                            <div v-if="code" class="flex flex-wrap items-center gap-3 rounded-lg bg-elevation-2 p-3 dark:bg-elevation-5">
                                <code class="min-w-0 flex-1 select-all break-all font-mono text-sm font-semibold tracking-wide text-highlighted sm:text-base">{{ code }}</code>
                                <UButton color="neutral" variant="outline" :icon="copied ? 'i-lucide-check' : 'i-lucide-copy'" class="rounded-md" @click="copyCode">
                                    {{ t(`characters.claim_flow.${copied ? 'copied' : 'copy'}`) }}
                                </UButton>
                            </div>
                            <USkeleton v-else-if="busy" class="h-14 w-full rounded-lg" :aria-label="t('characters.claim_flow.preparing')" />
                            <UButton v-else color="brand" variant="outline" icon="i-lucide-refresh-cw" class="rounded-md" @click="loadCode">{{ t('characters.claim_flow.get_code') }}</UButton>
                            <p v-if="expiry" class="text-xs text-muted">{{ t('characters.claim_flow.expires', { date: expiry }) }}</p>
                            <p v-if="copyError" class="text-sm text-error" role="alert">{{ t('characters.claim_flow.copy_error') }}</p>
                        </div>
                    </li>
                    <li class="flex gap-3 sm:gap-4">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-700/10 text-sm font-semibold text-brand-700 dark:bg-brand-300/10 dark:text-brand-300" aria-hidden="true">2</span>
                        <div class="min-w-0 space-y-3">
                            <h2 class="font-semibold text-highlighted">{{ t('characters.claim_flow.profile_title') }}</h2>
                            <p class="text-sm leading-relaxed text-muted">{{ t('characters.claim_flow.profile_description') }}</p>
                            <UButton :href="previewActive ? undefined : profileUrl" :disabled="previewActive" target="_blank" rel="noopener noreferrer" color="neutral" variant="outline" trailing-icon="i-lucide-external-link" class="rounded-md">
                                {{ t('characters.claim_flow.open_profile') }}
                            </UButton>
                        </div>
                    </li>
                    <li class="flex gap-3 sm:gap-4">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-700/10 text-sm font-semibold text-brand-700 dark:bg-brand-300/10 dark:text-brand-300" aria-hidden="true">3</span>
                        <div class="min-w-0 space-y-3">
                            <h2 class="font-semibold text-highlighted">{{ t('characters.claim_flow.check_title') }}</h2>
                            <p class="text-sm leading-relaxed text-muted">{{ t('characters.claim_flow.check_description') }}</p>
                            <UButton color="brand" icon="i-lucide-shield-check" class="rounded-md" :disabled="!code || busy" @click="verify">{{ t('characters.claim_flow.verify') }}</UButton>
                        </div>
                    </li>
                </ol>
                <p class="rounded-lg bg-elevation-2 p-4 text-sm leading-relaxed text-muted dark:bg-elevation-4">{{ t('characters.claim_flow.help') }}</p>
            </div>

            <div v-else-if="stage === 'checking'" class="flex flex-col items-center px-6 py-16 text-center" role="status" aria-live="polite">
                <UIcon name="i-lucide-loader-circle" class="mb-5 size-10 animate-spin text-brand-700 motion-reduce:animate-none dark:text-brand-300" aria-hidden="true" />
                <h2 class="text-lg font-semibold text-highlighted">{{ t('characters.claim_flow.checking_title') }}</h2>
                <p class="mt-3 max-w-md text-sm leading-relaxed text-muted">{{ t('characters.claim_flow.checking_description') }}</p>
            </div>

            <CharacterClaimConflictPanel v-else-if="conflictStage" :stage="conflictStage"
                :discord-url="discordUrl" @claim="previewClaim" />

            <CharacterTransferCompletionPanel v-else-if="stage === 'transfer' && transfer" :summary="transfer"
                v-model:history="historyChoice" v-model:upcoming="upcomingChoice"
                :busy="transferBusy" :error="transferError" @complete="transferForm.complete" />

            <div v-else-if="stage === 'complete'" class="flex flex-col items-center gap-5 px-6 py-10 text-center" aria-live="polite">
                <UIcon name="i-lucide-shield-check" class="size-12 text-brand-700 dark:text-brand-300" aria-hidden="true" />
                <CharacterTransferNotice v-if="transferred" />
                <p class="max-w-md text-sm leading-relaxed text-muted">{{ t('characters.claim_flow.remove_code') }}</p>
                <UButton :to="charactersUrl" color="brand" trailing-icon="i-lucide-arrow-right" class="rounded-md">{{ t('characters.onboarding.my_characters') }}</UButton>
            </div>
        </section>
    </div>
</template>
