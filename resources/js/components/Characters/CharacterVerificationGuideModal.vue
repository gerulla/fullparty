<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{
    close: [value: boolean]
    'after:leave': []
    restoreFocus: []
}>()
const { t } = useI18n()

const states = [
    { key: 'unverified', icon: 'i-lucide-user-round', color: 'text-muted' },
    { key: 'verified', icon: 'i-lucide-circle-check', color: 'text-success' },
    { key: 'claimed', icon: 'i-lucide-shield-check', color: 'text-brand-700 dark:text-brand' },
] as const
const qualifyingRules = ['attendance', 'independent', 'timing', 'same_group'] as const
const claimSteps = ['open_characters', 'choose_claim', 'follow_instructions'] as const

function restoreFocus(event: Event) {
    event.preventDefault()
    emit('restoreFocus')
}
</script>

<template>
    <UModal v-model:open="open"
        :title="t('characters.verification_guide.title')"
        :description="t('characters.verification_guide.description')"
        :content="{ onCloseAutoFocus: restoreFocus }"
        :close="{ class: 'rounded-md hover:bg-elevation-3 dark:hover:bg-elevation-5' }"
        :ui="{
            content: 'max-w-2xl rounded-xl bg-elevation-1 dark:bg-elevation-3 ring-default',
            header: 'border-default pr-12',
            title: 'text-lg font-semibold text-default',
            description: 'mt-1 text-sm text-muted',
            body: 'space-y-5',
            footer: 'justify-end gap-2 border-default',
        }"
        @after:leave="emit('after:leave')">
        <template #body>
            <div class="space-y-3">
                <section v-for="state in states" :key="state.key"
                    class="flex gap-3 rounded-lg bg-elevation-2 p-4 dark:bg-elevation-4">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-elevation-1 dark:bg-elevation-3">
                        <UIcon :name="state.icon" :class="state.color" class="size-5" aria-hidden="true" />
                    </div>
                    <div class="min-w-0 flex-1 space-y-1">
                        <h3 class="font-semibold text-default">{{ t(`characters.verification_guide.states.${state.key}.title`) }}</h3>
                        <p class="text-sm font-medium text-default">{{ t(`characters.verification_guide.states.${state.key}.summary`) }}</p>
                        <p class="text-sm leading-relaxed text-muted">{{ t(`characters.verification_guide.states.${state.key}.description`) }}</p>
                        <UAccordion v-if="state.key === 'verified'"
                            :items="[{ label: t('characters.verification_guide.qualifying.title'), value: 'qualifying' }]"
                            :ui="{
                                item: 'border-0',
                                trigger: 'rounded-md py-2 text-sm text-default hover:text-brand-700 dark:hover:text-brand focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand',
                                body: 'pb-1',
                            }">
                            <template #body>
                                <ul class="list-disc space-y-2 pl-5 text-sm leading-relaxed text-muted marker:text-default">
                                    <li v-for="rule in qualifyingRules" :key="rule">{{ t(`characters.verification_guide.qualifying.${rule}`) }}</li>
                                </ul>
                            </template>
                        </UAccordion>
                    </div>
                </section>
            </div>

            <section class="rounded-lg border border-default p-4">
                <h3 class="mb-3 font-semibold text-default">{{ t('characters.verification_guide.claim.title') }}</h3>
                <ol class="space-y-3">
                    <li v-for="(step, index) in claimSteps" :key="step" class="flex items-start gap-3 text-sm leading-relaxed">
                        <span aria-hidden="true" class="flex size-6 shrink-0 items-center justify-center rounded-full bg-elevation-3 text-xs font-semibold text-default dark:bg-elevation-5">{{ index + 1 }}</span>
                        <i18n-t :keypath="`characters.verification_guide.claim.${step}`" tag="p" class="min-w-0 text-default">
                            <template #page><strong>{{ t('characters.title') }}</strong></template>
                            <template #claim><strong>{{ t('characters.verification_guide.claim.button') }}</strong></template>
                        </i18n-t>
                    </li>
                </ol>
                <p class="mt-4 text-sm leading-relaxed text-muted">{{ t('characters.verification_guide.claim.direct') }}</p>
            </section>
        </template>
        <template #footer>
            <UButton color="neutral" variant="ghost" class="rounded-md" @click="emit('close', false)">
                {{ t('general.close') }}
            </UButton>
            <UButton :to="route('account.characters')" color="brand" icon="i-lucide-users-round" class="rounded-md"
                @click="emit('close', true)">
                {{ t('characters.title') }}
            </UButton>
        </template>
    </UModal>
</template>
